=== WP Optimizer – PageSpeed, Cache, Minify & Core Web Vitals ===
Contributors: sh1zen
Tags: cache, core web vitals, pagespeed, performance, image optimization
Donate link: https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU
Requires at least: 5.0.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Improve WordPress speed and Core Web Vitals with page cache, WebP, minification, database cleanup and built-in performance diagnostics.

== Description ==

**WP Optimizer is a free, modular WordPress cache and performance optimization plugin built to make WordPress faster, lighter and easier to diagnose.**

Improve page speed and Core Web Vitals with **page caching, HTML/CSS/JavaScript minification, browser caching, GZIP/Brotli compression, lazy loading, LCP optimization, local image compression, WebP conversion, database cleanup and performance monitoring** — from one dashboard.

Unlike optimization tools that depend on a mandatory external service, WP Optimizer's core optimization features run on **your own server**. No subscription or premium upgrade is required to unlock the performance modules listed below.

Already using another cache or optimization plugin? WP Optimizer is modular: **enable only the tools you need** and leave overlapping modules disabled.

= Optimize WordPress Performance =

WP Optimizer combines the most common WordPress performance optimizations in one plugin:

* **Page Cache** – Store complete HTML responses on disk and serve eligible cached pages before WordPress loads.
* **Browser Cache** – Configure caching policies for CSS, JavaScript, images, fonts and other static resources.
* **GZIP & Brotli Compression** – Generate supported server rules to reduce transferred page size.
* **HTML, CSS & JavaScript Minification** – Minify page output and local assets, with optional CSS and JavaScript combination.
* **Lazy Loading** – Lazy load images and iframes and prevent unnecessary video preloading.
* **LCP Optimization** – Preload a detected Largest Contentful Paint image with high fetch priority.
* **JavaScript Timing** – Optionally defer scripts with WordPress dependency checks and delay selected background scripts until idle.
* **Used CSS** – Optionally generate per-page Used CSS in the background, retaining original stylesheets until ready and on errors.
* **Font Optimization** – Apply `font-display: swap` and optionally defer font stylesheets.
* **Navigation Prefetching** – Prefetch eligible same-origin links when visitors show navigation intent.
* **Local Image Optimization** – Compress images and generated thumbnails using Imagick or GD.
* **WebP Conversion** – Convert supported images to WebP directly on your server.
* **Automatic Media Optimization** – Process new uploads and larger media-library jobs in background batches.
* **Database Optimization** – Clean revisions, transients, orphaned metadata, spam and other unnecessary database data.
* **DB Media Optimization** – Optionally move media metadata out of postmeta, with automatic restoration when disabled.
* **Object Cache** – Optional Redis or Memcached integration when the required service and PHP extension are available.
* **WP_Query & Database Query Cache** – Cache selected expensive WordPress and database query results.

WP Optimizer can be used as a complete WordPress performance toolkit or alongside an existing setup when overlapping optimization layers are disabled.

= Measure Before and After – Don't Guess =

Optimization is more useful when you can verify what actually changed.

**Page Test** compares a baseline request with WP Optimizer bypassed against your current warmed configuration.

It reports metrics including:

* Response time
* Time to First Byte (TTFB)
* Peak memory usage
* Response size
* Slow database queries
* Repeated queries
* Expensive WordPress hooks and callbacks

This makes it easier to determine whether a configuration is actually improving your site instead of enabling performance options blindly.

= Find Slow Plugins and Database Queries =

The built-in **Performance Monitor** helps identify where WordPress is spending time and resources.

It can profile:

* Plugin load time
* Theme load time
* WordPress core load time
* SQL execution time
* Query count
* Memory usage
* Slow database queries
* Cache hits and misses
* Slow requests
* Callback execution time

Use configurable sampling and slow-request thresholds to investigate performance problems without permanently profiling every request.

If your WordPress site has a high TTFB or slow backend, Performance Monitor can help identify whether the bottleneck comes from a plugin, theme, database query or WordPress itself.

= Page Cache =

WP Optimizer includes a configurable static page cache for WordPress.

