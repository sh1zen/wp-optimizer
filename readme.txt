
=== WP Optimizer –  The All-In-One real Performance-Boosting Plugin ===  
Contributors: sh1zen  
Tags: optimizer, caching, performance, image, minify  
Donate link: https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU  
Requires at least: 5.0.0  
Tested up to: 7.0.0  
Requires PHP: 7.4  
Stable tag: 2.8.7
License: GPLv2 or later  
  
All-in-one speed optimization plugin: cache, lazy load, minify, media and environment optimization & Core Web Vitals. Free & privacy safe.  
  
== Description ==  
  
Are you frustrated by a slow website? WP Optimizer brings performance, maintenance, diagnostics and site-management tools into one modular WordPress plugin.  
 
WPcOptimizer is a free, all-in-one performance plugin that can replace the 3–5 separate speed plugins most sites run today — page caching, script minification, image lazy loading, LCP preloading, WebP delivery and more — to help you pass Core Web Vitals: Largest Contentful Paint (LCP), Cumulative Layout Shift (CLS) and Interaction to Next Paint (INP). It runs on Apache, Nginx, LiteSpeed and OpenLiteSpeed, and it is fully WooCommerce-aware.

One cache plugin, one dashboard, no premium upsells for core features, and everithing run on your server, no external tool or subscription is required. 

And uf you prefer to keep your current cache plugin? Turn off the modules that overlap and use only the tools you want (see the FAQ).

= Core Features =

* **Static Page Cache:** Store complete HTML responses on disk and serve eligible cached pages before WordPress loads through server-specific rules. Configure lifetimes, URL rules, query-string handling, user scope, status-code groups, cookie and user-agent exclusions, automatic content-aware purging and per-rule hit, miss and disk-usage reports.
* **Query & Object Caching:** Cache selected `WP_Query` results and database queries with independent lifetimes, rules and targeted invalidation when related content changes. An optional WordPress object-cache drop-in can use Redis or Memcached when a supported PHP extension and service are available.
* **HTML, CSS & JavaScript Minification:** Minify rendered HTML, inline CSS and inline JavaScript, plus local stylesheet and script files. Generated assets are cached on disk, already-minified URLs are skipped, relative CSS paths are preserved and sensitive page-builder assets and markup bypass transformation.
* **Compression & Browser Caching:** Generate server directives for GZIP or Brotli compression and configurable browser-cache headers for CSS, JavaScript, images, fonts, archives and other static resources, including optional `immutable`, `stale-if-error` and `stale-while-revalidate` policies where supported.
* **Lazy Loading & Layout Stability:** Add native lazy loading to images and iframes, prevent videos from preloading data before they are needed and add missing image width and height attributes when WordPress attachment metadata is available.
* **LCP, Font & Navigation Hints:** Detect an image reported as the Largest Contentful Paint element and add a high-priority image preload, enforce `font-display: swap`, optionally defer font stylesheets and prefetch eligible same-origin links on hover or touch intent.
* **Local Image Optimization & WebP:** Compress existing media-library images and generated thumbnails locally with Imagick or GD, optionally resize oversized files, preserve EXIF data when requested and convert supported images to WebP. New uploads and large library scans can be processed automatically in background batches.
* **Media Cleaner:** Scan the media library or a selected uploads path for files that are not referenced by WordPress content, then review, ignore or delete the reported items from the dashboard.
* **Four-Stage Page Test:** Compare a signed baseline request with WP Optimizer bypassed against the current warmed configuration. The report measures response time, TTFB, peak memory and response size, while diagnostic warmup data highlights slow or repeated queries, expensive hooks and callback samples.
* **Performance Monitor:** Record sampled request history and cache hit/miss metrics, identify slow SQL and profile the load time, callback time, SQL time, memory use and query count attributable to WordPress core, the active theme and installed plugins. Capture scope, sampling rates and slow-request thresholds are configurable.
* **Database Cleanup, Backups & Autoload Health:** Remove revisions, auto-drafts, trashed posts, spam comments, transients and orphaned metadata, optimize selected tables, create and restore SQL backups and inspect the size and autoload status of `wp_options` rows before disabling autoload or deleting an option.
* **Cron & Heartbeat Management:** Schedule automatic database and media optimization, reduce front-end cron checks, set the wp-admin Heartbeat interval and inspect, create, edit, run or delete WordPress cron events and custom schedules.
* **WordPress Cleanup & Admin Controls:** Independently disable unused WordPress output and features such as emojis, XML-RPC, feeds, oEmbed/REST access, shortlinks, relational links, the core sitemap, jQuery Migrate, Dashicons, Global Styles, widgets, comments and selected Block Editor features. Dashboard panels, Admin Bar items and non-admin dashboard access can also be controlled.
* **Activity Log & Suspicious Request Monitoring:** Record selected user, post, term, attachment, option and plugin actions with optional IP, user-agent and request data. Optional request rules detect and log patterns associated with XSS, SQL injection, path traversal, command injection, sensitive-file probes and custom regular expressions.
* **WordPress & Server Security Hardening:** Generate environment-aware rules that can disable directory listings and HTTP TRACE, protect configuration files, add HSTS, MIME-sniffing, referrer and frame protections, reduce WordPress and server version disclosure, block basic user enumeration and disable the built-in file editor.
* **Cloudflare Cache Purging:** Test a Cloudflare API token and Zone ID from the dashboard, purge by host, cache tag or the complete zone and automatically clear the configured Cloudflare edge cache when WP Optimizer clears its local page cache.
* **SMTP & Mail Logging:** Override WordPress PHPMailer connection settings with a configurable SMTP server, authentication, encryption and timeout, and optionally record outgoing email with automatic cleanup of older log entries.
* **WordPress Update Controls:** Disable WordPress core or plugin update checks, the general automatic updater, update notices, the Updates admin page and update notification emails. WP Optimizer retains its own twice-daily manual update check when general plugin update checks are disabled.
* **Configuration Backups & Recovery:** Create throttled configuration snapshots before settings changes, retain the latest 50 backups and provide restore, import, export, per-module reset and full-reset tools. If WP Optimizer or one of its managed cache drop-ins causes a fatal error, the recovery service can try a saved configuration or perform a controlled plugin reset.
* **System, Server & Compatibility Tools:** Review WordPress, PHP, database, web-server, filesystem and storage details from one screen. Server rules are generated for Apache, Nginx, LiteSpeed Enterprise or OpenLiteSpeed, while WooCommerce transactions and supported page-builder edit or preview requests automatically bypass incompatible cache and output transformations.
  
