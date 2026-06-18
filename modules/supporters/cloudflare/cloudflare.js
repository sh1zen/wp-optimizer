/**
 * Cloudflare settings panel interactions.
 */

'use strict';

(function ($) {

    function ensureToastHost() {
        let $host = $('#wpopt-toast-host');

        if ($host.length) {
            return $host;
        }

        $host = $('<div/>', {
            id: 'wpopt-toast-host',
            'aria-live': 'polite',
            'aria-atomic': 'true'
        });

        $('body').append($host);

        return $host;
    }

    function showToast(state, text) {
        const $toast = $('<div/>', {
            'class': 'wpopt-toast is-' + state,
            text: text
        });

        ensureToastHost().append($toast);

        window.setTimeout(function () {
            $toast.addClass('is-visible');
        }, 10);

        window.setTimeout(function () {
            $toast.removeClass('is-visible');
            window.setTimeout(function () {
                $toast.remove();
            }, 220);
        }, 1800);
    }

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
                    showToast(state === 'success' ? 'success' : 'error', data && data.text ? data.text : wps.locale.get(state, 'Request processed.'));
                    syncEnabledState($form);
                }
            });
        });
    });

})(jQuery);