Eligible rendered HTML pages can be stored on disk and served using server-specific rules before a normal WordPress request needs to finish loading.

Cache controls include:

* Cache lifetime rules
* URL inclusion and exclusion rules
* Query-string handling
* User scope
* Status-code rules
* Cookie exclusions
* User-agent exclusions
* Automatic cache invalidation
* Content-aware purging
* Cache hit and miss statistics
* Disk usage statistics

When WordPress content changes, affected cache entries can be invalidated automatically.

WooCommerce-sensitive pages and sessions are automatically excluded where required.

= Object, WP_Query and Database Caching =

WP Optimizer provides multiple optional caching layers.

**Object Cache**

Install a WordPress `object-cache.php` drop-in and use Redis or Memcached when a compatible PHP extension and service are available.

**WP_Query Cache**

Cache selected WordPress query results with configurable lifetimes and invalidation behavior.

**Database Query Cache**

Cache eligible database responses and invalidate related entries when WordPress content changes.

Each cache layer can be configured independently.

Redis or Memcached is **not required** to use WP Optimizer's page cache, browser cache, minification, compression, image optimization or database tools.

= HTML, CSS and JavaScript Optimization =

WP Optimizer can optimize:

* Rendered HTML
* Inline CSS
* Inline JavaScript
* Local CSS files
* Local JavaScript files

Generated optimized assets are cached on disk.

Already-minified resources are skipped when possible, relative CSS paths are preserved, and compatibility safeguards prevent known page-builder editing and preview requests from being transformed.

CSS and JavaScript combination is optional.

For best compatibility, enable HTML, CSS and JavaScript optimization separately and test the site after each change.

= Image Optimization and WebP =

Optimize WordPress images locally without requiring an external image optimization service.

WP Optimizer can:

* Compress Media Library images
* Compress generated WordPress thumbnails
* Convert supported images to WebP
* Resize oversized images
* Preserve EXIF metadata when requested
* Optimize new uploads automatically
* Process larger libraries in background batches
* Add missing image width and height attributes when metadata is available
* Lazy load images
* Identify potentially unused media files

Image processing uses **Imagick or GD on your own server**.

= Core Web Vitals Optimization =

WP Optimizer includes multiple optimizations that can improve the technical factors behind **Largest Contentful Paint (LCP), Cumulative Layout Shift (CLS) and Interaction to Next Paint (INP)**.

These include:

* Page caching
* Browser caching
* GZIP/Brotli compression
* HTML/CSS/JavaScript minification
* Image compression
* WebP conversion
* Image and iframe lazy loading
* Missing image dimension fixes
* LCP image preloading
* Font display optimization
* Font stylesheet optimization
* Navigation prefetching
* Reduced unnecessary WordPress output

Actual Core Web Vitals and PageSpeed results depend on your theme, plugins, hosting, content, third-party scripts and configuration.

WP Optimizer does not promise a specific PageSpeed score. Use Page Test, Performance Monitor and external testing tools to verify the result on your own site.

= Database Cleanup and Autoload Health =

A slow WordPress database can increase backend and frontend response times.

WP Optimizer includes tools to inspect and maintain your WordPress database.

Clean or manage:

* Post revisions
* Auto drafts
* Trashed posts
* Spam comments
* Transients
* Orphaned metadata
* Database tables
* Large `wp_options` entries
* Autoloaded options

The database module can also:

* Optimize selected tables
* Create SQL backups
* Restore SQL backups
* Inspect option size
* Inspect autoload status
* Disable autoload for selected options
* Delete selected options

**DB Media Optimization** is disabled by default and appears above Sweeper in the database settings. Enabling it moves `_wp_attached_file` and `_wp_attachment_metadata` from the site's postmeta table to its media metadata table, using the configured database prefix.

Existing table structures and indexes are preserved. A separate rollback journal retains metadata IDs and duplicate rows. Disabling the feature moves the data back to postmeta, including changes made while it was enabled. Migration requires InnoDB tables and stops if conflicting copies or incompatible data would prevent a safe transfer.

Enabling or disabling this feature may be slow on large databases. Wait for the migration to finish.