= Configuration backups and recovery =  
  
WP Optimizer creates a configuration backup before its main settings are updated. To avoid duplicate snapshots during rapid auto saves, it reuses a backup created within the previous 15 minutes and retains the newest 50 backups.
  
From the Settings module, administrators can review or delete backups, restore a previous configuration, import or export settings and reset individual modules. Restoring settings runs the normal configuration lifecycle so modules, managed cache drop-ins and generated server rules stay synchronized.  
  
If a fatal error comes from WP Optimizer or a plugin-managed `object-cache.php` or `db.php` drop-in, the recovery service can try saved configurations or perform a controlled factory reset. A reset can remove plugin-managed drop-ins, generated server rules, static cache data, minified resources, direct-cache files and scheduled optimization tasks. Recovery does not reset unrelated themes, plugins or server configuration.  
  
= Server, WooCommerce and page-builder compatibility =  
  
WP Optimizer supports WordPress Multisite and detects Apache, Nginx, LiteSpeed Enterprise and OpenLiteSpeed.  
  
* **Apache and LiteSpeed Enterprise** use managed rules in the local `.htaccess` file.  
* **Nginx** uses a generated configuration file that must be included in the website server block before Nginx is reloaded.  
* **OpenLiteSpeed** receives compatible rewrite rules for direct cache delivery, redirects and rewrite-based security controls. Enable **Auto Load from .htaccess** for the virtual host. Configure compression, response headers, MIME types and other non-rewrite options in WebAdmin, then restart OpenLiteSpeed after rewrite changes.
  
= A Free Alternative to Premium Performance Plugins =

