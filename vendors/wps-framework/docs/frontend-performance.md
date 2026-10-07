# Shared frontend performance engines

WP Optimizer exposes these engines in its existing PageSpeed module. Other WPS consumers can use the same classes; no engine belongs to the minifier. The new execution and Used CSS modes default to off. The existing LCP option is reused.

## LCP and lazy loading

`LcpImages::transform($html, $learn, $lazy, $exclusions)` owns both image priority and learning. Exclusion lists are `images` (ID fragments), `classes` (class fragments) and `urls` (URL fragments). Excluded images, images with high fetch priority, explicit `data-wps-lcp` images and learned picture owners receive eager loading. Existing loading attributes on other images are preserved.

The observer records the final image candidate at first interaction or page exit. It never inserts a late preload. Public requests issue a signed, expiring manifest of image URLs already declared in the HTML, inline backgrounds or local stylesheets. The anonymous AJAX endpoint rejects invalid signatures, missing/array/oversized fields, unrecognized URLs, stale content revisions and unknown viewport classes. Updates are limited to once per minute per page fingerprint and viewport.

Later HTML contains early head hints for learned mobile (up to 767px) and desktop candidates. Mutually exclusive media conditions prevent the browser from fetching both variants. Responsive sets carry `imagesrcset` and `imagesizes`; picture sources also retain their media and MIME type. Complex picture media lists are preserved without guessing a preload. Background candidates use their declared URL. Remote CSS is not fetched to discover backgrounds.

The key includes the page URL, image/source markup, local asset hashes, exclusions and a content revision. Content, attachment, term, theme, plugin upgrade and relevant settings changes invalidate results. Remote assets must change their URL/version or call `FrontendAssets::invalidate()` when replaced in place. Records and measurement tokens expire after seven days; keep page cache lifetimes below that period. Cold pages retain native/author image priorities until a candidate is learned.

## Defer and selective Delay

`new ScriptTiming($config)` accepts `defer`, `delay`, `delay_handles`, `delay_urls`, `exclude_handles`, `exclude_urls` and `exclude_pages`. Handles are exact names; URL and page rules are case-insensitive fragments, one per line.

Defer requests the native WordPress strategy on WordPress 6.3 or newer. WordPress resolves the final dependency-safe strategy; existing strategies and interactive infrastructure are preserved. Diagnostics distinguish the requested rule from the final printed strategy.

Delay requires an explicit allowlist and only handles registered footer scripts. The dependency graph is checked again before footer printing. An immediate dependent protects its complete dependency chain. Known menus, forms, consent, jQuery, CAPTCHA and commerce assets remain immediate. Head scripts, separate localized data, lifecycle-dependent inline blocks, conditional scripts and existing async/defer strategies are preserved. Unknown scripts are never selected automatically.

Selected external scripts and associated inline-before, translations and inline-after blocks become an ordered inert bundle. The loader preserves attributes including nonce, integrity and crossorigin. It activates at idle after load, after an interaction has completed, or after five seconds. It never cancels or replays a click. External/module load failures skip that handle's remaining blocks and dependent bundles, emitting `wps:script-delay-error`. Completion emits `wps:script-delay-complete`. `window.wpsDelay.start()` is available for explicit integration. Delay is intended for background scripts that tolerate late execution; scripts requiring parser-time execution must be excluded.

Classic WordPress handles whose printed tag uses `type="module"` retain module semantics and execute in bundle order. Native `wp_enqueue_script_module()` assets/import maps stay under WordPress control and are not selected by this engine. Unregistered tags also remain unchanged. Inline runtime tags use WordPress's native attribute/nonce filters; CSP integrations must permit these just as they permit associated WordPress inline scripts. A missing runtime file leaves scripts immediate.

## Used CSS

`UsedCss::transform($html, $config)` accepts `exclude_files`, `exclude_pages`, `keep_selectors` and `static_selectors`. It records DOM tokens and immutable stylesheet hashes, then queues `wps_used_css_build` through WordPress Cron. It does not parse or generate CSS inside the visitor request. Each URL/DOM/asset/configuration/revision combination gets its own result; results are not shared by post type. Authenticated, preview, feed, error, non-GET and query-string requests are excluded.

The worker uses the bundled MIT-licensed Sabberworm PHP CSS Parser 8.8.0, scoped to `WPSVendor` to avoid other plugins' dependency conflicts. Only absent plain tag, class and ID selectors can be removed. State selectors, combinators, attributes, animations, font faces, layers and unknown at-rules are retained. Media/supports rules are traversed without selecting a single viewport, so all breakpoints remain available. Imports, namespaces, image-set and syntax the parser cannot accept retain original stylesheets. URLs in retained declarations are rebased to the original stylesheet location.

On a page with JavaScript, inline handlers or JavaScript links, every selector is preserved unless the administrator explicitly declares a plain selector static. The static list is an assertion that JavaScript never adds it. This conservative first version favors correctness over aggressive reductions. `keep_selectors` overrides the static list. Stylesheet exclusions, conditional/alternate sheets, integrity-protected sheets, remote assets and files larger than 1 MiB remain original. At most 40 local sheets and 12,000 DOM tokens are queued per result.

Original links remain while queued, when assets change, when workers fail, or when output files are absent. Ready outputs replace each stylesheet in place, preserving order and media. A native inline runtime restores the original href on a generated stylesheet load error. Minify skips these immutable outputs. A worker verifies asset hashes and content revisions before publication and publishes atomically. A per-job lock prevents concurrent writes. Ready records live seven days; generated files are retained for at least 30 days and bounded cleanup happens during later successful builds.

Used CSS selects rules needed anywhere on the page. It is not Critical CSS and does not select only the initial viewport. The font-lazy-loading description now describes its actual stylesheet loading behavior.

## Caches and diagnostics

`wps_frontend_page_ready($url)` lets consumers purge an affected cached page after a measurement or CSS job becomes ready. `wps_frontend_assets_invalidated` signals revision changes. WP Optimizer's static cache subscribes to both. External page/CDN caches must subscribe too or be purged through their normal integration.

The PageSpeed settings show the last public-page diagnostics: learned candidates, applied script rules, and CSS queue/ready/fallback reasons. `wps_frontend_diagnostic($feature, $details)` supports other consumers. Diagnostics are bounded to one expiring record per feature and contain public page/asset information.

## Verification

Run `tools/wps-frontend-regression.php` with the project's PHP executable. This CLI-only helper boots the actual local WordPress installation, validates the real settings fields, exercises signed AJAX, LCP and asset invalidation, native defer, dependency guards, CSS parsing, queue/build/fallback and per-page separation. It never changes saved optimization settings.

Pass `--keep-fixture` to generate `tools/wps-frontend-fixture.tmp.html` and its temporary local assets. Open that fixture in a browser: it checks first menu, consent, form and fixture purchase interactions while six script blocks remain inert, then displays actual execution order. It also deliberately requests a missing generated stylesheet to verify fallback. Check the 390px breakpoint and menu state styling. These are isolated interactions, not a live payment checkout. Remove the temporary `*.tmp.*` fixture files after verification; a normal CLI run cleans up its own fixture assets, transients and scheduled jobs.

References: [early LCP discovery](https://web.dev/articles/optimize-lcp), [WordPress loading strategies and dependencies](https://developer.wordpress.org/reference/functions/wp_enqueue_script/), [CSS parser upstream](https://github.com/MyIntervals/PHP-CSS-Parser/tree/v8.8.0).
