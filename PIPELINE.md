# Performance processing

## Internationalization

WordPress owns translation discovery, locale selection and language-pack loading.
Plugin gettext calls use the literal `wp-optimizer` domain, matching the plugin
header and WordPress.org slug. The first lookup uses the installed pack in
`WP_LANG_DIR/plugins/wp-optimizer-<locale>.mo` through WordPress's native
just-in-time loader. An unavailable pack leaves source text visible. Translations
are managed through translate.wordpress.org.

## Error tracking

The WPS error handler checks PHP's active `error_reporting()` mask before callbacks,
notification bookkeeping, mail/log output or forwarding to an earlier handler.
Warnings suppressed with `@`, including concurrent cache-file deletion failures,
do not enter tracking. Reportable errors retain the component filter, notification
deduplication and handler chain.

## Storage publication

Immediate writes, shutdown autosave and Cache's disk fallback all publish through
WPS `Storage::save()`. It serializes the existing record format and exclusively
creates a uniquely named `.wps-*.tmp` file in the entry's directory. Only a complete
write followed by a successful flush and close is eligible for publication.

Publication, single-entry deletion, request-only replacement cleanup, age/group
purges and group detachment share one filesystem mutation lock per storage namespace.
The persistent lock lives under `WP_CONTENT_DIR/.wps-storage-locks`, outside groups
that can be deleted or detached, and is never unlinked by those operations.
Serialization and staging happen before acquiring the lock; the final existence
check and rename happen together under it. Exactly one competing unforced save can
publish an absent entry. Forced publications can replace one another with complete
records. Purges inspect file ages while publication is excluded. WP Optimizer's
trash detachment and recovery cache purge use these Storage operations.

Ordinary reads consume complete published snapshots without a lock. A missing or
failed read is a cache miss. Expired/invalid snapshot cleanup compares the current
file bytes to the bytes actually read while holding the mutation lock before unlinking.
A decoded valid snapshot can satisfy its in-flight read, but memory hydration occurs
under the same lock only if that snapshot is still current and unexpired. This
prevents stale readers from deleting or caching replacements, including after group
detachment and recreation. Cache's remaining-TTL check returns a miss at expiry;
Storage owns the conditional filesystem cleanup. Selected-blog deletion and
detachment invalidate the corresponding in-memory group.

Open, short-write, flush, close or rename failures return false; the prior published
file remains intact. Each save closes its handle and removes any remaining staging
file on normal return or exception. Exceptions retain their existing propagation.
Abrupt process termination can leave a staging file for the ordinary group/age
purges. A purge can remove an in-progress staging file; its later publication then
fails. A write staged after that purge can publish a new entry. Lock setup or
acquisition failure raises a contextual exception and mutation is not attempted.
Existing cache entries, lifetimes and paths retain their format and policy.

## Object cache

The drop-in opts into durable zero expiry when constructing WPS Cache. Default
WordPress `set`, `add`, `replace` and bulk writes persist without a deadline in
the remote driver, or disk when the driver is absent or its write fails.
Replacing a timed entry with zero expiry
clears its TTL. Nonpersistent groups remain request-local. The same explicit
policy reaches Storage; ordinary WPS Cache/Storage instances retain their
request-local zero-expiry default.

WPS `add()` checks request memory before the remote driver and disk, stopping
on the first existing key. Successful remote `set()` and `replace()` writes
remove previous fallback files without reading or serializing their contents.
Failed remote writes retain the request-memory value and persist it to disk
when fallback is enabled. A remote-only entry is rebuilt on a miss during an
outage; it has no disk mirror. Direct Storage caches keep their disk records.

The object-cache drop-in delegates bulk reads to WPS `Cache::get_multiple()`.
Cache applies its context/blog group prefix once and serves request-memory hits.
It sends the remaining distinct keys in one Redis `MGET` or Memcached `getMulti`
call. Results remain keyed in input order, including `false`, `null`, zero and
empty values; misses use the caller's default. Remaining misses follow the same
Storage lookup and remote warming path as single reads. Hits/misses, object
cloning, expiry and nonpersistent/global-group rules are shared across both paths.
Successful remote warming preserves the remaining TTL and removes the fallback
file; failed warming leaves that file available for another request.
The existing runtime suspension guard returns misses before accessing the cache.
Single reads obtain both the value and `$found` from `Cache::get()` in the same
lookup. Cached false/null values report hits; misses report false without a
separate existence check or duplicate Storage read.

