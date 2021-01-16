=== WP Optimizer === 
Contributors: sh1zen 
Tags: optimize, database, images, cache, minify, backup, repair, speed, seo, server, clean-up, updates 
Donate link: https://www.paypal.me/sh1zen
Requires at least: 4.2.0 
Tested up to: 5.6 
Requires PHP: 5.3 
Stable tag: 1.5.0
License: GNU v3.0 License
URI: https://github.com/sh1zen/wp-optimizer/blob/master/LICENSE

Search Engine (SEO) &amp; Performance Optimization plugin, support automatic image compression, integrated caching, database cleanup and Server enhancements.

== Description ==

WP-Optimizer (WPOPT) contains most requested features for WordPress maintaining, carrying many options to keep it run as fast as new and to improve SEO and user experience by increasing website performance and reducing load times.
All customizable in few and easy steps.

**WHY USING WP Optimizer?**
  
* **All In One:** WPOPT support: image optimization, media clean-up, database manager, database optimizer, updates
  manager, Server info, Server configuration manager, WordPress enhancer, caching system and HTML - JavaScript - CSS
  minifier.
* **Modules Oriented:** WPOPT is divided in modules, so you can handle each module on its own, and disable not
  necessary one to reduce memory and CPU usage.
* **Easy to use:** WPOPT was designed to be intuitive, allowing also non experts to be able to make that changes to have a great website. 
* **Performances:** WPOPT is built to speed up your site, every single module is optimized to ensure the best performance.
* **No limits:** WPOPT can optimize a huge amount of images in background even if php time limit is not changeable 
* **Multisite support.**
* **Privacy:** WPOPT does not collect nor send any data. Furthermore, all optimization actions run on
  your server, so that your stuff will remain yours. 
  **No subscription email is asked or required.**
  

**BENEFITS**

