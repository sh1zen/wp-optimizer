/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
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

        $("button.wpopt-sweep-details").each(function () {

            let $this = $(this);

            $this.on('click', function (e) {

                e.preventDefault();

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
        });

        $("button.wpopt-sweep").each(function () {

            let $this = $(this);

            $this.on('click', function (e) {

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
        });

        $("form.wpopt-ajax-db").each(function (e) {

            $(this).on('submit', function (e) {

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
    });
})(jQuery);
