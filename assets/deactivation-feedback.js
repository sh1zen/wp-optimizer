(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const config = window.wpoptDeactivationFeedback || {};
        const dialog = document.querySelector('[data-wpopt-deactivation-dialog]');
        const pluginRow = Array.prototype.find.call(
            document.querySelectorAll('tr[data-plugin]'),
            function (row) {
                return row.getAttribute('data-plugin') === config.pluginFile;
            }
        );
        const deactivateLink = pluginRow ? pluginRow.querySelector('.deactivate a') : null;

        if (!dialog || !deactivateLink) {
            return;
        }

        const form = dialog.querySelector('[data-wpopt-deactivation-form]');
        const closeButton = dialog.querySelector('[data-wpopt-deactivation-close]');
        const skipButton = dialog.querySelector('[data-wpopt-deactivation-skip]');
        const submitButton = form.querySelector('[type="submit"]');
        const otherField = dialog.querySelector('[data-wpopt-deactivation-other]');
        const detailsField = otherField.querySelector('textarea');
        const deactivationUrl = deactivateLink.href;

        function openDialog() {
            dialog.hidden = false;
            document.body.classList.add('wpopt-deactivation-dialog-open');
            closeButton.focus();
        }

        function closeDialog() {
            dialog.hidden = true;
            document.body.classList.remove('wpopt-deactivation-dialog-open');
            deactivateLink.focus();
        }

        function deactivate() {
            window.location.assign(deactivationUrl);
        }

        deactivateLink.addEventListener('click', function (event) {
            event.preventDefault();
            openDialog();
        });

        closeButton.addEventListener('click', closeDialog);
        skipButton.addEventListener('click', deactivate);

        form.addEventListener('change', function (event) {
            if (event.target.name !== 'reason') {
                return;
            }

            const isOther = event.target.value === 'other';
            otherField.hidden = !isOther;
            detailsField.required = isOther;
            submitButton.disabled = false;

            if (isOther) {
                detailsField.focus();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            submitButton.disabled = true;
            const data = new FormData(form);
            data.append('action', 'wpopt_submit_deactivation_feedback');
            data.append('nonce', config.nonce || '');

            fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).finally(deactivate);
        });

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                closeDialog();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !dialog.hidden) {
                closeDialog();
            }
        });
    });
})();
