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

Framework settings forms explain autosave and keep pending, saving, confirmed
success and failure visible. Failures offer a retry; leaving with unsaved changes
triggers the browser warning. Consumers with their own save endpoint can use
`wps.createSaveFeedback(form, retryCallback)` and call the returned function with
`(state, translatedText)`. Keep the persistence endpoint and validation in the
consumer. Flexy SEO uses this integration. Its settings module owns the
`wpfs_autosave_core_settings` AJAX action; do not add another adapter or intercept
the generic WPS action for these forms.

Use `wps.showToast(state, translatedText)` for transient action feedback. The
framework owns the `wps-toast-host` and `.wps-toast` markup; failures remain until
dismissed. Flexy SEO and Cloudflare use this same implementation. Tool page
renderers write directly into the app content; no buffering adapter is needed.

Run `cms/tools/wps-admin-ui-regression.html` through the local checkout, then repeat
with `?context=wpfs`. Click **Run isolated save checks**. The fixture intercepts all
AJAX: it tests successful and failed saves, persistent errors, retry, queued edits,
reverting during an in-flight save and independent forms without saving settings.
It expects WordPress's bundled jQuery at `../../wp-includes/js/jquery/jquery.min.js`.
Use authenticated admin pages to check the actual plugin asset cascade, field
labels, tab navigation and responsive layout.

For WP Optimizer Page Test, run `php cms/tools/wpopt-page-test-regression.php` from
the site root with the site's PHP runtime. Open the generated
`cms/tools/wpopt-page-test-fixture.tmp.html` through localhost. Its isolated checks
cover HTTP failures in all four stages, network/body errors, missing test markers,
preparation failures, concurrent submits and retry. **Run real local requests**
uses signed URLs for five minutes; preparation and diagnostic display stay mocked.
Delete the generated HTML after checking because it contains temporary test URLs.
