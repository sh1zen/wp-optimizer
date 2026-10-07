/**
 * Cloudflare settings panel interactions.
 */

'use strict';

(function ($) {

    function syncEnabledState($form) {
        const enabled = $form.find('.wpopt-cloudflare-enabled').is(':checked');

        $form
            .find('.wpopt-cloudflare-fields')
            .prop('hidden', !enabled);

        $form
            .find('.wpopt-cloudflare-submit')
            .prop('hidden', !enabled);

        $form
            .find('.wpopt-cloudflare-action')
            .prop('disabled', !enabled);
    }

    $(function () {
        const $form = $('.wpopt-cloudflare-form');

        if (!$form.length) {
            return;
        }

        syncEnabledState($form);

        $form.on('change', '.wpopt-cloudflare-enabled', function () {
            syncEnabledState($form);
        });

        $form.on('click', '.wpopt-cloudflare-action', function (event) {
            event.preventDefault();

            const $button = $(this);

            if ($button.prop('disabled')) {
                return;
            }

            $button.prop('disabled', true);

            wps.ajaxHandler({
                use_loading: $form,
                mod: 'cloudflare',
                mod_action: $button.data('action'),
                mod_nonce: $button.data('nonce'),
                mod_form: $form.serialize(),
                callback: function (data, state) {
                    wps.showToast(state === 'success' ? 'success' : 'error', data && data.text ? data.text : wps.locale.get(state, 'Request processed.'));
                    syncEnabledState($form);
                }
            });
        });
    });

})(jQuery);
