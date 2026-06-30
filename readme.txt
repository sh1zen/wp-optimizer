=== WP Optimizer – Cache, Minify, Image Optimization, Core Web Vitals ===
Contributors: sh1zen
Tags: cache, performance, core web vitals, image optimization, minify
Donate link: https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU
Requires at least: 5.0.0
Tested up to: 7.0.0
Requires PHP: 7.4
Stable tag: 2.8.4
License: GPLv2 or later

Improve WordPress speed, Core Web Vitals and technical SEO with cache, minify, WebP media optimization, database cleanup, security and diagnostics.

== Description ==

WP Optimizer is a modular WordPress performance and maintenance toolkit built to make your site faster, cleaner and easier to manage.

Instead of installing separate plugins for cache, minification, image optimization, database cleanup, security hardening, diagnostics, mail logging and admin cleanup, WP Optimizer brings them together in one lightweight, modular dashboard.

Enable only the modules you need. Disabled modules stay out of the way, helping you keep your WordPress installation lean and focused.

WP Optimizer helps improve page speed, reduce unnecessary page weight, support better Core Web Vitals, maintain your database, monitor performance issues and apply safer WordPress defaults.

All optimization tasks run on your own server. No subscription is required.

= Why use WP Optimizer? =

* All-in-one WordPress optimization workflow from a single dashboard
* Modular architecture: activate only the tools your site actually needs
* Built for speed: cache, minify, compression, browser caching, media optimization and cron tuning
* Better technical SEO through faster pages, cleaner output and optimized assets
* Lower page weight with HTML, CSS and JavaScript minification
* Image optimization and WebP conversion to reduce bandwidth usage
* Database cleanup and optimization to keep your site lean
* Security hardening and activity logs to reduce risk and improve visibility
* Server and WordPress diagnostics to help troubleshoot faster
* Page Test workflow to compare the current configuration against a WP Optimizer bypass baseline
* Automatic configuration backups before plugin settings changes
* Recovery mode for WP Optimizer-related fatal errors, with backup restore and factory reset options
* Admin cleanup and WordPress feature toggles without editing theme or core files
* Multisite support
* Optional telemetry controls
* No subscription required

= What WP Optimizer does =

WP Optimizer focuses on four areas:

1. Speed and Core Web Vitals
   Improve loading performance with caching, minification, compression, browser cache policies, media optimization and smarter cron behavior.

2. Technical SEO and cleaner output
   Reduce unnecessary WordPress output, optimize assets and improve the technical foundation that search engines and users experience.

3. Maintenance and reliability
   Clean and optimize the database, manage settings, monitor slow requests, review server information and keep your WordPress installation easier to maintain.

4. Security and admin control
   Harden common WordPress defaults, monitor suspicious requests, control update behavior and customize the admin experience.

= Performance features =

* Static page caching for rendered front-end pages
* Static cache direct access through generated Apache/Nginx rules, allowing eligible cache hits to bypass WordPress
* Object caching with Redis and Memcached support
* WP_Query caching for expensive WordPress query results
* Database query caching for selected database tables
* Dedicated WP_Query and database cache configuration for lifespan, query-argument handling, purge behavior, admin requests, user-agent exclusions, no-cache cookies, WP_Query type filtering, database table filtering and dependency-aware WP_Query auto purge; regex rules remain available for static cache
* Admin-side cache exclusion, enabled by default
* HTML, CSS and JavaScript minification
* Server-side compression with GZIP and Brotli
* Browser cache lifetime controls
* WordPress cron optimization
* Server .htaccess enhancements
* PageSpeed module
* Performance monitor with request history, charts and slow-request visibility
* Page Test tool with disabled-module and direct/server-cache bypass baseline, empty active-configuration scan, active-configuration warmup diagnostics and signed active-configuration measurement

= Cache system =

WP Optimizer includes several cache layers that can be enabled independently:

* Static page cache stores rendered front-end HTML and can optionally serve eligible requests through generated server rules before WordPress loads.
* Object cache stores WordPress object cache data through the bundled cache integration, with Redis and Memcached support when available.
* WP_Query cache stores selected WordPress query results and can automatically purge entries when related content changes.
* Database query cache stores selected database query results and can limit caching to configured database tables.

Each cache layer exposes its own configuration instead of using one global switch for every request. You can control cache lifetime, query-argument handling, content-change purge behavior, admin-request behavior where supported, user-agent exclusions and no-cache cookies.

Static cache supports include and exclude regex rules for request paths. WP_Query cache can be limited by query type. Database query cache can be limited by database table. Rule reports show activity, disk usage, hits, misses, writes and hit ratio so you can tune cache coverage without guessing.

When direct static access is enabled, WP Optimizer writes the required local server rules and runtime files. Disabling or resetting the cache module removes the plugin-managed drop-ins, generated direct-access files and scheduled cache cleanup hooks.