WP Optimizer is a free alternative to paid performance plugins such as WP Rocket, FlyingPress and many others.
Static page caching with optional direct delivery, WP_Query and database caching, Redis or Memcached object caching, HTML/CSS/JavaScript minification, GZIP and Brotli compression, browser caching, lazy loading, LCP and font optimizations, local image compression and WebP conversion, Cloudflare purging, database cleanup, cron management, Page Test and Performance Monitor are included without premium-locked modules or feature upsells. Use it as a modular all-in-one toolkit or enable only the tools that complement your existing setup, but never let two plugins manage the same optimization layer—especially page cache, object or database cache, minification or generated server rules.
  
= Recommended setup =  
  
For an existing or production site:  

1. Review the environment using WP Info module.
2. Enable browser caching, supported compression and image optimization.
3. Enable WP-Optimizer schedule to allow auto image and database optimization.
4. Create a database backup before cleanup.
5. Enable the standard page cache and test dynamic pages.
6. Activate HTML, CSS and JavaScript optimization separately.
7. Clear generated caches after important setting changes.
8. Test forms, search, login, account and checkout flows.
9. Use Page Test and Performance Monitor to verify the result.
  
== Installation ==  
  
1. Go to Plugins → Add New in your WordPress dashboard.  
2. Search for “WP Optimizer”.  
3. Click Install Now.  
4. Activate the plugin.  
5. Review the one-time welcome page, which explains how the modular workflow works.  
6. Open WP Optimizer from the WordPress admin menu.  
7. Enable only the modules you need.  
8. Start with browser cache, compression and media optimization, then enable cache and minify gradually.  
  
You can also install WP Optimizer manually by uploading the plugin folder to `/wp-content/plugins/` and activating it from the Plugins screen.  
  
For multisite installations, install the plugin from the Network Admin area and network activate it if you want to use it across the network.  
  
== Frequently Asked Questions ==  
  
= What is WP Optimizer? =  
  
WP Optimizer is a modular WordPress optimization plugin for performance, Core Web Vitals, database maintenance, security hardening, diagnostics and admin cleanup.  
  
= Is WP Optimizer only a cache plugin? =  
  
No. WP Optimizer includes caching, but it also provides minification, image optimization, database cleanup, cron tuning, compression, browser cache policies, performance monitoring, security hardening, mail logging, server diagnostics and WordPress customization tools.  
  
= What should I enable first on a live site? =  
  
Start with lower-risk optimizations: browser cache, compression and media optimization. Before database cleanup, create a backup. Then enable cache and minify options one at a time, clearing cache and testing the front end after each change.  
  
= Can cache or minify break my layout? =  
  
Yes. Like any performance plugin, aggressive cache or minification settings can expose theme or plugin conflicts. Enable HTML, CSS and JavaScript minification separately. If something breaks, disable the last option you enabled, clear cache and test again.  
  
= Is WP Optimizer compatible with WooCommerce and page builders? =  
  
Yes. WooCommerce cart, checkout and my-account routes are automatically excluded from caching and runtime HTML optimization. WooCommerce session cookies and cart/API actions also bypass cache. WP Optimizer detects editing and preview requests from Elementor, Beaver Builder, Divi, Gutenberg, Bricks, Oxygen and Breakdance, preserves their generated markup and does not rewrite their CSS or JavaScript assets. Built-in exclusions cannot be removed by integration filters.  
  
= How does the cache system work? =  
  
WP Optimizer has multiple cache layers. Static page cache stores rendered HTML pages, object cache integrates with WordPress object caching and can use Redis or Memcached, WP_Query cache stores selected WordPress query results, and database query cache stores selected database query results. Each layer has its own lifetime, exclusions, purge behavior and scope controls.  
  
= What is Page Test? =  
  
Page Test is a browser-based diagnostic workflow for a specific site URL. It compares a WP Optimizer bypass baseline with the current active configuration, warms the active configuration, then reports timing, TTFB, memory and size differences together with warmup diagnostics such as slow queries, repeated queries and heavier hooks.  
  
= Do I need Redis or Memcached? =  
  
Only if you want to use the object cache feature. Browser cache, static cache, database/query cache, compression, minification and media optimization can still be used without Redis or Memcached.  
  
= Does WP Optimizer optimize images? =  
  
Yes. WP Optimizer includes media optimization, WebP conversion, background image optimization and unused media cleanup.  
  
= Can WP Optimizer help with Core Web Vitals? =  
  
