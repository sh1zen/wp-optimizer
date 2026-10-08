# Used CSS

`WPOptimizer\modules\supporters\UsedCss::transform($html, $config)` accepts `exclude_files`, `exclude_pages`, `keep_selectors` and `static_selectors`. It records DOM tokens and immutable stylesheet hashes, then queues `wps_used_css_build` through WordPress Cron. It does not parse or generate CSS inside the visitor request. Each URL/DOM/asset/configuration/revision combination gets its own result; results are not shared by post type. Authenticated, preview, feed, error, non-GET and query-string requests are excluded.

The worker uses the bundled MIT-licensed Sabberworm PHP CSS Parser 8.8.0, stored in `vendors/css-parser` and scoped to `WPOptimizerVendor` to avoid other plugins' dependency conflicts. Only absent plain tag, class and ID selectors can be removed. State selectors, combinators, attributes, animations, font faces, layers and unknown at-rules are retained. Media/supports rules are traversed without selecting a single viewport, so all breakpoints remain available. Imports, namespaces, image-set and syntax the parser cannot accept retain original stylesheets. URLs in retained declarations are rebased to the original stylesheet location.

On a page with JavaScript, inline handlers or JavaScript links, every selector is preserved unless the administrator explicitly declares a plain selector static. The static list is an assertion that JavaScript never adds it. This conservative first version favors correctness over aggressive reductions. `keep_selectors` overrides the static list. Stylesheet exclusions, conditional/alternate sheets, integrity-protected sheets, remote assets and files larger than 1 MiB remain original. At most 40 local sheets and 12,000 DOM tokens are queued per result.

Original links remain while queued, when assets change, when workers fail, or when output files are absent. Ready outputs replace each stylesheet in place, preserving order and media. A native inline runtime restores the original href on a generated stylesheet load error. Minify skips these immutable outputs. A worker verifies asset hashes and content revisions before publication and publishes atomically. A per-job lock prevents concurrent writes. Ready records live seven days; generated files are retained for at least 30 days and bounded cleanup happens during later successful builds.

Used CSS selects rules needed anywhere on the page. It is not Critical CSS and does not select only the initial viewport.

## Ownership and loading

WP Optimizer owns `modules/supporters/pagespeed/UsedCss.class.php`, `assets/used-css-fallback.js` and the parser dependency. Its plugin entry point loads `inc/frontend-assets.php`, which registers the scoped autoloader and the `wps_used_css_build` worker on frontend, admin and Cron requests. Parser classes load on demand.

The engine uses WP Optimizer's `FrontendAssets` for asset validation, revisions, diagnostics and page-ready notifications, and WPS `UtilEnv` for generated asset URLs. See [frontend performance](frontend-performance.md) for the shared engine policy. Configuration fields, transient keys, generated file paths, HTML attributes and Cron event arguments retain their existing contracts.

## Verification

Run `php mini-test/used-css.php` from the CMS root. These isolated checks exercise the real plugin bootstrap, parsing, Cron build and HTML transformation with small WordPress boundary fakes, without booting the site or changing its saved settings.

Parser source: [Sabberworm PHP CSS Parser 8.8.0](https://github.com/MyIntervals/PHP-CSS-Parser/tree/v8.8.0).
