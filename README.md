# WP Optimizer – WordPress Cache & Performance Optimization Plugin

WP Optimizer helps speed up WordPress with page caching, image optimization, WebP conversion, HTML/CSS/JavaScript minification and database cleanup. Its modular dashboard lets you enable the features your website needs and measure their impact with built-in performance diagnostics.

Core optimization features run on your own server without a required external optimization service or subscription.

[Download WP Optimizer](https://wordpress.org/plugins/wp-optimizer/) · [Support forum](https://wordpress.org/support/plugin/wp-optimizer/) · [Report a bug](https://github.com/sh1zen/wp-optimizer/issues)

## WordPress caching and page speed

- **Page cache:** serve cached HTML for eligible pages, with configurable lifetimes, URL exclusions and automatic purging when content changes.
- **Browser caching and compression:** configure static-resource caching and supported GZIP/Brotli server rules.
- **HTML, CSS and JavaScript minification:** reduce page and local asset size, with optional CSS and JavaScript combination.
- **Used CSS:** generate reduced stylesheets per page in the background, keeping original stylesheets available until ready or if processing fails.
- **Object and query caching:** optionally use Redis or Memcached for object caching, or cache selected WordPress and database query results.
- **Cloudflare integration:** optionally purge Cloudflare cache alongside local cache using your API token and Zone ID.

## Image optimization, WebP and Core Web Vitals

- Compress Media Library images and thumbnails locally with Imagick or GD.
- Convert supported images to WebP and resize oversized uploads.
- Optimize new uploads automatically or process existing media in background batches.
- Lazy load images and iframes, and add missing image dimensions when metadata is available.
- Learn and preload Largest Contentful Paint (LCP) images with high fetch priority.
- Defer JavaScript, delay selected background scripts and optimize font loading.

These features can improve loading speed and Core Web Vitals. Results depend on your hosting, theme, plugins, content and configuration.

## Database cleanup and website maintenance

Clean revisions, transients, spam and orphaned metadata; optimize tables; inspect autoloaded options; and create or restore SQL backups. Optional media metadata optimization moves attachment metadata to dedicated storage and restores it when disabled.

Manage WordPress cron events, control updates and restore configuration backups from the dashboard. Individual modules can also be reset independently.

## Performance diagnostics

**Page Test** compares a baseline request with WP Optimizer bypassed against your current warmed configuration. It reports response time, Time to First Byte (TTFB), peak memory and response size, with diagnostics for slow queries and expensive callbacks.

**Performance Monitor** helps identify slow plugins, theme code, database queries and requests through configurable sampling.

## Installation and setup

1. Install and activate **WP Optimizer** from **Plugins → Add New** in WordPress, or upload the plugin ZIP through **Upload Plugin**.
2. Open **WP Optimizer** in the dashboard and enable the modules you need.
3. Start with browser caching, supported compression and image optimization, then configure page caching and asset optimization one feature at a time.
4. Clear generated caches after changes and review important pages, forms and checkout flows.
5. Use **Page Test** to compare performance and **Performance Monitor** to investigate remaining bottlenecks.

Create a database backup before database cleanup or media metadata migration. When using another performance plugin, assign each optimization layer to one plugin to avoid conflicting cache, minification or server rules.

### Requirements

- WordPress 5.0 or newer and PHP 7.4 or newer.
- Imagick or GD for local image optimization; available formats depend on your server's image library.
- A working WordPress Cron setup for background image processing, Used CSS and local CSS/JavaScript minification.
- Redis or Memcached and its compatible PHP extension only if you enable that object-cache backend.

## Compatibility

WP Optimizer supports WordPress Multisite and includes safeguards for WooCommerce cart, checkout, account and session requests. Editing and preview requests from Elementor, Beaver Builder, Divi, Gutenberg, Bricks, Oxygen and Breakdance bypass incompatible caching and transformations.

Apache and LiteSpeed Enterprise use managed `.htaccess` rules. Nginx requires the generated `nginx.conf` file to be included in the site's server configuration. On OpenLiteSpeed, enable **Auto Load from .htaccess**, configure compression and response headers in WebAdmin, and restart the server after rewrite changes.

On Multisite, only network administrators can manage the shared object-cache and database-cache drop-ins; individual sites retain their own performance settings.

## Languages

Translations are managed on [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wp-optimizer/). WordPress loads installed language packs automatically for the active locale from its global plugins language directory, using the `wp-optimizer` text domain.

## Documentation and support

- [WordPress.org support forum](https://wordpress.org/support/plugin/wp-optimizer/) for help with installation and configuration.
- [GitHub issues](https://github.com/sh1zen/wp-optimizer/issues) for reproducible bugs and feature requests.
- [Frontend performance guide](docs/frontend-performance.md) for LCP and script configuration.
- [Media metadata guide](docs/media-metadata.md) for storage and migration details.
- [Public PHP API](EXTERNAL-API.md) for plugin and theme integrations.
- [Performance processing reference](PIPELINE.md) for background jobs and cache behavior.
