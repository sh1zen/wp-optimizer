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
It brings together the common tools needed to keep a WordPress installation fast and manageable: cache, media optimization, database maintenance, Page Test diagnostics, update controls, configuration backups and module resets.

After first activation, administrators see a one-time welcome page that explains the modular workflow and links directly to the modules manager. The welcome flag is stored separately from runtime module settings so reactivating an already configured installation does not show the page again.

### Performance and cache

The cache module includes dedicated configuration pages for static page cache, WP_Query cache and database query cache. Each cache layer can define its own lifespan, query-argument behavior, purge rules, user-agent exclusions and no-cache cookie handling.

Static page cache supports regex include/exclude rules and optional direct server access. WP_Query cache can be limited to selected query types, while database query cache can be limited to selected tables. Automatic purge is dependency-aware for WP_Query entries and table-aware for database query entries.

### Maintenance and diagnostics

The database module includes table maintenance, database backups and `wp_options` autoload review tools for safer cleanup work.

The Cron Manager is the administrative interface for viewing and managing WordPress cron events and custom schedules.

The admin dashboard includes a Page Test tool that runs four browser-based scans against a site URL:

1. A signed request with WP Optimizer modules and direct/server cache bypassed.
2. An empty current-configuration scan.
3. A diagnostic warmup request with the current configuration.
4. A measured signed request with the current configuration.

The diagnostic warmup pass collects optimization hints such as slow queries, repeated queries, heavier hooks, callback samples and memory/query totals.

Runtime HTML transformations are coordinated by the WPS `html_output_buffer` service. Modules register ordered transformers with this service instead of opening independent output buffers; PageSpeed transformations run before final HTML minification.

### Configuration safety

Automatic configuration backups are created before plugin settings changes. Backup creation is throttled to avoid duplicate autosave snapshots, and only the newest 50 entries are kept.

Each module can be reset from the modules settings screen. A module reset restores that module to factory settings and runs its cleanup lifecycle after confirmation.

### Developer integrations

External plugins, themes, importers and maintenance scripts can use the documented public PHP functions in [EXTERNAL-API.md](EXTERNAL-API.md).


## Support
This repository is not suitable for support. Please don't use our issue tracker for support requests. Support can take 
place through the appropriate channel on [our community forum on wp.org](https://wordpress.org/support/plugin/wp-optimizer/).

Support requests in issues on this repository will be closed on sight.

