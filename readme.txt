=== WP Optimizer ===
Contributors: sh1zen
Tags: wordpress optimization, performance, cache, minify, image optimization
Donate link: https://www.paypal.com/donate?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR
Requires at least: 5.0.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.6.5
License: GPLv2 or later

WordPress performance optimization plugin with cache, minify, image optimization, database cleanup, security hardening and server tuning.

== Description ==

WP Optimizer improves WordPress speed, Core Web Vitals, and technical SEO with a modular toolkit.
Enable only what you need: cache, minification, image optimization, database cleanup, update controls, security hardening, and admin customization.
All optimizations run on your server.

**WHY USING WP-Optimizer?**

* **One plugin, full optimization workflow:** performance, maintenance, security, and admin cleanup from a single panel.
* **Modular architecture:** enable only the modules you need to keep resource usage low.
* **Built for real speed gains:** cache, minify, media optimization, cron tuning, and browser caching work together to reduce load time.
* **Safer WordPress defaults:** security and update modules help reduce attack surface and unwanted changes.
* **Easier site management:** logs, diagnostics, mail logging, and dashboard widgets help you monitor and troubleshoot faster.
* **Background processing:** long operations can run in background to avoid blocking admin work.
* **Multisite support.**
* **Privacy-friendly:** optimization runs on your server, with optional telemetry controls.
* **No subscription required.**

**BENEFITS**

* Better technical SEO through faster pages and cleaner WordPress output.
* Potentially higher search visibility from improved performance metrics.
* Lower page load times and better user engagement.
* Better stability during traffic spikes.
* Reduced bandwidth usage with optimized images and minified assets.
* Lower disk usage with media optimization and cleanup tools.

**FEATURES**

**WHAT WP OPTIMIZER DOES**

* Improves loading speed with caching, minification, compression, and smarter cron behavior.
* Reduces page weight through image optimization and cleanup of unnecessary WordPress output.
* Helps technical SEO with cleaner markup, better performance signals, and faster page delivery.
* Improves reliability with database maintenance, update controls, and server tuning.
* Adds visibility with activity logs, server info, mail logs, and admin dashboard widgets.
* Lets you customize WordPress behavior without editing theme or core files.

**MODULES INCLUDED (FULL LIST)**

* **Activity Log:** logs user/content actions and suspicious requests.
* **Cache:** object, query, database and static page caching.
* **Cron Handler:** centralizes scheduled jobs and cron-related module tasks.
* **Database:** database cleanup, optimization and backup utilities.
* **Media:** image optimization/conversion and unused media cleanup.
* **Minify:** HTML, CSS and JavaScript minification.
* **Performance Monitor:** request history with charts by request type and slow-request visibility.
* **Settings:** global settings management (reset, import/export, restore, autosave).
* **Tracking:** optional anonymous plugin usage/error telemetry controls.
* **Widget:** dashboard widgets for folder size and server information.
* **WP Customizer:** admin cleanup and WordPress feature toggles.
* **WP Info:** full diagnostic information about WordPress/server environment.
* **WP Mail:** SMTP configuration and mail logging.
* **WP Optimizer:** cron tuning, server enhancements, compression and browser cache policies.
* **WP Security:** WordPress and server-level hardening options.
* **WP Updates:** control over core/plugin/theme update behavior.
* **Modules Handler:** internal loader/manager for module lifecycle and upgrades.

* ***Activity Log:***
  * WordPress activity log for users, posts and terms
  * Monitor suspicious access attempts such as XSS and SQL injection probes
  * Supports custom rule monitoring

* ***Cache:***
  * Advanced caching system focused on speed and low storage footprint
  * Object Caching (Memcached and Redis)
  * WordPress Query Caching
  * Database Caching
  * Static Page Caching

* ***Database:***
  * Backup, optimize, and maintain database tables
  * Clean up orphaned and unnecessary WordPress data

* ***Media:***
  * Image optimization and conversion with WebP support
  * Media leftovers cleanup tool
  * Automatic image optimization in background
  * Compatible with servers with limited PHP execution time

* ***Server Info:***
  * Detailed diagnostics for server, database, PHP and WordPress installation

* ***WP Optimizer:***
  * WordPress cron optimization
  * Server .htaccess enhancements
  * Server-side compression (GZIP, Brotli)
  * Browser cache lifetime controls

