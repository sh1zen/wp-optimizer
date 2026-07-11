=== WP Optimizer – PageSpeed, Cache, Minify, Image Optimization & Core Web Vitals ===
Contributors: sh1zen
Tags: performance, cache, core web vitals, image optimization, minify
Donate link: https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU
Requires at least: 5.0.0
Tested up to: 7.0.0
Requires PHP: 7.4
Stable tag: 2.8.5
License: GPLv2 or later

All-in-one speed optimization plugin: cache, lazy load, minify, LCP preload, WebP media optimization & Core Web Vitals. Free & privacy safe.

== Description ==

Make WordPress faster, lighter and easier to maintain from one modular control center.

WP Optimizer combines page caching, object caching, HTML/CSS/JavaScript minification, image optimization, WebP conversion, database maintenance, PageSpeed tools and performance diagnostics in a single plugin.

Use it as a complete WordPress optimization solution or enable only the modules your website needs. Disabled modules stay out of the way, so you can avoid overlapping features and keep full control of your configuration.

WP Optimizer works with Apache and Nginx, supports WordPress Multisite and performs optimization tasks directly on your server.

No required subscription. No mandatory cloud service.

= Core features =

* **Static Page Cache:** Store rendered HTML pages and serve repeat requests without rebuilding the complete WordPress response. Configure cache lifespan, URL rules, query-string behavior, cookies, user agents and automatic purging.

* **Direct Cache Delivery:** Route eligible static-cache requests before WordPress loads. WP Optimizer can generate the required rules for Apache and Nginx, reducing PHP and database work on cached visits.

* **Object Cache:** Use the WordPress object-cache integration with Redis or Memcached when a compatible service is available on the server.

* **WP_Query Cache:** Cache selected WordPress query results, filter them by query type and automatically purge entries when related content changes.

* **Database Query Cache:** Store selected database-query results, restrict caching to specific tables and invalidate entries according to database changes.

* **HTML, CSS and JavaScript Minification:** Reduce front-end file and response size by removing unnecessary characters. Each asset type can be enabled and tested separately.

* **Compression and Browser Cache:** Generate Apache or Nginx rules for GZIP compression, optional Brotli directives and browser cache policies that reduce transfer size and repeated downloads.

* **Image Optimization and WebP:** Optimize WordPress images, convert supported files to WebP and process new uploads automatically without requiring an external image-optimization subscription.

* **Lazy Loading and LCP Preload:** Improve resource delivery by deferring supported offscreen content and preloading important resources related to Largest Contentful Paint.

* **PageSpeed Tools:** Apply coordinated front-end transformations and server optimizations designed to improve loading performance and support better Core Web Vitals.

* **Page Test:** Compare a real page with and without the active WP Optimizer configuration using response time, TTFB, peak memory and response size.

* **Performance Monitor:** Inspect slow requests, database queries, WordPress hooks, callbacks and memory use to find where execution time is being spent.

* **Database Maintenance:** Clean and optimize WordPress tables, create database backups, remove orphaned data and inspect heavy autoloaded options.

* **Cron Manager:** Review WordPress cron events and custom schedules, manage scheduled tasks and optimize cron execution.

* **Activity and Request Monitoring:** Record relevant user, post and term activity, monitor suspicious requests and define custom monitoring rules.

* **WordPress and Server Controls:** Apply selected hardening rules, manage WordPress updates, customize admin behavior and disable features the website does not need.

* **SMTP and Mail Logging:** Configure outgoing WordPress email and keep a log that helps diagnose mail-delivery problems.

* **Configuration Backups and Recovery:** Automatically protect settings changes, restore previous configurations and recover from fatal errors related to WP Optimizer.

= Multiple cache layers, one control center =

Different WordPress requests benefit from different types of caching. WP Optimizer therefore provides independent cache layers instead of placing every request behind one global switch.

The static page cache stores complete front-end responses. Object caching reduces repeated WordPress data lookups, while the WP_Query and database-query caches target selected expensive query results.

Available cache controls include:

* Cache lifespan
* Query-argument handling
* URL inclusion and exclusion rules
* User-agent exclusions
* No-cache cookies
* Administrative-request behavior
* WP_Query type filtering
* Database table filtering
* Content-change purging
* Dependency-aware invalidation

Cache reports show hits, misses, writes, disk usage and hit ratio. This gives you real information about cache activity and helps identify where configuration changes are useful.

When a cache module or direct-access option is disabled, WP Optimizer removes the related managed files, drop-ins, server rules and scheduled cleanup tasks.

= Apache and Nginx support =

WP Optimizer is designed for both Apache and Nginx web servers.

On compatible Apache installations, the plugin can manage optimization directives through the local `.htaccess` file.

For Nginx websites, WP Optimizer generates a dedicated configuration file. Add this file to the website's Nginx server block and reload Nginx after changing server-level settings.

Generated Apache and Nginx rules can cover:

* Direct static-cache routing
* Browser cache headers
* GZIP compression
* MIME type configuration
* Server enhancements
* Security and access restrictions
* Page Test cache-bypass behavior