Create a database backup before changing or deleting data you are not familiar with.

= Media Cleaner =

The Media Cleaner can scan the WordPress Media Library or a selected uploads directory for files that do not appear to be referenced by WordPress content.

Detected files can be:

* Reviewed
* Ignored
* Deleted

Always review detected files before deleting them. Themes, plugins and custom code can reference media in ways that WordPress cannot automatically discover.

= WooCommerce Compatibility =

WP Optimizer includes automatic safeguards for WooCommerce.

Sensitive WooCommerce requests such as:

* Cart
* Checkout
* My Account
* WooCommerce sessions
* Cart actions
* Relevant API requests

are automatically excluded from incompatible caching and runtime HTML optimization.

Content changes can automatically invalidate affected cache entries.

= Page Builder Compatibility =

WP Optimizer detects editing and preview requests from supported page builders, including:

* Elementor
* Beaver Builder
* Divi
* Gutenberg
* Bricks
* Oxygen
* Breakdance

Editing and preview requests bypass incompatible caching and output transformations, while sensitive generated builder assets and markup are protected from minification where required.

= Apache, Nginx, LiteSpeed and OpenLiteSpeed =

WP Optimizer detects and supports multiple common WordPress server environments.

**Apache and LiteSpeed Enterprise**

Managed server rules can be written to the local `.htaccess` file.

**Nginx**

WP Optimizer generates an Nginx configuration file. The generated configuration must be included in the website server block and Nginx must then be reloaded.

**OpenLiteSpeed**

WP Optimizer can generate compatible rewrite rules for direct cache delivery, redirects and rewrite-based security controls.

Enable **Auto Load from .htaccess** for the appropriate virtual host.

Compression, response headers, MIME types and other non-rewrite server settings should be configured through OpenLiteSpeed WebAdmin where required.

= Cloudflare Integration =

WP Optimizer can optionally integrate with Cloudflare using a configured API token and Zone ID.

The integration can:

* Test Cloudflare credentials
* Purge cached content by host
* Purge cache tags
* Purge the complete zone cache
* Automatically purge the configured Cloudflare cache when the local WP Optimizer page cache is cleared

Cloudflare is optional and is not required for WP Optimizer's local optimization features.

= Safer Performance Testing and Recovery =

Performance settings can interact differently with every combination of theme, plugins, server and hosting configuration.

WP Optimizer therefore includes configuration backup and recovery tools.

Before the main settings are changed, WP Optimizer can create an automatic configuration snapshot.

The latest configuration backups can be reviewed, restored or deleted from the Settings module.

If a fatal error is caused by WP Optimizer or by a WP Optimizer-managed cache drop-in, the recovery system can attempt to:

* Restore a previous working configuration
* Remove plugin-managed cache drop-ins
* Remove generated server rules
* Clear generated cache data
* Remove minified resources
* Remove direct-cache files
* Reset scheduled optimization tasks
* Reset WP Optimizer to factory settings

Recovery actions do not reset unrelated themes, plugins or server configuration.

= More WordPress Maintenance Tools =

Performance is the main focus, but WP Optimizer also provides optional maintenance and administration modules.

These include:

**Cron & Heartbeat**

* Inspect WordPress cron events
* Create, edit, run or delete cron events
* Manage custom schedules
* Reduce unnecessary frontend cron checks
* Configure the wp-admin Heartbeat interval
* Schedule automatic database and media optimization

**WordPress Cleanup**

Optionally disable unnecessary WordPress output or functionality such as:

* Emojis
* XML-RPC
* Feeds
* oEmbed
* Selected REST access
* Shortlinks
* Relational links
* WordPress sitemap
* jQuery Migrate
* Dashicons
* Global Styles
* Widgets
* Comments
* Selected Block Editor functionality

**Security Hardening**

Optional WordPress and server hardening controls include:

* Directory listing protection
* HTTP TRACE protection
* Sensitive configuration-file protection
* HSTS
* MIME-sniffing protection
* Referrer policy
* Frame protection
* Reduced WordPress/server version disclosure
* Basic user-enumeration protection
* WordPress file-editor disabling

