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

- **Per-page Used CSS:** the PageSpeed module builds reduced local stylesheets through WordPress Cron. WP Optimizer owns the engine and its bundled, isolated CSS parser. See [Used CSS](docs/used-css.md) for loading, safety rules and verification.
- **Frontend engines:** WP Optimizer owns LCP learning, image priority and script defer/delay, including their browser runtimes and asset policy. See [frontend performance](docs/frontend-performance.md) for loading, configuration and verification.
- **Layered cache storage:** the shared WPS cache uses request memory first, Redis (or Memcached) when available, and WPS `Storage` as the durable fallback under `WP_CONTENT_DIR/cache`. Positive lifetimes are applied to both remote and disk entries; a zero lifetime is request-local and is not persisted. WP Optimizer's static page, `WP_Query` and database-query caches continue to use their dedicated WPS Storage groups and independent lifetime, purge and exclusion rules.
- **Safe compatibility defaults:** compatible with WooCommerce and with editing and preview flows from Elementor, Beaver Builder, Divi, Gutenberg, Bricks, Oxygen and Breakdance. Builder requests bypass cache and output optimization, preserving generated assets and markup.
- **Protected and extensible behavior:** built-in exclusions cannot be removed, but filters can add project-specific routes, request signatures and assets. Invalid or missing direct-cache configuration is regenerated in a disabled fail-safe state.

### Maintenance and diagnostics

- **Database and scheduling tools:** maintain tables, create database backups, review `wp_options` autoload data and manage WordPress cron events and custom schedules. On WordPress Multisite, site administrators retain Database Manager access, while SQL script execution requires the `manage_network_options` capability.
- **Media metadata storage:** the plugin owns reversible media storage and publishes generic lifecycle events for external integrations. See [media metadata](docs/media-metadata.md) for storage and integration contracts.
- **Multisite lifecycle:** network-wide activation, upgrades and deactivation are applied independently to every site while preserving each site's settings, cron state and database-table prefix.
- **Four-stage Page Test:** scans a site URL with a signed optimization/cache-bypass request, an empty current-configuration pass, a diagnostic warmup and a final measured signed request using the current configuration.
- **Actionable diagnostics:** the warmup identifies slow or repeated queries, heavier hooks, callback samples and memory/query totals. Runtime HTML transformations use ordered handlers in the WPS `html_output_buffer` service, with PageSpeed processing before final HTML minification.
- **Performance Monitor storage:** configure **Maximum stored entries per table** under Shared request capture (default: 10,000; minimum: 1). Request history and Slow SQL each keep the newest entries within this limit and the 24-hour retention window.

### Configuration safety

- **Automatic backups:** configuration snapshots are created before settings changes, throttled to prevent duplicate autosaves and limited to the 50 newest entries.
- **Safe module resets:** after confirmation, individual modules can be restored to factory settings from the modules screen, including their cleanup lifecycle.
- **Multisite direct-cache isolation:** each blog has its own runtime configuration, index directory and request signature; the shared bootstrap routes by hostname and the most specific registered site path.
- **Network-owned cache drop-ins:** on Multisite, only users with `manage_network_options` can configure, install or remove the global `object-cache.php` and `db.php` drop-ins. Subsite administrators retain access to WP_Query and static-page cache settings.

### Web server compatibility

- **Automatic detection:** identifies Apache, Nginx, LiteSpeed Enterprise and OpenLiteSpeed. Apache and LiteSpeed Enterprise use generated `.htaccess` directives, while Nginx uses a generated `nginx.conf` include file.
- **Portable directory protection:** generated Apache-style security rules use `Options -Indexes`, avoiding the invalid mixed `Options All -Indexes` syntax that can make LiteSpeed reject the site `.htaccess`.
- **OpenLiteSpeed support:** because `.htaccess` accepts only Apache `mod_rewrite` syntax, WP Optimizer writes compatible rules for direct cache delivery, redirects and rewrite-based security controls. Enable **Auto Load from .htaccess** for the virtual host; configure compression, response headers, MIME types and other non-rewrite options in WebAdmin, then restart OpenLiteSpeed after rewrite changes.

### Developer integrations

External plugins, themes, importers and maintenance scripts can use the documented public PHP functions in [EXTERNAL-API.md](EXTERNAL-API.md).

### Module architecture

WP Optimizer registers modules through the explicit catalog in `inc/module-catalog.php`. The catalog declares one relative module directory and namespace; each module entry contains only its display name and scopes. The entry slug deterministically produces `<slug>.class.php` and `WPOptimizer\modules\Mod_<slug>`, so file paths and classes are never duplicated per module. The bundled WPS framework normalizes the catalog into one compact, request-shared registry and loads module files on demand. New top-level module classes must follow this convention and be registered explicitly; unregistered files are ignored and there is no discovery fallback.


## Support
This repository is not suitable for support. Please don't use our issue tracker for support requests. Support can take 
place through the appropriate channel on [our community forum on wp.org](https://wordpress.org/support/plugin/wp-optimizer/).

Support requests in issues on this repository will be closed on sight.
