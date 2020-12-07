# WP Optimizer
 Optimize your WordPress website easily
 
 [![Author](https://img.shields.io/badge/author-sh1zen-brightgreen.svg)](https://sh1zen.github.io/)
 ![License: CC-NC](https://img.shields.io/badge/License-CCNC-orange.svg)
 [![Donate](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://paypal.me/sh1zen)
 [![Repo Link](https://img.shields.io/badge/Repo-Link-black.svg)](https://github.com/sh1zen/wp-optimizer)
 
    Contributors: sh1zen
    Tags: optimize, database, images, media, minify, backup, repair, speed, seo, server, clean-up, updates 
    Donate link: https://www.paypal.me/sh1zen
    Requires at least: 4.2.0
    Tested up to: 5.6
    Requires PHP: 5.6.0
    Stable tag: 1.4.1
    License: GNU v3.0
    License URI: https://github.com/sh1zen/wp-optimizer/blob/master/LICENSE

## Description 

 With WP Optimizer you can optimize your WordPress in all aspects with few and easy steps.
 This plugin contains most requested features to keep your WordPress site run as fast as new, from database and images optimizer to updates blocker.

**WHY USING WP Optimizer?**

* **All In One:** image optimization, media clean-up, database manager, database optimizer, updates manager, server info and  HTML, JavaScript, CSS minifier all in one plugin.
* **Performances** This plugin is built taking care on performance.
* **Privacy:** This plugin does not collect nor send any personally identifiable data.
* **No subscription email asked or required.**
* **Multisite support.**
* **Clean uninstall.**

**WHY USING WP Optimizer - Database feature?**

* **Overview:** The plugin will help you get an overview of what is happening in your database. It will report all unused/orphaned items that should be cleaned.
* **Auto optimize:** You can specify what items should be cleaned/optimized/repaired, the process will run automatically based on your settings.
* **Backup Manager:** The plugin will help you handle your database backups.
* **Benefits:**  
If you have been using WordPress for a while, then you should think absolutely about a database cleanup. 
Indeed, your database may be full of garbage that makes your site sluggish and bloated such as old revisions, orphaned post meta, spam comments, etc. 
You should clean-up this unnecessary data to reduce your database size and improve website speed and performance. 
In addition, you will have quicker database backup since the file of your backup will be smaller.

**WHY USING WP Optimizer - Images feature?**

* **Privacy:** Any optimization will run on your server using php module imagick (or if not available: jpegtran, optipng, pngout, gifsicle, cwebp) so that your stuff will remain yours.
* **Smooth Handling** With pixel-perfect optimization using progressive rendering, your images will be looks great.
* **Auto optimize:** You can set images optimization process run automatically on every file uploaded.
* **Save space:** By optimizing images you will save lot of space in WordPress media library, and your site will load faster due to less heavy images.
* **Benefits:**  
Thanks to image optimization your website will load faster, and the WordPress installation will take up less space.
Furthermore, the clean-up feature will handle non used media and remove them, so you can have much space available.

**WP Optimizer - Extra Features**

* ***Server Info:*** a detailed useful information about your server, databse, php and WordPress
* ***Dashboard installation size:*** a useful tool to know the size of your WordPress installation, to disable it go to settings
* ***Query caching system:*** a useful tool to speed up your WordPress loading time, works by caching WordPress query for some time, set by user

**Note**

* **Speed up your website is great to better connect with your visitors and to gain more SEO scores.**

* **Some features are still under development or test phase so may not currently be available, sorry for the inconvenience, I will bring them up as soon as possible.**

**DONATIONS**

 This plugin is free and always will be, but if you are feeling generous and want to show your support, you can buy me a beer or coffee [here](https://www.paypal.me/sh1zen), I will really appreciate it.


## Installation 


This section describes how to install the plugin. In general, there are 3 ways to install this plugin like any other WordPress plugin.

**1. VIA WORDPRESS DASHBOARD**

* Click on ‘Add New’ in the plugins dashboard
* Search for 'WP Optimizer'
* Click ‘Install Now’ button
* Activate the plugin from the same page or from the Plugins Dashboard
    
**2. VIA UPLOADING THE PLUGIN TO WORDPRESS DASHBOARD**

* Download the plugin to your computer from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/wp-optimizer/)
* Click on 'Add New' in the plugins dashboard
* Click on 'Upload Plugin' button
* Select the zip file of the plugin that you have downloaded to your computer before
* Click 'Install Now'
*  Activate the plugin from the Plugins Dashboard
    
**3. VIA FTP**

* Download the plugin to your computer from [https://wordpress.org/plugins/wp-optimizer/](https://wordpress.org/plugins/wp-optimizer/)
* Unzip the zip file, which will extract the main directory
* Upload the main directory (included inside the extracted folder) to the /wp-content/plugins/ directory in your web space
* Activate the plugin from the Plugins Dashboard
    
**FOR MULTISITE INSTALLATION**

* Log in to your primary site and go to "My Sites" » "Network Admin" » "Plugins"
* Install the plugin following one of the above ways
* Network activate the plugin
    
**INSTALLATION DONE, A NEW LABEL WILL BE DISPLAYED ON YOUR ADMIN MENU**

## FAQ
* What to do if I run in some issues after upgrade?

  Deactivate the plugin and reactivate it, if this doesn't work try to uninstall and install again the plugin. That should work! 


## Roadmap
* Support for custom image optimization quality
* Better reports
* Enable HTML, JavaScript and CSS optimization
* Fully reset WordPress module

**1.4.1**
* added setting panel to allow modules deactivation
* added option to import settings
* added an option in WP-Customizer module to hide WordPress versions from web-page 
* added an option in WP-Customizer module to hide WordPress welcome panel in the admin dashboard
* divided modules settings from core-plugin settings
* improved some core functionality
* optimized modules loading system

**1.4.0**
* added a module to disable (Gutenberg, Dashboard welcome panel, Core Sitemap, wpautop, Admin Bar, Feed links)
* added option to export and reset settings
* added WP_Term_Query and Database Query caching system
* added restrictions to some features based on user roles
* improved Cron handling for modules
* improved user-experience with panels
* updated language filters
* fixed some issues while saving settings
* major code restructure 

**1.3.8**
* added WP_Query posts caching system
* added storage to the core
* tested compatibility with WordPress 5.6
* improved handling for the dashboard widget
* improved the module for handling auto image optimization
* fixed some bugs and performance related issues
* fixed cron bug 

**1.3.6**
* added deep database scan for hierarchical terms
* added dashboard widget to show WordPress installation size
* added edit link to term list in database cleaner
* extended time limit for database operations
* improved translations support
* improved settings handling
* fixed some bugs 

**1.3.5**
* improved plugin speed 
* extended support to WordPress 5.5
* extended support from PHP 5.3 and WordPress 4.2
* fixed mysqldump error 1044, added single-transaction
* fixed mysqldump error handling
* fixed tables repair result, before always outputting fail

**1.3.3**
* added some database backup options (restore, download, delete)
* added database conversion: MyISAM / InnoDB
* added database action for all tables
* added css animations for Ajax modules
* improved JavaScript handlers for Ajax requests
* fixed memory conversion from size to bytes
* fixed WordPress heartbeat during restore process

**1.3.0**
* added Ajax support to modules
* added WordPress updates manager
* major code restructure
* improved modules handler
* full multilingual friendly
* designed a better UI
* fixed css enqueue

**1.2.3**
* added basic wp-cron settings
* added database module
* added ajax support
* added multisite support
* improved user configuration
* improved info module
* improved timer and memory meters 
* fixed settings handler
* fixed activation/deactivation after upgrade

**1.2.0**
* added uninstall cleanup procedure
* added settings page
* added meters to ensure speed and memory test on development phase 
* added multilingual support
* added modules support
* new ui for setting page
* improved core performance