**Activity Log**

Record selected:

* User actions
* Post actions
* Term actions
* Attachment changes
* Option changes
* Plugin actions

Optional suspicious-request monitoring can detect patterns commonly associated with XSS, SQL injection, path traversal, command injection and sensitive-file probes.

**SMTP & Mail Logging**

Configure WordPress SMTP transport and optionally record outgoing email for troubleshooting.

**WordPress Update Controls**

Control WordPress core and plugin update checks, automatic updates, update notices and related notification emails.

**System Information**

Inspect WordPress, PHP, database, web server, filesystem and storage information from one dashboard.

= Local-First and Modular =

WP Optimizer does not require a mandatory optimization SaaS or subscription for its core features.

Page caching, minification, image optimization, WebP conversion, database optimization, diagnostics and performance monitoring can run on your own WordPress server.

Optional integrations such as Cloudflare only communicate with external services when you configure and use those integrations.

Optional error-reporting or telemetry functionality can be controlled separately.

Most importantly, **you do not have to enable everything**.

If you already have a preferred cache plugin, CDN or another optimization tool, disable overlapping WP Optimizer modules and use only the features that complement your existing stack.

Do not let two plugins manage the same optimization layer at the same time, especially:

* Page cache
* Object cache
* Database/query cache
* HTML/CSS/JavaScript minification
* Asset combination
* Generated server rules

= Recommended Setup =

For an existing or production WordPress site:

1. Open **WP Optimizer → WP Info** and review your server environment.
2. Create a database backup.
3. Start with browser caching and supported compression.
4. Configure image optimization and WebP.
5. Enable the WP Optimizer schedule if you want automatic image or database maintenance.
6. Enable page cache.
7. Clear the cache and test important pages.
8. Enable HTML minification and test again.
9. Enable CSS optimization and test again.
10. Enable JavaScript optimization and test again.
11. Test forms, search, login, WooCommerce cart/account/checkout and other dynamic pages.
12. Run **Page Test** to compare the result.
13. Use **Performance Monitor** if you still have slow requests, plugins or database queries.

Avoid enabling multiple aggressive optimization options simultaneously on an existing production site.

= Free Performance Features – No Premium-Locked Modules =

WP Optimizer is free and the performance modules described on this page are not locked behind a premium upgrade.

That includes:

* Static page cache
* Browser caching
* GZIP/Brotli configuration
* HTML/CSS/JavaScript minification
* Lazy loading
* LCP optimization
* Font optimization
* Local image compression
* WebP conversion
* WP_Query cache
* Database query cache
* Redis/Memcached object-cache integration
* Database cleanup
* Autoload analysis
* Cron management
* Page Test
* Performance Monitor
* Cloudflare cache purging
* Configuration backups and recovery

Use one plugin as a complete optimization toolkit, or enable only the individual modules your site needs.

== Screenshots ==

1. WP Optimizer dashboard – enable only the performance, maintenance and diagnostic modules your WordPress site needs.
2. Page Test – compare baseline and optimized requests using response time, TTFB, memory usage and response size.
3. Performance Monitor – find slow plugins, database queries, callbacks, cache misses and high-memory requests.
4. Static Page Cache – configure cache rules, exclusions, lifetimes, automatic purging and cache statistics.
5. HTML, CSS and JavaScript optimization – configure minification and generated asset caching.
6. Image Optimization and WebP – compress Media Library images locally, convert to WebP and process background jobs.
7. Database and Autoload Health – clean WordPress data, inspect large autoloaded options and manage database maintenance.
8. Cron Manager – inspect, run, edit and manage WordPress scheduled events.
9. WP Info and server configuration – inspect WordPress, PHP, database and web-server information.
10. Configuration Backups and Recovery – restore previous settings or recover from an incompatible optimization configuration.

== Installation ==

1. Open **Plugins → Add New** in your WordPress dashboard.
2. Search for **WP Optimizer**.
3. Click **Install Now**.
4. Activate WP Optimizer.
5. Review the welcome screen.
6. Open **WP Optimizer** from the WordPress admin menu.
7. Enable only the modules you need.
8. Start with lower-risk optimizations such as browser caching, compression and image optimization before enabling more aggressive cache or minification settings.