Yes. WP Optimizer helps improve the technical foundation behind Core Web Vitals through caching, minification, compression, browser caching, media optimization, reduced page weight and performance monitoring.  
  
= Does WP Optimizer include database cleanup? =  
  
Yes. The Database module includes cleanup, optimization and backup utilities for maintaining WordPress database tables and removing unnecessary or orphaned data.  
  
= Is WP Optimizer privacy-friendly? =  
  
WP Optimizer runs optimization tasks on your own server. Optional telemetry controls are available in the Tracking module.  
  
= Does WP Optimizer include security features? =  
  
Yes. WP Optimizer includes practical WordPress and server-level hardening options, activity logging and suspicious request monitoring.  
  
= Does WP Optimizer support multisite? =  
  
Yes. WP Optimizer supports multisite and can be network activated. Each site can still require different performance settings depending on theme, plugins and traffic.  
  
= Can I export or restore plugin settings? =  
  
Yes. The Settings module supports reset, import, export, restore and autosave features, which is useful when testing aggressive optimization settings or moving configurations between sites. It also stores automatic configuration backups before plugin settings changes, with restore and delete actions available from the Settings module. Individual modules can also be reset to factory settings from the modules screen; this asks for confirmation and runs the module cleanup lifecycle.  
  
= Are configuration backups created automatically? =  
  
Yes. WP Optimizer creates a configuration backup before the main plugin settings are updated. If the newest backup is less than 15 minutes old, the existing recent backup is reused instead of creating another one for every autosave or rapid setting change. The newest 50 configuration backups are kept; older entries are removed automatically.  
  
= What happens if a bad configuration causes a fatal error? =  
  
If the fatal error is caused by WP Optimizer code or a WP Optimizer-managed cache drop-in, recovery mode offers Try Recover and Reset actions to administrators. Try Recover restores saved configuration backups one by one and tests whether the request succeeds. Reset restores WP Optimizer to factory settings and removes plugin-managed cache drop-ins, local server rules, generated optimization storage and scheduled optimizer tasks.  
  
= What should I do if something looks wrong after changing settings? =  
  
Disable the last module or option you enabled, clear cache and test again. Use Page Test to compare the active configuration with the bypass baseline. If needed, use the Settings module to restore an automatic configuration backup or reset options, then re-enable features one by one.  
  
= Is WP Optimizer compatible with WP Rocket, FlyingPress, Perfmatters, LiteSpeed Cache and other optimization plugins? =  
  
WP Optimizer can coexist with other optimization plugins, but the same optimization layer should not be enabled in more than one plugin. Choose a single owner for page cache, minification, asset combination and server rules, then clear every cache and test the site after changing the configuration.  
  
= How do I exclude a page from caching? =  
  
Open the Static Pages Cache configuration and add an exclude rule for the required path or URL pattern. WooCommerce cart, checkout and account routes are excluded automatically.  
  
= Does WP Optimizer minify and combine CSS and JavaScript? =  
  
Yes. The Minify module can minify HTML, CSS and JavaScript and can optionally try to combine CSS or JavaScript files. Enable each option separately, clear cache and verify important pages after every change because themes and plugins may depend on asset order.  
  
= Do I need a CDN to use WP Optimizer? =  
  
No. Caching, minification, media optimization and server features run without a CDN. You can still use a CDN independently; if it caches HTML, coordinate its purge behavior with WP Optimizer so visitors do not receive stale pages.  
  
= Will WP Optimizer slow down my wp-admin dashboard? =  
  
Front-end transformations do not normally run on wp-admin pages, and cache layers can be configured to skip administrative requests. Monitoring, backups and bulk media or database jobs still use server resources, so schedule heavy work outside busy periods and disable modules you do not need.  
  
= Can I use WP Optimizer with Cloudflare? =  
  
Yes. The Cloudflare module can purge the Cloudflare edge cache when WP Optimizer cache is cleared, using a scoped API token and Zone ID. Avoid duplicating HTML caching or optimization features without testing, and verify that both cache layers are purged after content changes.  
  
= What happens to my cache when I edit a post or product? =  
  
When automatic purge is enabled, WordPress content-change hooks invalidate affected static-page and query cache entries after posts, products, terms or comments change. WooCommerce-sensitive routes remain excluded. If the Cloudflare integration is enabled, WP Optimizer also requests the configured edge-cache purge.  
  
