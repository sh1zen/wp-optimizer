/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

'use strict';

(function ($) {

    $(document).ready(function () {

        let database_ajax_request = function ($element, $table, callback_fn) {

            wps.ajaxHandler({
                action: 'wpopt',
                mod: 'database',
                mod_action: $element.data('action'),
                mod_nonce: $element.data('nonce'),
                mod_args: $element.data('args'),
                use_loading: $table,
                callback: callback_fn
            });
        };

        let load_lazy_panel = function ($panel) {
            let $lazy = $('.wpopt-db-lazy-panel', $panel).first();

            if (!$lazy.length || $lazy.data('loading') || $lazy.data('loaded')) {
                return;
            }

            $lazy.data('loading', true);

            wps.ajaxHandler({
                mod: 'database',
                mod_action: 'render_panel',
                mod_nonce: $lazy.data('nonce'),
                mod_args: {
                    panel: $lazy.data('panel')
                },
                use_loading: $panel,
                callback: function (res, status) {
                    $lazy.data('loading', false);

                    if (status === 'success' && res && res.html) {
                        $lazy.data('loaded', true).replaceWith(res.html);
                        return;
                    }

                    $lazy.addClass('is-error').html('<strong>' + wps.locale.get('error', 'Error') + '</strong><p>' + (res && res.text ? res.text : 'Unable to load this section.') + '</p>');
                }
            });
        };

        let schedule_lazy_panel = function ($panel) {
            let $lazy = $('.wpopt-db-lazy-panel', $panel).first();

            if (!$lazy.length || $lazy.data('loading') || $lazy.data('loaded') || $lazy.data('scheduled')) {
                return;
            }

            $lazy.data('scheduled', true);

            let runner = function () {
                $lazy.data('scheduled', false);
                load_lazy_panel($panel);
            };

            if (window.requestIdleCallback) {
                window.requestIdleCallback(runner, {timeout: 700});
                return;
            }

            window.requestAnimationFrame(function () {
                window.setTimeout(runner, 0);
            });
        };

        $('.wpopt-db-manager-page .wps-ar-tabcontent[aria-hidden="false"]').each(function () {
            schedule_lazy_panel($(this));
        });

        $(document).on('click', '.wpopt-db-manager-page .wps-ar-tab[aria-controls]', function () {
            let panel_id = $(this).attr('aria-controls');

            window.setTimeout(function () {
                load_lazy_panel($('#' + panel_id));
            }, 0);
        });

        $(document).on('click', 'button.wpopt-sweep-details', function (e) {

            e.preventDefault();

            let $this = $(this);
            let $table = $this.parents("table");
            let $row = $this.parents('tr');
            let $details = $('.sweep-details', $row);

            if ($details.children().length > 0) {
                $details.toggle("slow");
                return;
            }

            let callback_fn = function (details, success) {

                if (!success) return;

                if (details.length > 0) {
                    let html = '';
                    $.each(details, function (i, n) {
                        html += '<li>' + n + '</li>';
                    });
                    $('.sweep-details', $row).append('<ol class="wps-gridRow">' + html + '</ol>').toggle("slow");
                }
            }

            database_ajax_request($this, $table, callback_fn);
        });

        $(document).on('click', 'button.wpopt-sweep', function (e) {

            let $this = $(this);

            if ($this.data('explicit')) return;

            e.preventDefault();

            let $table = $this.parents("table");
            let $row = $this.parents('tr');

            let callback_fn = function (sweep, success) {

                if (!success) {
                    return;
                }

                $('.sweep-count', $row).text('0');
                $('.sweep-percentage', $row).text('0');

                if (sweep.count === 0) {
                    $this.parent('td').html(wps.locale.get('text_na'));
                }

                $('.sweep-details', $row).html('').toggle("slow");
            }

            database_ajax_request($this, $table, callback_fn);
        });

        $(document).on('submit', 'form.wpopt-ajax-db', function (e) {

            let $this = $(this);

            let $submitter = $(e.originalEvent.submitter);

            if ($submitter.data('explicit')) return;

            e.preventDefault();

            let action = $submitter.data('action');

            wp.heartbeat.suspend = true;

            wps.ajaxHandler({
                use_loader: $this,
                mod: 'database',
                mod_action: action,
                mod_nonce: $this.data('nonce'),
                mod_args: $submitter.data('args'),
                mod_form: $this.serialize(),
                callback: function (res, status) {

                    let $mex_viewer = $("#wpopt-ajax-message");
                    $mex_viewer.empty();

                    wp.heartbeat.suspend = false;

                    switch (action) {

                        case 'download':
                            let a = document.createElement('a');
                            a.style.display = 'none';
                            document.body.appendChild(a);
                            let blob = new Blob([res], {type: 'octet/stream'});
                            let url = URL.createObjectURL(blob);
                            a.href = url;
                            a.download = $("input[name=file]:checked", $this).val();
                            a.click();
                            URL.revokeObjectURL(url);
                            return;

                        case 'backup':
                            location.reload();
                            return;

                        case 'delete':
                            $("input[name=file]:checked", $this).parents('tr').fadeOut();
                            break;
                    }

                    $mex_viewer.addNotice(res, status);
                }
            })
        });
    });
})(jQuery);