For manual installation, upload the WP Optimizer folder to `/wp-content/plugins/` and activate the plugin from **Plugins → Installed Plugins**.

For WordPress Multisite, install WP Optimizer from Network Admin and network activate it when you want the plugin available across the network.

== Frequently Asked Questions ==

= Is WP Optimizer free? =

Yes. WP Optimizer is free and the performance features described in this readme are not locked behind a premium subscription.

= What does WP Optimizer do? =

WP Optimizer is a modular WordPress performance plugin that combines page caching, minification, image optimization, WebP conversion, browser caching, compression, database optimization, Core Web Vitals tools and performance diagnostics.

It also includes optional maintenance, cron, security, mail and WordPress administration tools.

= Can WP Optimizer improve PageSpeed and Core Web Vitals? =

WP Optimizer can improve many technical factors measured by performance tools and Core Web Vitals, including caching, page weight, image delivery, compression, browser caching, layout stability and LCP resource priority.

A specific score cannot be guaranteed because performance also depends on your hosting, theme, plugins, content and third-party scripts.

Use Page Test and external performance tools to measure the result on your own website.

= Is WP Optimizer a cache plugin? =

Yes, but caching is only one part of WP Optimizer.

It includes static page cache, optional Redis/Memcached object cache, WP_Query cache and database query cache, together with minification, image optimization, database maintenance and performance diagnostics.

= How does the page cache work? =

WP Optimizer stores eligible rendered HTML pages on disk.

Depending on the server configuration, cached responses can be delivered using server-specific rules before a normal WordPress request finishes loading.

Cache lifetime, URL rules, exclusions, query strings, cookies, user agents and automatic purging can be configured independently.

= Do I need Redis or Memcached? =

No.

Redis or Memcached is only required if you choose to use the corresponding object-cache integration.

Static page cache, browser caching, compression, minification, WebP, image optimization, Page Test and the other core modules work without Redis or Memcached.

= Does WP Optimizer minify CSS and JavaScript? =

Yes.

WP Optimizer can minify HTML, CSS and JavaScript, including local CSS and JavaScript files. CSS and JavaScript combination is also optional.

Enable each optimization separately and verify important pages after changing asset optimization settings.

= Does WP Optimizer optimize images and create WebP files? =

Yes.

WP Optimizer can compress WordPress Media Library images and generated thumbnails locally using Imagick or GD and convert supported files to WebP.

Background processing can also be used for new uploads and larger media libraries.

= Does image optimization require an external API? =

No.

The built-in image optimization and WebP conversion run locally on your server using supported PHP image-processing libraries.

= What is Page Test? =

Page Test is a diagnostic tool that compares a request with WP Optimizer bypassed against the current active and warmed configuration.

It reports response time, TTFB, peak memory and response size and can provide diagnostic information about slow queries, repeated queries and expensive callbacks.

= What is Performance Monitor? =

Performance Monitor records configurable samples of WordPress requests and helps identify expensive plugins, theme code, database queries, callbacks and cache behavior.

It can measure load time, SQL time, query count and memory usage attributed to WordPress core, the active theme and installed plugins.

= Is WP Optimizer compatible with WooCommerce? =

Yes.

WooCommerce cart, checkout and account routes are automatically excluded from incompatible page caching and runtime HTML optimization.

Session cookies and relevant cart/API requests are also protected by built-in exclusions.

Always test your own checkout and account flows after changing performance settings.

= Is WP Optimizer compatible with Elementor and other page builders? =

WP Optimizer contains automatic safeguards for editing and preview requests from Elementor, Beaver Builder, Divi, Gutenberg, Bricks, Oxygen and Breakdance.

Page-builder editing requests bypass incompatible caching and transformations where required.

= Does WP Optimizer work with Apache, Nginx and LiteSpeed? =

Yes.

WP Optimizer supports Apache, Nginx, LiteSpeed Enterprise and OpenLiteSpeed.

Apache and LiteSpeed Enterprise can use managed `.htaccess` rules.

