/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

'use strict';

(function ($) {

    $(document).ready(function () {

        let confirm_action = function (message, options) {
            if (!message) {
                return Promise.resolve(true);
            }

            if (window.wps && window.wps.ui && window.wps.ui.popup && typeof window.wps.ui.popup.confirm === 'function') {
                return window.wps.ui.popup.confirm(message, options || {});
            }

            return Promise.resolve(false);
        };

        let show_database_toast = function (response, status) {
            let normalize_state = function (state) {
                state = state || 'info';

                if (state === 'updated') {
                    return 'success';
                }

                if (state === 'notice') {
                    return 'info';
                }

                return state;
            };
            let state = normalize_state(status || (response && response.status) || 'info');

            let show = function (text, item_status) {
                item_status = normalize_state(item_status || state);
                text = typeof text === 'string' && text ? text : wps.locale.get(item_status, 'Request processed.');

                if (window.wps && typeof window.wps.showToast === 'function') {
                    window.wps.showToast(item_status, text);
                }
            };

            if (response && Array.isArray(response.list)) {
                response.list.forEach(function (item) {
                    show(item && item.text, item && item.status ? item.status : state);
                });
                return;
            }

            show(response && response.text ? response.text : response, state);
        };

        let database_ajax_request = function ($element, $loading_target, callback_fn) {

            wps.ajaxHandler({
                action: 'wpopt',
                mod: 'database',
                mod_action: $element.data('action'),
                mod_nonce: $element.data('nonce'),
                mod_args: $element.data('args'),
                use_loading: $loading_target,
                callback: callback_fn
            });
        };

        let load_lazy_panel = function ($panel) {
            let $lazy = $('.wpopt-db-lazy-panel', $panel).first();

            if (!$lazy.length || $lazy.data('loading') || $lazy.data('loaded')) {
                return;
            }

            $lazy.data('loading', true);

            let args = {
                panel: $lazy.data('panel')
            };

            if (args.panel === 'db-tables' || args.panel === 'db-options') {
                let params = new URLSearchParams(window.location.search);

                ['paged', 'orderby', 'order', 's', 'wpopt_autoload'].forEach(function (key) {
                    if (params.has(key)) {
                        args[key] = params.get(key);
                    }
                });
            }

            wps.ajaxHandler({
                mod: 'database',
                mod_action: 'render_panel',
                mod_nonce: $lazy.data('nonce'),
                mod_args: args,
                use_loading: $panel,
                callback: function (res, status) {
                    $lazy.data('loading', false);

                    if (status === 'success' && res && res.html) {
                        $panel.data('nonce', $lazy.data('nonce'));
                        $lazy.data('loaded', true).replaceWith(res.html);
                        return;
                    }

                    $lazy.addClass('is-error').html('<strong>' + wps.locale.get('error', 'Error') + '</strong><p>' + (res && res.text ? res.text : 'Unable to load this section.') + '</p>');
                }
            });
        };

        let database_panel_query_keys = ['paged', 'orderby', 'order', 's', 'wpopt_autoload'];

        let get_database_panel_id = function ($element) {
            let id = $element.closest('.wpopt-db-manager-page .wps-ar-tabcontent').attr('id') || '';

            if (id === 'db-tables' || id === 'db-options') {
                return id;
            }

            return '';
        };

        let get_database_panel_nonce = function ($panel) {
            return $panel.data('nonce') || $('[data-nonce]', $panel).first().data('nonce') || '';
        };

        let update_database_panel_url = function (panel_id, args) {
            if (!window.history || !window.history.pushState) {
                return;
            }

            let url = new URL(window.location.href);

            database_panel_query_keys.forEach(function (key) {
                if (args[key] !== undefined && args[key] !== null && args[key] !== '') {
                    url.searchParams.set(key, args[key]);
                }
                else {
                    url.searchParams.delete(key);
                }
            });

            url.hash = panel_id;
            window.history.pushState({}, '', url.toString());
        };

        let reload_database_panel = function ($panel, args, update_url) {
            let panel_id = args.panel || $panel.attr('id');
            let nonce = get_database_panel_nonce($panel);

            if (!nonce) {
                return;
            }

            args.panel = panel_id;

            wps.ajaxHandler({
                mod: 'database',
                mod_action: 'render_panel',
                mod_nonce: nonce,
                mod_args: args,
                use_loading: $panel,
                callback: function (res, status) {
                    if (status === 'success' && res && res.html) {
                        $panel.html(res.html).data('loaded', true).data('nonce', nonce);

                        if (update_url) {
                            update_database_panel_url(panel_id, args);
                        }

                        return;
                    }

                    show_database_toast(res && res.text ? res : {text: 'Unable to load this section.'}, 'error');
                }
            });
        };

        let panel_args_from_url = function (panel_id, href) {
            let url = new URL(href, window.location.href);
            let args = {
                panel: panel_id
            };

            database_panel_query_keys.forEach(function (key) {
                if (url.searchParams.has(key)) {
                    args[key] = url.searchParams.get(key);
                }
            });

            return args;
        };

        let panel_args_from_form = function (panel_id, $form) {
            let params = new URLSearchParams($form.serialize());
            let args = {
                panel: panel_id
            };

            database_panel_query_keys.forEach(function (key) {
                if (key === 'paged') {
                    return;
                }

                if (params.has(key)) {
                    args[key] = params.get(key);
                }
            });

            return args;
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

        let schedule_active_lazy_panels = function () {
            let $panels = $('.wpopt-db-manager-page .wps-ar-tabcontent[aria-hidden="false"]');
            let hash = window.location.hash ? window.location.hash.substring(1) : '';

            if (!$panels.length && hash) {
                let hashPanel = document.getElementById(hash);

                if (hashPanel && $(hashPanel).closest('.wpopt-db-manager-page').length) {
                    $panels = $(hashPanel);
                }
            }

            if (!$panels.length) {
                $panels = $('.wpopt-db-manager-page .wps-ar-tabcontent').first();
            }

            $panels.each(function () {
                schedule_lazy_panel($(this));
            });
        };

        schedule_active_lazy_panels();
        window.setTimeout(schedule_active_lazy_panels, 0);
        window.setTimeout(schedule_active_lazy_panels, 150);

        $(document).on('click', '.wps-ar-tab[aria-controls]', function () {
            let panel_id = $(this).attr('aria-controls');

            window.setTimeout(function () {
                let $panel = $('#' + panel_id);

                if ($panel.closest('.wpopt-db-manager-page').length) {
                    load_lazy_panel($panel);
                }
            }, 0);
        });

        $(document).on('click', '.wpopt-db-manager-page .wp-list-table thead a, .wpopt-db-manager-page .wps-table thead a, .wpopt-db-manager-page .tablenav-pages a', function (e) {
            let $this = $(this);
            let panel_id = get_database_panel_id($this);

            if (!panel_id) {
                return;
            }

            e.preventDefault();
            reload_database_panel($('#' + panel_id), panel_args_from_url(panel_id, $this.attr('href')), true);
        });

        $(document).on('submit', '.wpopt-db-manager-page form.wpopt-db-tables-form, .wpopt-db-manager-page form.wpopt-db-options-form', function (e) {
            let $this = $(this);
            let panel_id = get_database_panel_id($this);

            if (!panel_id) {
                return;
            }

            let $submitter = $(e.originalEvent && e.originalEvent.submitter ? e.originalEvent.submitter : null);

            if (panel_id === 'db-tables' && $submitter.attr('id') !== 'search-submit') {
                return;
            }

            e.preventDefault();
            reload_database_panel($('#' + panel_id), panel_args_from_form(panel_id, $this), true);
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

        let format_bytes = function (bytes) {
            bytes = parseInt(bytes, 10) || 0;

            if (bytes < 1024) {
                return bytes + ' B';
            }

            let units = ['KB', 'MB', 'GB'];
            let value = bytes / 1024;
            let unit = units.shift();

            while (value >= 1024 && units.length) {
                value = value / 1024;
                unit = units.shift();
            }

            return value.toFixed(value >= 10 ? 1 : 2) + ' ' + unit;
        };

        let format_count = function (count) {
            return (parseInt(count, 10) || 0).toLocaleString();
        };

        let update_options_summary = function (summary) {
            if (!summary) {
                return;
            }

            let $summary = $('[data-role="wpopt-options-summary"]').first();

            if (!$summary.length) {
                return;
            }

            $('[data-stat="autoload-size"] strong', $summary).text(format_bytes(summary.autoload_size));
            $('[data-stat="autoload-count"] strong', $summary).text(format_count(summary.autoload_count));
            $('[data-stat="total-count"] strong', $summary).text(format_count(summary.total_count));
            $('[data-stat="total-size"] strong', $summary).text(format_bytes(summary.total_size));
        };

        let render_option_preview = function (preview) {
            let $wrap = $('<div/>', {'class': 'wpopt-option-preview-panel'});

            $('<pre/>').text(preview.preview || '').appendTo($wrap);

            return $wrap;
        };

        $(document).on('click', 'button.wpopt-option-preview', function (e) {

            e.preventDefault();

            let $this = $(this);
            let $table = $this.parents("table");
            let $row = $this.parents('tr');
            let columns = $('td, th', $row).length || 4;
            let option_key = $this.data('args');
            let $next = $row.next('.wpopt-option-preview-row');

            if ($next.length && $next.data('option') === option_key) {
                $next.remove();
                return;
            }

            $('.wpopt-option-preview-row', $table).remove();

            database_ajax_request($this, $row, function (res, success) {
                if (!success || !res || !res.list) {
                    show_database_toast(res || {text: 'Unable to load option preview.'}, 'error');
                    return;
                }

                let $preview_row = $('<tr/>', {
                    'class': 'wpopt-option-preview-row',
                    'data-option': option_key
                });

                $('<td/>', {
                    colspan: columns,
                    'data-label': ''
                }).append(render_option_preview(res.list)).appendTo($preview_row);

                $row.after($preview_row);
            });
        });

        let run_option_action = function ($this, rollback_fn) {
            let $row = $this.parents('tr');
            $this.prop('disabled', true);

            database_ajax_request($this, $row, function (res, success) {

                $this.prop('disabled', false);

                if (res && res.list && res.list.summary) {
                    update_options_summary(res.list.summary);
                }

                if (!success || !res) {
                    if (typeof rollback_fn === 'function') {
                        rollback_fn();
                    }
                    show_database_toast(res || {text: 'Invalid request.'}, 'error');
                    return;
                }

                show_database_toast(res, res.status || 'success');

                if (res.status === 'error') {
                    if (typeof rollback_fn === 'function') {
                        rollback_fn();
                    }
                    return;
                }

                if (res.list && res.list.deleted) {
                    let option_key = $this.data('args');
                    let $preview_row = $row.next('.wpopt-option-preview-row');

                    if ($preview_row.length && $preview_row.data('option') === option_key) {
                        $preview_row.remove();
                    }

                    $row.fadeOut(160, function () {
                        $(this).remove();
                    });
                    return;
                }

                if (res.list && res.list.option) {
                    let autoload = res.list.option.autoload || '';
                    let autoload_enabled = ['yes', 'on', 'auto', 'auto-on'].indexOf(String(autoload)) !== -1;
                    let $toggle = $('[data-action="option_toggle_autoload"]', $row);

                    $toggle.prop('checked', autoload_enabled);
                }
            });
        };

        $(document).on('change', 'input.wpopt-option-autoload-toggle', function () {
            let $this = $(this);
            let previous_checked = !$this.prop('checked');

            run_option_action($this, function () {
                $this.prop('checked', previous_checked);
            });
        });

        $(document).on('click', 'button.wpopt-option-action', function (e) {

            e.preventDefault();

            let $this = $(this);
            let confirm_message = $this.data('confirm');

            confirm_action(confirm_message, {danger: $this.hasClass('is-danger')}).then(function (confirmed) {
                if (confirmed) {
                    run_option_action($this);
                }
            });
        });

        $(document).on('submit', 'form.wpopt-ajax-db', function (e) {

            let $this = $(this);

            let $submitter = $(e.originalEvent.submitter);

            if ($submitter.data('explicit')) return;

            e.preventDefault();

            let action = $submitter.data('action');
            let selected_file = $submitter.data('file') || '';

            if (action === 'backup') {
                $this.addClass('wpopt-db-backup-is-running');
                $submitter.prop('disabled', true).val('Backup in progress...').text('Backup in progress...');
                $('.wpopt-db-backup-progress', $this).remove();
                $this.append(
                    '<div class="wpopt-db-backup-progress" role="status" aria-live="polite">' +
                    '<span class="wpopt-db-backup-progress-spinner" aria-hidden="true"></span>' +
                    '<span>Backup in progress. Please wait...</span>' +
                    '</div>'
                );
                $('button, input[type=submit], a.wpopt-btn', $this).not($submitter).addClass('is-disabled').attr('aria-disabled', 'true');
            }

            wp.heartbeat.suspend = true;

            wps.ajaxHandler({
                use_loader: $this,
                mod: 'database',
                mod_action: action,
                mod_nonce: $this.data('nonce'),
                mod_args: $submitter.data('args'),
                mod_form: $this.serialize(),
                callback: function (res, status) {

                    wp.heartbeat.suspend = false;

                    switch (action) {

                        case 'download':
                            let a = document.createElement('a');
                            a.style.display = 'none';
                            document.body.appendChild(a);
                            let blob = new Blob([res], {type: 'octet/stream'});
                            let url = URL.createObjectURL(blob);
                            a.href = url;
                            a.download = selected_file;
                            a.click();
                            URL.revokeObjectURL(url);
                            return;

                        case 'backup':
                            location.reload();
                            return;

                        case 'delete':
                            $submitter.parents('tr').fadeOut();
                            break;
                    }

                    show_database_toast(res, status);
                }
            })
        });
    });
})(jQuery);
