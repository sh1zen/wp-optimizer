# WP Optimizer frontend performance

WP Optimizer exposes these engines in its PageSpeed module. The classes use the `WPOptimizer\modules\supporters` namespace and live in `modules/supporters/pagespeed`; browser runtimes live in `assets`. The execution modes default to off. The existing LCP option is reused.

The plugin entry point loads `inc/frontend-assets.php` on frontend, admin, AJAX and Cron requests. It loads the engine classes, registers LCP measurement endpoints and content invalidation hooks, and registers the scoped parser autoloader and Used CSS worker. `FrontendAssets` owns asset policy, revisions and diagnostics. WPS supplies general services such as `UtilEnv` and the HTML output buffer. See [Used CSS](used-css.md) for CSS processing.

## LCP and lazy loading

`LcpImages::transform($html, $learn, $lazy, $exclusions)` owns both image priority and learning. Exclusion lists are `images` (ID fragments), `classes` (class fragments) and `urls` (URL fragments). Excluded images, images with high fetch priority, explicit `data-wps-lcp` images and learned picture owners receive eager loading. Existing loading attributes on other images are preserved.

The observer records the final image candidate at first interaction or page exit. It never inserts a late preload. Public requests issue a signed, expiring manifest of image URLs already declared in the HTML, inline backgrounds or local stylesheets. The anonymous AJAX endpoint rejects invalid signatures, missing/array/oversized fields, unrecognized URLs, stale content revisions and unknown viewport classes. Updates are limited to once per minute per page fingerprint and viewport.

Later HTML contains early head hints for learned mobile (up to 767px) and desktop candidates. Mutually exclusive media conditions prevent the browser from fetching both variants. Responsive sets carry `imagesrcset` and `imagesizes`; picture sources also retain their media and MIME type. Complex picture media lists are preserved without guessing a preload. Background candidates use their declared URL. Remote CSS is not fetched to discover backgrounds.

The key includes the page URL, image/source markup, local asset fingerprints, exclusions and a frontend revision. Local files up to 1 MiB use content hashes memoized per request and cached for one hour; larger files use size, modification/change times and inode without reading their contents. File metadata or revision changes refresh fingerprints. Replacements preserving metadata must call `FrontendAssets::invalidate()`. Remote assets must change their URL/version or call that method when replaced in place. Records and measurement tokens expire after seven days; keep page cache lifetimes below that period. Cold pages retain native/author image priorities until a candidate is learned.

Ordinary post saves rely on rendered markup/token fingerprints and targeted content-cache purges. Shared blocks, templates, global styles and navigation saves invalidate globally, as do attachment, term, theme, plugin upgrade and relevant settings changes.

## Defer and selective Delay

`new ScriptTiming($config)` accepts `defer`, `delay`, `delay_handles`, `delay_urls`, `exclude_handles`, `exclude_urls` and `exclude_pages`. Handles are exact names; URL and page rules are case-insensitive fragments, one per line.

Defer requests the native WordPress strategy on WordPress 6.3 or newer. WordPress resolves the final dependency-safe strategy; existing strategies and interactive infrastructure are preserved. Diagnostics distinguish the requested rule from the final printed strategy.

Delay requires an explicit allowlist and only handles registered footer scripts. The dependency graph is checked again before footer printing. An immediate dependent protects its complete dependency chain. Known menus, forms, consent, jQuery, CAPTCHA and commerce assets remain immediate. Head scripts, separate localized data, lifecycle-dependent inline blocks, conditional scripts and existing async/defer strategies are preserved. Unknown scripts are never selected automatically.

Selected external scripts and associated inline-before, translations and inline-after blocks become an ordered inert bundle. The loader preserves attributes including nonce, integrity and crossorigin. It activates at idle after load, after an interaction has completed, or after five seconds. It never cancels or replays a click. External/module load failures skip that handle's remaining blocks and dependent bundles, emitting `wps:script-delay-error`. Completion emits `wps:script-delay-complete`. `window.wpsDelay.start()` is available for explicit integration. Delay is intended for background scripts that tolerate late execution; scripts requiring parser-time execution must be excluded.

Classic WordPress handles whose printed tag uses `type="module"` retain module semantics and execute in bundle order. Native `wp_enqueue_script_module()` assets/import maps stay under WordPress control and are not selected by this engine. Unregistered tags also remain unchanged. Inline runtime tags use WordPress's native attribute/nonce filters; CSP integrations must permit these just as they permit associated WordPress inline scripts. A missing runtime file leaves scripts immediate.

## Caches and diagnostics

`wps_frontend_page_ready($url)` lets consumers purge an affected cached page after a measurement becomes ready. `wps_frontend_assets_invalidated` signals revision changes. WP Optimizer's static cache subscribes to both. External page/CDN caches must subscribe too or be purged through their normal integration.

The PageSpeed settings show sampled public-page diagnostics: learned candidates and applied script rules. `wps_frontend_diagnostic($feature, $details)` fires for every diagnostic call so other consumers receive current details. Persistent diagnostics use one record per feature with a one-day expiry. Identical details do not refresh the record, even when the page or timestamp changes. Changed details are sampled at most once per minute per feature; the stored page and time identify that sample. Expired records are recreated on the next diagnostic call.

## Verification

From the CMS root, run `php -n tests/frontend-versions.php` and `php -n tests/cache-monitor.php` with PHP 7.4 or newer. These checks cover LCP fingerprints, repeated candidates, the hashing limit, cross-request reuse, learned preloads and targeted content/shared frontend invalidation. They use the adjacent WordPress HTML and hook APIs with isolated storage, option and SQL boundaries; no site bootstrap, database or network is required. See [object-cache verification](../README.md#object-cache-regression-check) for default-expiry persistence checks.

References: [early LCP discovery](https://web.dev/articles/optimize-lcp), [WordPress loading strategies and dependencies](https://developer.wordpress.org/reference/functions/wp_enqueue_script/).