= How do I completely uninstall WP Optimizer and remove its data? =  
  
For the cleanest removal, first reset active modules to factory settings so their cleanup lifecycle removes managed drop-ins, generated storage and local server rules. Then deactivate and delete WP Optimizer from the WordPress Plugins screen. The uninstall routine removes plugin options, scheduled media hooks and plugin database tables from every site in a Multisite network; shared WPS framework data is removed only when no other installed component uses it.  
  
= Is there a developer API for clearing the cache? =  
  
Yes. Integration code can guard with `function_exists()` and call `wpopt_flush_cache('integration-name')` to flush active WP Optimizer cache layers. Bulk processes can use `wpopt_suspend_cache_auto_purge()` and `wpopt_resume_cache_auto_purge()` to avoid repeated purges. See `EXTERNAL-API.md` for the supported functions and examples.  
  
== Changelog ==  

= 2.8.8 =

* added plugin compatibility headers
* improved core performances
* improved Multisite experience
* updated docs
* fixed some bugs

= 2.8.6 =  

* added automatic WooCommerce and page-builder compatibility safeguards  
* removed the unused legacy implementations  
* hardened WooCommerce session, cart action and database-cache exclusions  
* made direct-cache migration fail-safe when runtime configuration is missing or invalid
  
= 2.8.4 =  
  
* added dedicated db tables to performance monitor and cache  
* improved performances  
* removed legacy fallbacks  
* fixed some bugs  
  
= 2.8.2 =  
  
* added config backup and restore  
* added error handling and recovery  
* added Page Test tool  
* added welcome page  
* improved cache configuration documentation and diagnostics  
* fixed some UI/UX issues  
  
= 2.8.1 =  
  
* fixed some bugs as reported  
* improved translations  
  
= 2.8.0 =  
  
* updated UI/UX  
* added PageSpeed module  
* improved core performances  
* fixed some bugs  
  
= 2.7.1 =  
  
* added Cron module to manage scheduled tasks  
* extended support to WordPress 7.0  
* improved plugin/module initialization and lazy dependency loading to reduce unnecessary work during requests  
* updated UI/UX  
  
= 2.6.5 =  
  
* added to PerformanceMonitor SQL monitor, Cache hit/miss, plugin time and memory footprint  
* improved core security hardening  
  
= 2.6.0 =  
  
* fixed Activity Log authenticated SQL injection vulnerability (CVE-2026-6295) and hardened equivalent WP Mail search handling  
* hardened request actions with mandatory nonce validation and admin-only execution for sensitive actions  
* secured settings import, database action arguments and wpsargs parsing against unsafe deserialization  
* hardened database backup excluded-table handling in mysqldump commands  
* limited WP Mail message preview in the table and added a popup view for full content  
  
= 2.5.0 =  
  
* added performance monitor module  
* fixed some bugs in the activity-log  
* fixed ImageProcessor issue on delete images  
* improved UI/UX  
* updated translations  
  
= 2.4.0 =  
  
* added info in some modules  
* improved performances  
* fixed bugs in Minify Modules  
* fixed bugs in cache modules  
* improved UI/UX  
  
= 2.3.8 =  
  
* updated translations  
* extended support to WordPress 6.9  
* improved media cleaner  
  
= 2.3.7 =  
  
* updated translations  
* extended support to WordPress 6.8  
* fixed some bugs on old version of PHP  
  
= 2.3.5 =  
  
* improved core performances  
* improved performances  
* updated translations  
* extended support to WordPress 6.7  
  
= 2.3.4 =  
  
* added new module to configure mail transport and log mails  
* added Welcome page on plugin activation  
* improved ImagesProcessor  
* improved core performances  
* improved uninstallation process  
* updated admin UI  
* updated translations  
* fixed some compatibility bugs  
  
= 2.2.5 =  
  
* added support for WordPress fonts  
* added blueprint.json for WordPress preview  
* improved core performances  
* improved performances  
* updated translations  
* extended support for WordPress 6.5  
  
= 2.2.2 =  
  
* improved media scan  
* improved core performances  
* improved Gutenberg disable  
* improved ActivityLog  
* extended support for WordPress 6.4