* ***WP Updates:***
  * Full control over WordPress core, plugin and theme updates

* ***WP Security:***
  * Practical WordPress and server-level hardening options

* ***WP Mail:***
  * Mail logging
  * Configure external SMTP server

* ***WP Customizer:***
  * Prevent access to WordPress dashboard for non-admin users
  * Hide Admin Bar
  * Disable Block Editor and related block features
  * Disable comments
  * Disable QuickPress, WordPress Blog and Welcome Panel
  * Fast category list filter in your editor
  * Disable WordPress sitemap, short-links, self-ping and other non-essential outputs

* ***Minify:***
  * HTML, JavaScript and CSS optimization on the fly

**DONATIONS**

This plugin is free and always will be, but if you want to support development, you can buy me a
beer or coffee [here](https://www.paypal.com/donate/?business=dev.sh1zen%40outlook.it&item_name=Thank+you+in+advanced+for+the+kind+donations.+You+will+sustain+me+developing+WP-Optimizer.&currency_code=EUR).

== Installation ==

This section describes how to install the plugin. In general, there are 3 ways to install this plugin like any other
WordPress plugin.

**1. VIA WORDPRESS DASHBOARD**

* Click on 'Add New' in the plugins dashboard
* Search for 'WP Optimizer'
* Click 'Install Now'
* Activate the plugin from the same page or from the Plugins Dashboard

**2. VIA UPLOADING THE PLUGIN TO WORDPRESS DASHBOARD**

* Download the plugin to your computer
  from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/wp-optimizer/)
* Click on 'Add New' in the plugins dashboard
* Click on 'Upload Plugin'
* Select the plugin zip file
* Click 'Install Now'
* Activate the plugin from the Plugins Dashboard

**3. VIA FTP**

* Download the plugin to your computer
  from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/wp-optimizer/)
* Unzip the zip file, which will extract the main directory
* Upload the main directory to `/wp-content/plugins/`
* Activate the plugin from the Plugins Dashboard

**FOR MULTISITE INSTALLATION**

* Log in to your primary site and go to "My Sites" > "Network Admin" > "Plugins"
* Install the plugin following one of the above ways
* Network activate the plugin

**INSTALLATION DONE, A NEW LABEL WILL BE DISPLAYED ON YOUR ADMIN MENU**

== Frequently Asked Questions ==

= What should I enable first on a live site? =

Start with lower-risk optimizations first:

* Browser cache and compression
* Media optimization for new uploads
* A database backup before cleanup tasks

After that, enable cache and minify one option at a time and test the front end after each change.

= Can cache or minify break my layout? =

Yes. Minification and aggressive caching can expose theme or plugin conflicts.

* Enable HTML, CSS, and JavaScript minification separately
* Clear cache after each change
* If something breaks, disable the last option you enabled first

= Why are image optimization jobs not starting? =

Bulk media optimization runs in background, so it depends on your scheduler and PHP image libraries.

* WP-Cron or a real server cron must be working
* Imagick or GD must be available in PHP
* Large media libraries can take time to finish
* Review media settings before launching a full scan

= Do I need Redis or Memcached for object cache? =

Only for the object cache feature. Browser cache, static cache, database/query cache, compression, and minify can still be used without Redis or Memcached.

= How can I clean the database safely? =

Create a fresh backup first, then clean up in small steps.

* Back up the database before removing revisions, transients, or orphaned data
* Avoid cleanup during migrations, imports, or major plugin/theme updates
* Restore the backup instead of guessing if the result is not what you expected

= Can I export or restore plugin settings? =

Yes. The Settings module can export, import, reset, and restore plugin options. This is useful before testing aggressive tuning or when moving the same configuration between staging and production.

= Does WP Optimizer work on multisite? =

Yes. The plugin supports multisite and can be network activated. Each subsite may still have different themes, plugins, and traffic patterns, so test cache, minify, and security changes gradually.

= What should I do if something looks wrong after an update or settings change? =

Disable the last module or option you enabled, clear cache, and test again. If the issue started after an update, deactivate and reactivate the plugin first.

If needed, open the Settings module to restore or reset options, then re-enable features one by one.

== Changelog ==

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