* Improvements in search engine result page rankings. 
* At least 10x site performance improvements (Grade A in [WebPagetest](https://www.webpagetest.org/) or significant [Google Page Speed](https://developers.google.com/speed/pagespeed/insights/) improvements) when fully configured.
* Reduced page load time: increased visitor time on site and number of viewed pages.
* Improved web server performance: sustain high traffic periods.
* Up to 80% bandwidth savings when images are optimized and HTML, CSS and JS are minified.
* Storage saver, optimized images required less disk space.

**FEATURES**

* ***Cache:*** an advanced caching system to ensure best speed performance, and the lowest space used.
* ***Database:*** a powerful tool to manage and clean up your WordPress database.
* ***Media:*** another powerful tool to manage all your media, removing duplicates or not used ones and optimizing images.
* ***WP Optimizer:*** offers many features to improve SEO and server performances.
* ***WP Updates:*** allow admins to disable some or all WordPress updates check.
* ***WP Customizer:*** offers the most used features to customize your WordPress, like Block Editor disabler, Admin Bar hider and so on..
* ***Server Info:*** a detailed useful information about your server, database, php and WordPress.
* ***Dashboard installation size:*** a useful tool to know the size of your WordPress installation.
  

**DONATIONS**

This plugin is free and always will be, but if you are feeling generous and want to show your support, you can buy me a
beer or coffee [here](https://www.paypal.me/sh1zen), I will really appreciate it.

== Installation ==

This section describes how to install the plugin. In general, there are 3 ways to install this plugin like any other
WordPress plugin.

**1. VIA WORDPRESS DASHBOARD**
  
* Click on ‘Add New’ in the plugins dashboard
* Search for 'WP Optimizer'
* Click ‘Install Now’ button
* Activate the plugin from the same page or from the Plugins Dashboard

**2. VIA UPLOADING THE PLUGIN TO WORDPRESS DASHBOARD**
  
* Download the plugin to your computer
  from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/wp-optimizer/)
* Click on 'Add New' in the plugins dashboard
* Click on 'Upload Plugin' button
* Select the zip file of the plugin that you have downloaded to your computer before
* Click 'Install Now'
* Activate the plugin from the Plugins Dashboard

**3. VIA FTP**
  
* Download the plugin to your computer
  from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/wp-optimizer/)
* Unzip the zip file, which will extract the main directory
* Upload the main directory (included inside the extracted folder) to the /wp-content/plugins/ directory of your website
* Activate the plugin from the Plugins Dashboard

**FOR MULTISITE INSTALLATION**

* Log in to your primary site and go to "My Sites" » "Network Admin" » "Plugins"
* Install the plugin following one of the above ways
* Network activate the plugin

**INSTALLATION DONE, A NEW LABEL WILL BE DISPLAYED ON YOUR ADMIN MENU**

== Upgrade Notice ==

= 1.5.0 =
Added wp_optimizer module carrying some new features, like security fixes, browser caching system and server
enhancements. Improved settings handling and cron schedules.
This is a huge upgrade, so plugin settings will be reset to defaults to ensure no conflicts.


**Roadmap**

* HTML, JavaScript and CSS optimization
* CDN support
* Reverse proxy support
* NGINX support

== Frequently Asked Questions ==

= WHY USING WP Optimizer - Database feature? =

* **Overview:** The plugin will help you get an overview of what is happening in your database. It will report all
  unused/orphaned items that should be cleaned.
* **Auto optimize:** You can specify what items should be cleaned/optimized/repaired, the process will run automatically
  based on your settings.
* **Backup Manager:** The plugin will help you handle your database backups.
* **Benefits:**  
  If you have been using WordPress for a while, then you should think absolutely about a database cleanup. Indeed, your
  database may be full of garbage that makes your site sluggish and bloated such as old revisions, orphaned post meta,
  spam comments, etc. You should clean-up this unnecessary data to reduce your database size and improve website speed
  and performance. In addition, you will have quicker database backup since the file of your backup will be smaller.

= WHY USING WP Optimizer - Images feature? = 

* **Privacy:** Any optimization will run on your server using php module imagick (or if not available: jpegtran,
  optipng, pngout, gifsicle, cwebp) so that your stuff will remain yours.
* **Smooth Handling** With pixel-perfect optimization using progressive rendering, your images will be looks great.
* **Auto optimize:** You can set images optimization process run automatically on every file uploaded.
* **Save space:** By optimizing images you will save lot of space in WordPress media library, and your site will load
  faster due to less heavy images.
* **Benefits:**  
  Thanks to image optimization your website will load faster, and the WordPress installation will take up less space.
  Furthermore, the clean-up feature will handle non used media and remove them, so you can have much space available.

= Why does speed matter? =

Search engines like Google, measure and factor in the speed of web sites in their ranking algorithm. When they recommend
a site they want to make sure users find what they're looking for quickly. So in effect you and Google should have the
same objective.

Speed is among the most significant success factors web sites face. In fact, your site's speed directly affects your
income (revenue) &mdash; it's a fact.

Speed it's a game changer in visibility, and some consequences of poor performance are:

* Lower perceived credibility and quality
* Increased user frustration
* Reduced conversion rates
* Increased exit rates
* Are perceived as less interesting or attractive

= What to do if I run in some issues after upgrade? =

Deactivate the plugin and reactivate it, if this doesn't work try to uninstall and reinstall it. That should
work! Otherwise, go to the new added module "Setting" and try a reset.

== Changelog ==

= 1.5.0 =

* added image optimization and orphaned media remover module
* added wp_security module
* added wp_optimizer module (browser caching system, server enhancements)
* added cron jobs time limit extender (allow executing long time schedules in environments with not changeable PHP time limit)
* improvements in settings core
* fixed some bugs in module folder_size

= 1.4.3 =

* added in settings parent ui support
* improved UI/UX  
* improved database cleaning
* improved cache flush
* updated filters for admins restricted content
* inverted some settings logic
* fixed translations
* improved cron modules handling

= 1.4.2 =

* improved JavaScripts UX
* fixed settings save procedure
* fixed some bugs with database backup
* rewritten some core functions

= 1.4.1 =

* added setting panel to allow modules deactivation
* added option to import settings
* added an option in WP-Customizer module to hide WordPress versions from web-page
* added an option in WP-Customizer module to hide WordPress welcome panel in the admin dashboard
* divided modules settings from core-plugin settings
* improved some core functionality
* optimized modules loading system

= 1.4.0 =

* added a module to disable (Gutenberg, Dashboard welcome panel, Core Sitemap, wpautop, Admin Bar, Feed links)
* added option to export and reset settings
* added WP_Term_Query and Database Query caching system
* added restrictions to some features based on user roles
* improved Cron handling for modules
* improved user-experience with panels
* updated language filters
* fixed some issues while saving settings
* major code restructure

= 1.3.8 =

* added WP_Query posts caching system
* added storage to the core
* tested compatibility with WordPress 5.6
* improved handling for the dashboard widget
* improved the module for handling auto image optimization
* fixed some bugs and performance related issues
* fixed cron bug

= 1.3.6 =

* added deep database scan for hierarchical terms
* added dashboard widget to show WordPress installation size
* added edit link to term list in database cleaner
* extended time limit for database operations
* improved translations support
* improved settings handling
* fixed some bugs

= 1.3.5 =

* improved plugin speed
* extended support to WordPress 5.5
* extended support from PHP 5.3 and WordPress 4.2
* fixed mysqldump error 1044, added single-transaction
* fixed mysqldump error handling
* fixed tables repair result, before always outputting fail

= 1.3.3 =

* added some database backup options (restore, download, delete)
* added database conversion: MyISAM / InnoDB
* added database action for all tables
* added css animations for Ajax modules
* improved JavaScript handlers for Ajax requests
* fixed memory conversion from size to bytes
* fixed WordPress heartbeat during restore process

= 1.3.0 =

* added Ajax support to modules
* added WordPress updates manager
* major code restructure
* improved modules handler
* full multilingual friendly
* designed a better UI
* fixed css enqueue

= 1.2.3 =

* added basic wp-cron settings
* added database module
* added ajax support
* added multisite support
* improved user configuration
* improved info module
* improved timer and memory meters
* fixed settings handler
* fixed activation/deactivation after upgrade

= 1.2.0 =

* added uninstall cleanup procedure
* added settings page
* added meters to ensure speed and memory test on development phase
* added multilingual support
* added modules support
* new ui for setting page
* improved core performance