Nginx requires the generated configuration to be included in the website server block.

OpenLiteSpeed requires the corresponding rewrite and WebAdmin configuration where applicable.

= Can I use WP Optimizer with Cloudflare? =

Yes.

The optional Cloudflare integration can purge Cloudflare cache when WP Optimizer clears its local page cache.

A Cloudflare API token and Zone ID are required only when you use this integration.

= Can I use WP Optimizer with another cache or performance plugin? =

Yes, but do not enable the same optimization layer in multiple plugins.

For example, choose one plugin to manage page caching and one plugin to manage asset minification.

Duplicating page cache, object cache, database cache, minification or server-rule management can create conflicts or make performance worse.

= What should I enable first on a live website? =

Start with lower-risk optimizations such as browser caching, compression and image optimization.

Create a database backup before database cleanup.

Then enable page caching and HTML/CSS/JavaScript optimization one feature at a time, clear generated caches and test your site after each change.

= Can cache or minification break a website? =

Performance optimization changes how pages or assets are delivered, so incompatible theme or plugin behavior is possible.

WP Optimizer includes compatibility safeguards and configuration backups, but you should still enable aggressive options progressively and test important pages after each change.

If something goes wrong, disable the last option, clear the cache or restore an earlier WP Optimizer configuration.

= Are configuration backups automatic? =

WP Optimizer creates configuration snapshots before its main settings are changed.

Rapid consecutive saves can reuse a recent backup instead of producing unnecessary duplicates.

The newest configuration backups can be reviewed, restored or removed from the Settings module.

= What happens if an optimization causes a fatal error? =

If WP Optimizer itself or a WP Optimizer-managed cache drop-in causes a fatal error, the recovery service can try stored configurations or perform a controlled plugin reset.

The reset can remove plugin-managed cache files, generated rules, drop-ins, scheduled optimization tasks and generated optimization storage without resetting unrelated themes or plugins.

= Does WP Optimizer clean the WordPress database? =

Yes.

The Database module can remove unnecessary WordPress data, optimize selected tables, inspect autoloaded options and create or restore database backups.

= Does WP Optimizer support WordPress Multisite? =

Yes.

WP Optimizer supports Multisite and can be network activated.

Individual sites may still need different performance configurations depending on their theme, plugins and traffic.

= Is WP Optimizer privacy-friendly? =

Core optimization tasks such as page caching, image optimization, WebP conversion, minification and diagnostics can run on your own server.

Optional integrations or reporting functionality can communicate externally only when those features are configured or enabled.

= How do I exclude a page from cache? =

Open the Static Page Cache configuration and create an exclusion rule for the required URL or path.

Sensitive WooCommerce routes such as cart, checkout and account pages are excluded automatically.

= What happens to the cache when I update a post or product? =

When automatic purge is enabled, WordPress content-change hooks invalidate related page and query cache entries when relevant posts, products, terms or comments change.

If Cloudflare integration is enabled, the configured edge cache can also be purged.

= Can I export or restore WP Optimizer settings? =

Yes.

The Settings module supports configuration import, export, restore and reset operations.

Individual modules can also be reset independently.

= Is there an API for developers to clear WP Optimizer cache? =

Yes.

Integration code can check for and call:

`wpopt_flush_cache('integration-name')`

Bulk processes can also use:

`wpopt_suspend_cache_auto_purge()`

and:

`wpopt_resume_cache_auto_purge()`

See `EXTERNAL-API.md` for supported integration functions and examples.

= How do I completely uninstall WP Optimizer? =

For the cleanest removal, reset active modules first so WP Optimizer can remove managed cache drop-ins, generated storage and local server rules.

Then deactivate and delete WP Optimizer from the WordPress Plugins screen.

The uninstall routine removes plugin options, scheduled media hooks and plugin database tables. In Multisite installations this cleanup applies across the network where appropriate.

== Upgrade Notice ==

= 2.9.0 =

Recommended update with compatibility improvements, Multisite enhancements, performance refinements and bug fixes.

== Changelog ==

= 2.9.0 =