= Page Test =

The Page Test tool helps validate real page behavior from the WordPress admin before and after enabling optimization options.

For a selected URL, the test prepares signed requests and runs a four-step browser-based workflow:

1. A baseline request with WP Optimizer modules bypassed and direct/server cache bypass headers applied.
2. A scan of the current active configuration without diagnostics, used to compare a clean active run.
3. A warmup request with the active configuration, used to collect diagnostics and populate caches.
4. A signed active-configuration measurement.

The comparison reports response time, TTFB, memory peak and response size against the baseline. The warmup diagnostics can surface slow queries, repeated queries, heavier hooks, callback samples, memory totals and query totals. This makes the tool useful for checking whether cache, minify or PageSpeed settings are helping the page or introducing overhead.

= Media optimization =

WP Optimizer includes tools to reduce media weight and keep your uploads cleaner:

* Image optimization
* Image conversion with WebP support
* Automatic image optimization in background
* Unused media cleanup
* Processing designed to work even on servers with limited PHP execution time

= Database cleanup and maintenance =

Keep your WordPress database cleaner and easier to maintain:

* Database cleanup
* Database optimization
* Database backup utilities
* wp_options browser with autoload size review and guarded actions
* Cleanup of orphaned and unnecessary WordPress data
* Safer maintenance workflow before aggressive cleanup tasks

= Security and activity monitoring =

WP Optimizer includes practical security and monitoring tools:

* WordPress activity log for users, posts and terms
* Suspicious request monitoring, including XSS and SQL injection probes
* Custom rule monitoring
* WordPress and server-level hardening options
* Update controls for WordPress core, plugins and themes

= Diagnostics and admin tools =

WP Optimizer also helps you understand and manage your WordPress installation:

* Full diagnostic information about WordPress, PHP, database and server environment
* Dashboard widgets for folder size and server information
* Mail logging
* SMTP configuration
* Global settings management
* Settings reset, import, export, restore and autosave
* Automatic configuration backup list with restore and delete actions
* Admin cleanup and WordPress behavior customization
* Browser-based Page Test against the current site configuration, including an empty active-configuration scan before warmup hints for slow queries, repeated queries, heavier hooks and callback samples

= Configuration backups and recovery =

WP Optimizer creates automatic configuration backups before changes are written to the main plugin settings option. To avoid storing a new snapshot for every rapid autosave, a new backup is skipped when the newest backup is less than 15 minutes old. WP Optimizer keeps the newest 50 configuration backups and removes older entries automatically.

Configuration backups are listed in the Settings module. Administrators can restore a previous configuration or delete obsolete backup entries. Restoring a backup reapplies the plugin configuration lifecycle so modules, cache drop-ins and generated server rules are synced with the restored settings.

WP Optimizer also registers a recovery service early during plugin bootstrap. If WordPress hits a fatal error caused by WP Optimizer code or by a WP Optimizer-managed `object-cache.php` or `db.php` drop-in, recovery mode stores the error and offers administrator actions from the WP Optimizer admin pages.

The Try Recover action attempts available configuration backups one by one until a request completes without the same fatal error. The Reset action restores WP Optimizer to factory settings, removes plugin-managed cache drop-ins and local server rules, clears generated cache/minify/direct-static storage and disables scheduled optimizer tasks. Recovery is intentionally limited to WP Optimizer-related fatal errors; unrelated theme, plugin or server failures are not reset automatically.

= WordPress customization =

Control common WordPress behavior without editing code:

* Prevent dashboard access for non-admin users
* Hide the Admin Bar
* Disable the Block Editor and related block features
* Disable comments
* Disable QuickPress, WordPress Blog and Welcome Panel
* Fast category list filter in the editor
* Disable sitemap, short-links, self-ping and other non-essential outputs

= Recommended first setup =

For a live site, start with lower-risk optimizations first:

1. Enable browser cache and compression.
2. Enable media optimization for new uploads.
3. Create a database backup before cleanup tasks.
4. Enable cache and minify one option at a time.
5. Clear cache and test the front end after each change.
6. Use Page Test to compare the active configuration against a bypass baseline.
7. Monitor requests and performance from the Performance Monitor and PageSpeed modules.

This gradual approach gives you better control and makes it easier to identify conflicts caused by aggressive cache or minification settings.

= Modules included =

* Activity Log
* Cache
* Cron Handler
* Database
* Media
* Minify
* Performance Monitor
* PageSpeed
* Settings
* Widget
* WP Customizer
* WP Info
* WP Mail
* WP Optimizer
* WP Security
* WP Updates

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

= 2.8.4 =

* added dedicated db tables to performances monitor and cache
* improved performances
* removed legacy fallbacks
* updated translations
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
