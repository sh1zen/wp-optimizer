jQuery(document).ready(function ($) {

    let wpopt_abspath = "<?= WO_UtilEnv::normalize_path(ABSPATH, true) ?>";

    let $dirExplorer = $(".wpopt-dir-explorer");
    let $input = $dirExplorer.find('input');

    $dirExplorer.append('<ul class="wpopt-autocomplete" id="wpopt-dir-list" style="display: none;"></ul>');

    let suggestions = $('#wpopt-dir-list');

    suggestions.on("click", "li", function (e) {
        e.preventDefault();
        $input.val($(this).data('path'));
        suggestions.slideToggle();
    });

    function list_directories(res, status) {

        wpopt_semaphore.release('wpopt-dir-explorer');

        let response = res.response;

        if (suggestions.is(":hidden"))
            suggestions.slideToggle();

        suggestions.empty();

        if (status) {
            response['predictions'].forEach(function (k, index) {
                suggestions.append('<li data-path="' + k + '">' + wpopt_abspath + k + '</li>');
            });
        }
    }

    $input.on("input", function (e) {

        if (wpopt_semaphore.is_locked('wpopt-dir-explorer'))
            return;

        wpopt_semaphore.lock('wpopt-dir-explorer');

        wpopt_ajaxHandler({
            womod: 'media',
            wpopt_action: 'autoCompleteDirs',
            wpopt_args: $(this).val(),
            callback: list_directories
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

            let callback_fn = function (res, success) {

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

                $mex_viewer.wpoptNotice(res.response, success);
            }

            wpopt_ajaxHandler({
                use_loader: $this,
                womod: 'database',
                wpopt_action: action,
                wpopt_nonce: $this.data('nonce'),
                wpopt_args: $submitter.data('args'),
                wpopt_form: $this.serialize(),
                callback: callback_fn
            })
        });
    });
});