# Shared admin UI

`Graphic::render_admin_app()` opts consumers into `wps-admin-ui`. The framework
loads `assets/css/admin-ui.css` after enqueued plugin styles through
`admin_print_styles`. Its rules apply only inside the WPS app shell. The base
stylesheet also supports active non-app admin integrations.

Use shared panels, field groups, buttons and tabs instead of adding another
plugin-specific visual layer. Keep ordinary surfaces white, borders neutral,
headings readable and actions consistent with WordPress. Reserve color for
selected navigation, focus and meaningful state. Existing dashboard compatibility
selectors live in the shared stylesheet.

Status labels must describe measured state. Do not supply a static `Healthy`
badge. Explain what a setting changes in its description; the renderer associates
its name and description with the control. Tabs expose their selected panel and
support arrow keys, Home, End, Enter and Space.

Framework settings forms use one toast per form for pending, saving, confirmed
success and failure. Idle forms show no notification. Success disappears
automatically; failures offer a retry. Leaving with unsaved changes
triggers the browser warning. Consumers with their own save endpoint can use
`wps.createSaveFeedback(form, retryCallback)` and call the returned function with
`(state, translatedText)`. Keep the persistence endpoint and validation in the
consumer. Flexy SEO and Members Control level edits use this integration. The SEO
settings module owns the `wpfs_autosave_core_settings` AJAX action; do not add
another adapter or intercept
the generic WPS action for these forms.

Use `wps.showToast(state, translatedText)` for transient action feedback. The
framework owns the `wps-toast-host` and `.wps-toast` markup and the styles in
`assets/css/toast.css`; errors and warnings remain until dismissed. Every toast
has a close button. Server module notices and dynamic WPS action messages use
this same host. Inline guidance stays in its panel. Flexy SEO and Cloudflare use
this implementation. Tool page renderers write directly into the app content;
no buffering adapter is needed.

`vendor-wps-css` depends on `vendor-wps-toast-css`. Admin pages with a custom layout
can enqueue just `vendor-wps-toast-css` and `vendor-wps-js`, and mark transient
WordPress notice containers with `data-wps-notices`. XML Importer uses this
integration for action and settings feedback on both admin pages.

Run `node mini-test/wps-toast-regression.cjs` from the CMS directory with
Playwright available through `NODE_PATH` and PHP on `PATH` (or set `PHP_BINARY`).
It uses installed Chrome by default; `WPS_TEST_BROWSER` selects another Playwright
browser channel. Run `php mini-test/wps-toast-notices.php` for the standalone PHP
renderer check, and `php mini-test/wps-toast-importer.php` for importer asset hooks
and settings markup. The browser check uses real framework and plugin assets,
bundled WordPress jQuery and fake AJAX replies.
It covers save feedback, retry, queued edits, independent forms, server and dynamic
notices, dismiss/expiry behavior, and desktop/mobile positioning. It does not
load WordPress or save settings. Use authenticated admin pages to check field
labels, tab navigation and the complete page layout.

For WP Optimizer Page Test, run `php cms/tools/wpopt-page-test-regression.php` from
the site root with the site's PHP runtime. Open the generated
`cms/tools/wpopt-page-test-fixture.tmp.html` through localhost. Its isolated checks
cover HTTP failures in all four stages, network/body errors, missing test markers,
preparation failures, concurrent submits and retry. **Run real local requests**
uses signed URLs for five minutes; preparation and diagnostic display stay mocked.
Delete the generated HTML after checking because it contains temporary test URLs.
