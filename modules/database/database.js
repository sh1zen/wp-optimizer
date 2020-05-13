(function ($) {
    'use strict';
    $(function () {
        let options = wpopt_sweep || {};
        let $body = $('body');

        $('.btn-sweep').on('click', function (e) {
            e.preventDefault();

            let $node = $(this);
            let $row = $node.parents('tr');
            $body.addClass('sweeper-active');
            $node.prop('disabled', true).text(options.text_sweeping);

            return $.get(ajaxurl, {
                action: 'wpopt',
                womod: 'database',
                wpopt_action: $node.data('action'),
                sweep_name: $node.data('sweep_name'),
                sweep_type: $node.data('sweep_type'),
                '_wpnonce': $node.data('nonce')
            }, function (data) {
                if (data.success) {
                    $('.sweep-count', $row).text('0');
                    $('.sweep-percentage', $row).text('0');

                    if (data.count === 0) {
                        $node.parent('td').html(options.text_na);
                    }

                    $('.sweep-details', $row).html('').toggle("slow");

                    $body.removeClass('sweeper-active');

                    $node.prop('disabled', false).text(options.text_sweep);
                }
            });
        });
        $('.btn-sweep-details').on('click', function (e) {
            e.preventDefault();

            let $node = $(this);
            let $row = $('.sweep-details', $node.parents('tr'));

            if ($row.children().length > 0) {
                $row.toggle("slow");
            } else {

                $.get(ajaxurl, {
                    action: 'wpopt',
                    womod: 'database',
                    wpopt_action: $node.data('action'),
                    sweep_name: $node.data('sweep_name'),
                    sweep_type: $node.data('sweep_type'),
                    '_wpnonce': $node.data('nonce')
                }, function (data) {
                    if (data.success) {
                        if (data.data.length > 0) {
                            let html = '';
                            $.each(data.data, function (i, n) {
                                html += '<li>' + n + '</li>';
                            });
                            $row.append('<ol class="wpopt-gridRow">' + html + '</ol>').toggle("slow");
                        }
                    }
                });
            }
        });
        $(window).on('beforeunload', function (e) {
            if ($body.hasClass('sweeper-active')) {
                (e || window.event).returnValue = options.text_close_warning;
                return options.text_close_warning;
            }
        });
    });
})(jQuery);