Optional Brotli and PageSpeed directives are kept commented when enabling them automatically could cause errors on servers where the corresponding modules are not installed.

This approach provides native rules for each supported server without treating Nginx as an Apache installation.

= Image optimization without a mandatory cloud service =

WP Optimizer processes images on your own server.

The Media module can:

* Optimize image files
* Convert supported images to WebP
* Process new uploads automatically
* Scan the WordPress media library
* Scan a selected filesystem path
* Optimize generated thumbnails
* Run larger jobs in the background
* Help identify and clean unused media

Background processing divides large workloads into smaller operations. This helps image optimization continue on hosting environments with limited PHP execution time.

Because processing remains local, the core media workflow does not require sending images to a third-party optimization platform.

= Minify WordPress pages and assets =

The Minify module reduces the size of HTML responses and local CSS or JavaScript resources.

HTML, CSS and JavaScript minification can be activated independently. This allows you to test one optimization at a time and add exclusions where required by a theme, page builder or plugin.

Runtime HTML transformations use the shared WPS output-buffer service. PageSpeed transformations run in a defined order before final HTML minification, avoiding multiple independent output buffers competing for the same response.

= Improve Core Web Vitals with a controlled workflow =

WP Optimizer combines caching, minification, media optimization, compression, browser caching, lazy loading and LCP preloading to address the technical areas that influence page speed and Core Web Vitals.

The final result also depends on the hosting environment, active theme, page content, third-party scripts and installed plugins. For this reason, WP Optimizer does not rely on a universal configuration or promise a fixed performance score.

Instead, it gives you the tools to apply changes gradually, measure the result and keep only the optimizations that benefit the website.

= Compare performance before and after optimization =

The integrated Page Test measures the effect of WP Optimizer on a selected URL.

It performs a four-step browser-based workflow:

1. A signed baseline request with WP Optimizer modules and direct server cache bypassed.
2. A clean request using the current active configuration.
3. A diagnostic warmup that collects data and populates available caches.
4. A measured request using the warmed active configuration.

The final comparison includes:

* Response time
* Time to First Byte
* Peak memory usage
* Response size

Warmup diagnostics can reveal:

* Slow database queries
* Repeated queries
* Expensive WordPress hooks
* Callback samples
* Query totals
* Memory totals

This makes it easier to determine whether caching, minification or PageSpeed settings are helping the tested page or introducing additional overhead.

= Find slow WordPress requests =

The Performance Monitor records request history and presents slow executions through reports and charts.

Diagnostic data can help identify performance costs associated with:

* WordPress core
* The active theme
* Installed plugins
* Database queries
* Hooks and callbacks
* Memory consumption
* The current WP Optimizer configuration

Instead of showing only a general speed score, the monitor exposes activity from the WordPress request itself.

= Clean and inspect the WordPress database =

The Database module provides maintenance tools for data that can accumulate as a website changes over time.

Available operations include:

* Database cleanup
* Table optimization
* Database backups
* Orphaned-data cleanup
* Guarded maintenance actions
* `wp_options` inspection
* Autoload-size analysis

The options browser helps identify large autoloaded values that may consume memory on every WordPress request.

Create a database backup before running more aggressive cleanup operations to maintain a safer production workflow.

= Manage WordPress cron events =

The Cron Handler provides an administrative view of WordPress scheduled events and registered custom schedules.

Use it to:

* Review scheduled events
* Inspect recurring tasks
* Manage cron entries
* Check custom schedule intervals
* Identify obsolete scheduled work

Additional cron controls can reduce inefficient execution and help administrators understand which recurring tasks are registered on the website.

= Review WordPress and server information =

The WP Info module collects technical information about the current installation.

It provides visibility into:

* WordPress configuration
* PHP environment
* Database information
* Web server details
* Filesystem data
* Directory and storage usage

Dashboard widgets can also display server and folder-size information for quicker access to common diagnostics.

= Monitor activity and suspicious requests =

The Activity Log records relevant actions involving WordPress users, posts and terms.

Request monitoring provides additional visibility into suspicious traffic, including common XSS and SQL-injection probes. Custom rules can be added for installation-specific monitoring requirements.

These tools help with investigation and operational awareness without requiring every optimization module to be enabled.

= Apply WordPress and server hardening =

The WP Security module provides configurable hardening options for WordPress, Apache and Nginx environments.

Depending on the selected settings, generated rules can:

* Protect sensitive configuration files
* Restrict unwanted access
* Apply security-related response headers
* Reduce exposed server information
* Support suspicious-request monitoring

These features complement secure hosting, maintained WordPress software and reliable backups. They are not presented as a replacement for a complete security strategy.

= Control WordPress updates and admin features =

The WP Updates module controls update behavior for:

* WordPress core
* Plugins
* Themes

The WP Customizer module can adjust common WordPress behavior without editing core or theme files.

Available controls include:

* Restricting dashboard access
* Hiding the WordPress Admin Bar
* Disabling comments
* Controlling the Block Editor and related features
* Removing selected dashboard panels
* Managing sitemap, short-link and self-ping behavior
* Disabling selected non-essential WordPress output
* Simplifying the administration interface

