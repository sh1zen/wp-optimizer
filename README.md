# WP Optimizer
Welcome to the WP Optimizer repository on GitHub.

[![Author](https://img.shields.io/badge/author-sh1zen-brightgreen.svg)](https://sh1zen.github.io/)
![License: CC-NC](https://img.shields.io/badge/License-CCNC-orange.svg)
[![Donate](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://www.paypal.com/donate/?hosted_button_id=8G8VR4APG9JRU)
[![Repo Link](https://img.shields.io/badge/Repo-Link-black.svg)](https://github.com/sh1zen/wp-optimizer)


Here you can browse the source, look at open issues and keep track of development.

If you are not a developer, please use the [WP Optimizer plugin page](https://wordpress.org/plugins/wp-optimizer/) on WordPress.org.

## Description

WP Optimizer is a modular WordPress optimization toolkit for performance, maintenance, diagnostics and site control.
It brings together the common tools needed to keep a WordPress installation fast and easy to maintain: cache, media optimization, database managment, Page Test diagnostics, update controls, configuration backups and module resets.

### Performance and cache

- **Three configurable cache layers:** static pages, `WP_Query` results and database queries, each with independent lifetimes, query-argument handling, purge rules, user-agent exclusions and no-cache cookies. Page cache supports regex rules and optional direct server access; query and database caches can target selected query types or tables and purge only affected entries.
- **Safe compatibility defaults:** compatible with WooCommerce and with editing and preview flows from Elementor, Beaver Builder, Divi, Gutenberg, Bricks, Oxygen and Breakdance. Builder requests bypass cache and output optimization, preserving generated assets and markup.
- **Protected and extensible behavior:** built-in exclusions cannot be removed, but filters can add project-specific routes, request signatures and assets. Invalid or missing direct-cache configuration is regenerated in a disabled fail-safe state.

### Maintenance and diagnostics

- **Database and scheduling tools:** maintain tables, create database backups, review `wp_options` autoload data and manage WordPress cron events and custom schedules.
- **Four-stage Page Test:** scans a site URL with a signed optimization/cache-bypass request, an empty current-configuration pass, a diagnostic warmup and a final measured signed request using the current configuration.
- **Actionable diagnostics:** the warmup identifies slow or repeated queries, heavier hooks, callback samples and memory/query totals. Runtime HTML transformations use ordered handlers in the WPS `html_output_buffer` service, with PageSpeed processing before final HTML minification.

### Configuration safety

- **Automatic backups:** configuration snapshots are created before settings changes, throttled to prevent duplicate autosaves and limited to the 50 newest entries.
- **Safe module resets:** after confirmation, individual modules can be restored to factory settings from the modules screen, including their cleanup lifecycle.

### Web server compatibility

- **Automatic detection:** identifies Apache, Nginx, LiteSpeed Enterprise and OpenLiteSpeed. Apache and LiteSpeed Enterprise use generated `.htaccess` directives, while Nginx uses a generated `nginx.conf` include file.
- **OpenLiteSpeed support:** because `.htaccess` accepts only Apache `mod_rewrite` syntax, WP Optimizer writes compatible rules for direct cache delivery, redirects and rewrite-based security controls. Enable **Auto Load from .htaccess** for the virtual host; configure compression, response headers, MIME types and other non-rewrite options in WebAdmin, then restart OpenLiteSpeed after rewrite changes.

### Developer integrations

External plugins, themes, importers and maintenance scripts can use the documented public PHP functions in [EXTERNAL-API.md](EXTERNAL-API.md).


## Support
This repository is not suitable for support. Please don't use our issue tracker for support requests. Support can take 
place through the appropriate channel on [our community forum on wp.org](https://wordpress.org/support/plugin/wp-optimizer/).

Support requests in issues on this repository will be closed on sight.