* Added DB Media Optimization.
* Improved LCP image learning by page and viewport, early preloads and image exclusions.
* Added optional JavaScript defer with WordPress dependency checks and idle delay for selected background scripts.
* Improved the settings layout for multiline fields.
* Improved the admin UI and UX.

= 2.8.9 =

* fixed invalid directory-listing syntax that could make LiteSpeed reject the generated `.htaccess`
* refreshes the managed security-rule block during upgrade

= 2.8.8 =

* Added plugin compatibility headers.
* Improved WP Optimizer core performance.
* Improved WordPress Multisite behavior and administration.
* Updated documentation.
* Fixed reported bugs.

= 2.8.6 =

* Added automatic WooCommerce compatibility safeguards.
* Added automatic page-builder editing and preview safeguards.
* Hardened WooCommerce session, cart-action and database-cache exclusions.
* Made direct-cache migration fail-safe when runtime configuration is missing or invalid.
* Removed unused legacy implementations.

= 2.8.4 =

* Added dedicated database tables for Performance Monitor data and cache data.
* Improved internal performance.
* Removed legacy fallbacks.
* Fixed reported bugs.

= 2.8.2 =

* Added automatic configuration backups and restore tools.
* Added error handling and recovery functionality.
* Added the Page Test performance comparison tool.
* Added the welcome page.
* Improved cache configuration documentation and diagnostics.
* Improved the admin UI and UX.

= 2.8.1 =

* Fixed reported bugs.
* Improved translations.

= 2.8.0 =

* Added the PageSpeed module.
* Updated the WP Optimizer admin UI and UX.
* Improved plugin core performance.
* Fixed reported bugs.

= 2.7.1 =

* Added the Cron module for managing WordPress scheduled tasks.
* Added support for WordPress 7.0.
* Improved plugin and module initialization.
* Added lazy dependency loading to reduce unnecessary work during requests.
* Updated the admin UI and UX.

= 2.6.5 =

* Added SQL monitoring to Performance Monitor.
* Added cache hit and miss monitoring.
* Added plugin execution-time monitoring.
* Added plugin memory-footprint monitoring.
* Improved core security hardening.

= 2.6.0 =

* Fixed the Activity Log authenticated SQL injection vulnerability (CVE-2026-6295) and hardened equivalent WP Mail search handling.
* Added mandatory nonce validation and admin-only execution for sensitive request actions.
* Hardened settings import, database action arguments and `wpsargs` parsing against unsafe deserialization.
* Hardened excluded-table handling for database backups using `mysqldump`.
* Limited WP Mail message previews in the table and added a popup for viewing complete messages.

= 2.5.0 =

* Added Performance Monitor.
* Fixed Activity Log issues.
* Fixed an ImageProcessor issue when deleting images.
* Improved the admin UI and UX.
* Updated translations.

= 2.4.0 =

* Added additional information and guidance to plugin modules.
* Improved performance.
* Fixed issues in the Minify modules.
* Fixed issues in cache modules.
* Improved the admin UI and UX.

= 2.3.8 =

* Updated translations.
* Added support for WordPress 6.9.
* Improved Media Cleaner.

= 2.3.7 =

* Updated translations.
* Added support for WordPress 6.8.
* Fixed compatibility issues on older PHP environments.

= 2.3.5 =

* Improved WP Optimizer core performance.
* Updated translations.
* Added support for WordPress 6.7.

= 2.3.4 =

* Added configurable SMTP transport and WordPress mail logging.
* Added the welcome page shown after plugin activation.
* Improved ImageProcessor.
* Improved plugin core performance.
* Improved uninstall cleanup.
* Updated the admin UI.
* Updated translations.
* Fixed compatibility issues.

= 2.2.5 =

* Added WordPress font optimization support.
* Added `blueprint.json` for WordPress Preview.
* Improved plugin core performance.
* Updated translations.
* Added support for WordPress 6.5.

= 2.2.2 =

* Improved Media Cleaner scanning.
* Improved plugin core performance.
* Improved Gutenberg-disable functionality.
* Improved Activity Log.
* Added support for WordPress 6.4.