These options allow WordPress to be adapted to the real requirements of the website.

= Configure SMTP and log WordPress email =

The WP Mail module provides SMTP settings and outgoing email logs.

Mail logging helps verify whether WordPress attempted to send a message and provides useful information when investigating contact forms, notifications, password resets and other email-delivery issues.

= Automatic configuration backups =

WP Optimizer creates a configuration backup before updating its main settings.

To avoid redundant snapshots during rapid autosaves, backup creation is skipped when the latest snapshot is less than 15 minutes old. The newest 50 backups are retained automatically.

Administrators can:

* Review saved configurations
* Restore a previous configuration
* Delete obsolete backups
* Import settings
* Export settings
* Reset an individual module

Restoring a backup runs the normal plugin configuration lifecycle so modules, managed cache drop-ins and generated server rules remain synchronized with the restored settings.

= Recovery from WP Optimizer errors =

WP Optimizer registers a recovery service early during plugin startup.

If a fatal error originates from WP Optimizer or from a plugin-managed `object-cache.php` or `db.php` drop-in, administrators can try saved configurations one by one or perform a controlled factory reset.

The reset process can remove:

* Plugin-managed cache drop-ins
* Generated Apache or Nginx rules
* Static cache data
* Minified resources
* Direct-cache runtime files
* Scheduled optimization tasks

Recovery is intentionally limited to WP Optimizer-related failures. It does not automatically reset unrelated themes, plugins or server configuration.

= Works with your WordPress installation =

* **WordPress:** Version 5.0 or later
* **PHP:** Version 7.4 or later
* **Web servers:** Apache and Nginx
* **Cache services:** Redis and Memcached when available
* **Networks:** WordPress Multisite support
* **Configuration:** Modular features with independent activation
* **Processing:** Local server-side optimization
* **Telemetry:** Optional controls available

Themes, page builders, membership systems, e-commerce plugins and personalized content may require cache or minification exclusions. Avoid running two plugins that perform the same page-cache, object-cache or minification task at the same time.

= Included modules =

* **Activity Log:** Records relevant user, post and term activity.
* **Cache:** Manages static, object, WP_Query and database-query caching.
* **Cron Handler:** Displays and manages WordPress scheduled events.
* **Database:** Provides cleanup, backup, optimization and autoload inspection.
* **Media:** Optimizes images, generates WebP files and manages media processing.
* **Minify:** Reduces HTML, CSS and JavaScript size.
* **Performance Monitor:** Analyzes requests, queries, hooks and memory usage.
* **PageSpeed:** Improves front-end loading and resource delivery.
* **Settings:** Manages backups, imports, exports, restores and module resets.
* **Widget:** Adds dashboard information widgets.
* **WP Customizer:** Controls WordPress features and admin behavior.
* **WP Info:** Displays WordPress, PHP, database and server diagnostics.
* **WP Mail:** Configures SMTP and records outgoing email.
* **WP Optimizer:** Manages compression, browser caching and server enhancements.
* **WP Security:** Provides request monitoring and hardening controls.
* **WP Updates:** Controls WordPress core, plugin and theme updates.

= Recommended first setup =

For an existing or production website:

1. Review the environment information in WP Info.
2. Enable browser caching and supported compression.
3. Configure image optimization for new uploads.
4. Create a database backup before running cleanup tasks.
5. Enable static page caching and test dynamic pages.
6. Configure direct cache delivery after verifying the standard cache.
7. Enable additional cache layers only when supported by the server.
8. Activate HTML, CSS and JavaScript minification separately.
9. Clear generated caches after important configuration changes.
10. Test forms, search, login areas and other dynamic workflows.
11. Use Page Test to compare the active configuration with the bypass baseline.
12. Review Performance Monitor data for slow requests or unexpected overhead.

WP Optimizer gives you a complete, measurable and modular way to make WordPress faster.

Use the tools your website needs, keep processing under your control and build an optimization setup that works with your server—not against it.

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

== Changelog ==

= 2.8.5 =

* improved core performances
* fixed some bugs
* updated translations

= 2.8.4 =

* added dedicated db tables to performances monitor and cache
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
* limited WP Mail message preview in the table and added popup view for full content

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

= 2.1.5 =

* added support to disable oembed and rest
* improved activity log module
* updated translations file
* extended support to WordPress 6.3

= 2.1.2 =

* added runtime actualization of imported settings
* improved settings reset
* updated some options
* fixed a bug where no active modules where found in same cases

= 2.1.0 =

* added a new module to register WordPress activity log and bad requests
* added possibility to hash versioned styles and scripts
* added auto-clean cache on interval to reduce space used especially for static caching method
* added possibility to try to fix plugin settings, without fully reset settings
* improved core performances
* fixed some bugs

= 2.0.0 =

* added object caching
* added support to Redis, Memcached
* added behaviour info for each setting
* improved browser-caching
* improved core performances
* extended support to WordPress 6.2
* extended support to PHP 8.2
* updated faqs
* updated UI/UX
* updated translations file
* fixed .htaccess bug
* moved minimum WordPress support to version 4.6.0
