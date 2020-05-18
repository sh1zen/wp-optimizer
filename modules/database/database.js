(function ($) {

    'use strict';
    $(document).ready(function () {

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

                let action = $this.data('action');

                let callback = function (res) {

                    if (!res.success) {
                        return;
                    }

                    if (res.data.length > 0) {
                        let html = '';
                        $.each(res.data, function (i, n) {
                            html += '<li>' + n + '</li>';
                        });
                        $('.sweep-details', $row).append('<ol class="wpopt-gridRow">' + html + '</ol>').toggle("slow");
                    }
                }

                flex_ajaxHandler($table, {
                    womod: 'database',
                    wpopt_action: action,
                    wpopt_nonce: $this.data('nonce'),
                    wpopt_args: $this.data('args'),
                }, callback);
            });
        });

        $("button.wpopt-sweep").each(function () {

            let $this = $(this);

            $this.on('click', function (e) {

                if ($this.data('explicit')) return;

                e.preventDefault();

                let $table = $this.parents("table");
                let $row = $this.parents('tr');
                let action = $this.data('action');

                let callback = function (res) {

                    if (!res.success) {
                        return;
                    }

                    $('.sweep-count', $row).text('0');
                    $('.sweep-percentage', $row).text('0');

                    if (res.count === 0) {
                        $this.parent('td').html(WPOPT.strings.text_na);
                    }

                    $('.sweep-details', $row).html('').toggle("slow");
                }

                flex_ajaxHandler($table, {
                    womod: 'database',
                    wpopt_action: action,
                    wpopt_nonce: $this.data('nonce'),
                    wpopt_args: $this.data('args'),
                }, callback);

            });
        });

        $("form.wpopt-ajax-db").each(function (e) {

            $(this).on('submit', function (e) {

                let $this = $(this);
                let $submitter = $(e.originalEvent.submitter);

                if ($submitter.data('explicit')) return;

                e.preventDefault();

                let action = $submitter.data('action');

                switch (action) {

                    case 'restore':
                        wp.heartbeat.suspend = true;
                        break;
                }

                let callback = function (res) {
                    let $mex_viewer = $("#wpopt-ajax-message");

                    $mex_viewer.empty();

                    switch (action) {

                        case 'restore':
                            wp.heartbeat.suspend = false;
                            break;

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

                    flex_defaultMessage(res.data.response, res.success);
                }

                flex_ajaxHandler($this, {
                    womod: 'database',
                    wpopt_action: action,
                    wpopt_nonce: $this.data('nonce'),
                    wpopt_args: $submitter.data('args'),
                    wpopt_form: $this.serialize()
                }, callback)

            });
        });

    });

})(jQuery);
