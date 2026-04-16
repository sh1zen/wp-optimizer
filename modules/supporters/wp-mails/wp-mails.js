jQuery(function ($) {
    $(document).on('click', '.wpopt-mail-message-more', function (event) {
        event.preventDefault();

        const $trigger = $(this);
        const targetId = $trigger.data('mail-message-target');

        if (!targetId) {
            return;
        }

        const $content = $('#' + targetId);

        if (!$content.length) {
            return;
        }

        wps.ui.popup.render({
            title: 'Mail message',
            body: $content.html(),
            size: 'medium'
        });
    });
});