Forced driver writes skip existence reads. Redis uses conditional `SET NX` for
adds and `SET XX` for replacements inside the same script that updates its group
index. The index tracks data expiry using the Redis clock, prunes up to 128 expired
names per operation, and expires when its last expiring member does. Permanent
members keep the index persistent. Existing group sets migrate up to 128 members
per maintenance operation; missing legacy data is discarded. Group inspection
and flushing understand both formats.

Redis group flush reads the initial member count and removes at most that many
names in batches of 128. Each batch deletes data and membership in one server-side
operation; it does not return the full index to PHP. Concurrent writes do not
extend the flush indefinitely. Backend failures retain the cache's existing
Storage fallback and return conventions. See the framework's
[cache ownership](../../themes/flex-and-go/core/vendors/wps-framework/ARCHITECTURE.md).

## Database query cache

The database drop-in applies the configured request and query exclusions, derives
the existing query key, and reads the `cache/db` WPS Storage group. Only `false`
means a miss; zero, an empty string, an empty array and `null` are cached results.
A miss executes SQL and stores its result according to the lifetime and timing
threshold. Hits return the stored value and record a hit metric.

## WP_Query dependencies

`QueryCacheTypes` owns the query-type catalog and normalization. Configuration
preserves input order; runtime selection uses catalog order. Explicit empty
selections stay empty and invalid nonempty selections use the full catalog.
The lightweight owner can also be loaded by configuration screens independently
of the query-cache engine.

Query-cache creation collects unique valid post IDs, types and authors, grouping
the posts by their registered taxonomies. Each taxonomy receives one term-ID
lookup for its applicable posts. Empty or failed taxonomy lookups contribute no
term dependency. Cache hits restore posts without rebuilding dependency metadata.

`StaticCacheRules` replaces the entry's index rows with one metadata row plus
normalized, deduplicated dependency rows. Shared fields and timestamps are built
once; prepared multi-row inserts publish at most 250 rows per statement. Both
static-page and query caches use this index for their existing invalidation rules.

Targeted invalidation selects at most 250 distinct keys at a time, deletes their
storage entries and removes all their dependency rows in one prepared SQL delete
per batch. The namespace and each exact key/hash pair constrain the delete. SQL
delete failures surface an error and stop the purge.

Expiry flushes first apply Storage's file-age cleanup. Static-page and query
caches then read age-eligible metadata rows in batches of 250, advancing through
the key-hash index. Only absent files lose their SQL rows; surviving files and
recent writes awaiting shutdown autosave retain their metadata. Deletes recheck
the timestamp cutoff to preserve concurrent rewrites. An explicit blog ID selects
both that site's storage path and index table. Database-query storage has no
dependency-index reconciliation.

## Used CSS cleanup

The worker prepares its exact static-selector set and literal keep patterns once
per job and shares them across source stylesheets and nested CSS blocks. The
public reducer prepares the same inputs once per call. Literal pattern matching
uses WPS `StringHelper::contains_any()` with the prepared strings.

