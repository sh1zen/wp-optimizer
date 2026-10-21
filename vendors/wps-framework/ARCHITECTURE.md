# String matching

`StringHelper::contains_any()` provides case-insensitive literal substring
matching for prepared string lists. Callers own configuration normalization and
reuse the list across matching operations; WP Optimizer's frontend engines use
this utility for selector and asset policies.

# Settings lookup

`Settings::get_option()` traverses dot-separated paths directly, skipping empty
segments. Empty and leading-dot paths select the root. Missing or null nodes
return the caller's default; collections merge defaults at the selected node.
The same lookup serves WP Optimizer's shared layer-settings validator.

# Cache ownership

`Cache.class.php` owns request memory, hit/miss counters, object cloning,
context and multisite group names, persistence exclusions and disk fallback.
`Storage.class.php` owns durable entries under `WP_CONTENT_DIR`. Cache creates
a disk-only Storage instance on demand; Storage's internal Cache has its own
disk fallback disabled to keep the dependency acyclic.

Storage owns atomic publication for direct writes, deferred autosave and Cache's
disk fallback. Each save creates and completes its own temporary file beside the
target, then publishes it with a same-directory rename. A stable namespace lock
under `WP_CONTENT_DIR/.wps-storage-locks` orders publication, conditional snapshot
cleanup, memory hydration, deletion, age/group purges and safe group detachment.
The lock survives group removal and recreation. Ordinary disk reads remain lock-free;
snapshot-based cleanup and hydration recheck the current bytes under the lock.
The serialized record format and expiry policy are independent of publication.
See [storage publication](../../../../../extensions/wp-optimizer/PIPELINE.md#storage-publication)
for ordering, cleanup and failure behavior.

Cache writes durable values to the remote driver first, using disk only when no
driver is available or the remote write fails. Successful writes and promotion
of disk hits remove any existing fallback file, preventing stale copies from
resurfacing during an outage. This requires only file metadata/deletion work,
without serializing or writing another payload. A remote-only entry becomes a
cache miss if that backend is unavailable; callers rebuild it. Direct Storage
users continue to own their durable disk records independently of Cache.
Unforced Cache writes check memory, then the remote driver, then disk, stopping
at the first existing entry, including false/null values.

Cache's optional fifth constructor argument, `persist_zero_expiry`, selects
durable zero-expiry writes. The WordPress drop-in enables it; other instances
default to request-local zero expiry. Cache passes this policy to its disk-only
Storage instance and initializes the driver to clear any previous TTL on a zero
overwrite. Storage uses the existing `expire = 0` record format for permanent
entries in this mode. Group exclusions apply to both remote and disk persistence.

The driver contract is `drivers/cache/CacheInterface.class.php`. Redis and
Memcached implement native bulk reads; the default driver implementation
preserves single-read semantics. Cache sends only request-memory misses to the
driver, then resolves remaining misses through Storage. The WordPress object
cache drop-in consumes this public Cache API.
`Cache::get()` also supplies an optional by-reference hit flag from that same
lookup, so the drop-in can distinguish stored false/null values from misses
without querying the backend or loading a disk entry twice.

The Redis driver owns serialized value encoding and group indexes. Data keys
retain the `group#key` format. Each group has a sorted expiry index at
`wps-cache:group:v2:<sha256(group)>`; scores are absolute expiry milliseconds,
with positive infinity for persistent data. Server-side Lua owns atomic writes,
index maintenance, legacy set migration and bounded group deletion. Index
lifetimes follow the latest member expiry. Legacy sets at the group name are
consumed incrementally without changing the data keys or values.

See the plugin's [performance pipeline](../../../../../extensions/wp-optimizer/PIPELINE.md)
for read ordering, recovery and group-flush behavior.

`Options::upsert()` writes a computed option snapshot directly to its unique
object/context/item row with `INSERT ... ON DUPLICATE KEY UPDATE`, preserving
row identity and skipping comparison reads. It supports options without a
container. It shares serialization, expiry normalization, lock retries and cache
publication with the existing `add()` path. `add()` and `update()` retain their
existing replacement and comparison contracts. Performance Monitor and rule
statistics use upsert after computing their counters from one stored snapshot.