Successful Used CSS workers stream generated-directory entries without building
or sorting a full file list. Each build removes at most 100 regular CSS files
older than 30 days. Recent files do not consume the deletion quota, so later
expired files remain reachable and repeated builds progress through any backlog.
Directories, symbolic links and other file extensions are retained. See
[minification](#minification) for related background asset processing.

## Component profiling and history

With component profiling enabled, an `all` action inspects the hook about to run.
Only changed callback arrays are rewritten with profiling pairs; unchanged hooks
reuse their copy-on-write snapshots. Recursively active hooks are left intact
during dispatch. Callback ordering, arguments and filter values are preserved.

History retention checkpoints count actual rows and delete excess oldest rows
using a bounded ascending-ID delete. Underfull tables keep an hourly transient
describing available headroom and the latest ID. Full checkpoints also retain
the oldest 128 row IDs (or the table limit if smaller). Subsequent single inserts
count at most 129 recent primary-key entries and delete through the corresponding
oldest-ID cutoff. The cutoff makes repeated/concurrent deletes idempotent and
ID gaps do not count as rows. These inserts retain the exact cap without a full
count or rewriting the checkpoint. Exhausted windows, changed limits, reset IDs
and multi-row SQL batches require a fresh checkpoint. Age cleanup refreshes it;
history reset clears it. Failed counts or deletes do not publish a new checkpoint.

Cumulative cache metrics read their shared WPS option once, merge the request
counters, and write one `INSERT ... ON DUPLICATE KEY UPDATE` through
`Options::upsert()`. No comparison read or `REPLACE` is needed. Empty metric
requests do not access this option; the existing reset and report formats remain.

## Static cache hits

PHP-served hits emit the cached status, headers and body, then complete the
response with FastCGI or LiteSpeed's finish API before shutdown maintenance.
Other SAPIs flush output; they cannot guarantee detachment from the connection.
Shutdown persists one exact rule-hit increment and repairs the direct index.
Index records compare their generated contents while ignoring the created
timestamp, so matching files remain unchanged. Missing, changed or invalid
records are published; comparisons read data without executing the index file.
Cache stores still publish the index during generation.

## Minification

The Minify module processes HTML after PageSpeed in the shared HTML output buffer.
HTML and configured inline minification run in that callback. Eligible external
CSS/JS tags use ready generated files from `WPOPT_STORAGE/minify/{css,js}` with
the existing URL-derived filenames. Missing files enqueue one
`wpopt_minify_asset_build($original_url, $type)` single event for ten seconds later;
the response retains the original asset URL. This stage does not read or minify
asset contents or write generated files.

WordPress Cron invokes the module's worker, which checks current settings and
asset exclusions and resolves the source through `FrontendAssets::local()`. Only
local files with the matching CSS/JS extension are accepted. The worker obtains
a nonblocking per-output file lock, rechecks for ready output, then reads and
minifies the source. CSS retains its original directory for relative URL rewriting.
It writes a temporary file beside the output and renames it into place, so HTML
processing can see only complete assets. Lock files remain until cache cleanup;
OS locks are released when the worker exits, including on failure.

Duplicate workers reuse ready output or return when another worker holds the
lock. Scheduling, source and publication failures emit
`wpopt_minify_asset_failed($original_url, $type, $message)`. A later processed
request can enqueue a retry if the output is still missing. Cleanup cancels
pending asset events and purges the minification cache. WordPress Cron must run
for generated files to become available. Cached HTML retaining original URLs
continues to use them until that HTML is processed again.

## Script timing

Script timing prepares allowlists and exclusions when its configuration is
constructed. Each head/footer plan calculates manual exclusions once per active
script and reuses them for delay and defer decisions. The footer rebuilds the
active dependency graph and recalculates exclusions from current script sources.

## Request rules and database summaries

Static-page, WP_Query and database-cache settings use one ordered validator.
Common boolean defaults and pattern normalization have one owner; layer-specific
fields, field order, lifetimes and invalidation decisions are preserved. Static
user-agent list edits retain their existing invalidation policy, while dynamic
layers invalidate on submitted changes. Filtering calls do not trigger purges.

Static request-rule evaluation normalizes and separates active rules once per
call. Exclusions take precedence over the first matching include. Reports retain
their public arguments and read entry/byte totals from the SQL index namespace.
Each candidate rule is compiled once and checked against the request path with
and without its leading slash. Cookie exclusion lists are filtered once before
matching cookie names.

The options autoload summary reads autoload count/bytes and total count/bytes
with one conditional aggregate over the options table. Empty tables yield four
integer zeros.

## Frontend diagnostics

`FrontendAssets::diagnostic()` emits the live diagnostic action for every call.
It stores only changed details, sampling at most once per minute per feature.
The sampled record includes its page and time and expires after one day;
unchanged calls leave its expiry intact. See
[frontend performance](docs/frontend-performance.md) for engine and integration
contracts.

## LCP fingerprints and content invalidation

`FrontendAssets::version()` resolves local candidates to canonical files and
memoizes each fingerprint by path, size, modification/change times, inode and
frontend revision. Files up to 1 MiB use SHA-256 digests cached through the
WordPress object cache for one hour. Larger files use metadata fingerprints and
are not read for hashing. Image/srcset/background references to the same file
reuse its fingerprint. Metadata or explicit revision changes invalidate the
memoized result; failed hashes are retried on a later call.

Ordinary post saves use the cache module's existing targeted content purges.
LCP markup and Used CSS token fingerprints select the result for the rendered
content. Saves of shared blocks, templates, global styles and navigation update
the global frontend revision and notify global cache subscribers. Attachment,
term, theme, upgrade and watched settings changes retain global invalidation.
