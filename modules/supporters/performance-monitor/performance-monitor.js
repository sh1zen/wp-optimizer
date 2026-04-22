(function () {
    var applyPerformanceMonitorStyles = function (root) {
        Array.prototype.forEach.call(root.querySelectorAll('[data-wpopt-style-bg]'), function (node) {
            var value = node.getAttribute('data-wpopt-style-bg');

            if (value) {
                node.style.background = value;
            }
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-wpopt-style-width]'), function (node) {
            var value = node.getAttribute('data-wpopt-style-width');

            if (value !== null && value !== '') {
                node.style.width = value + '%';
            }
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-wpopt-style-color]'), function (node) {
            var value = node.getAttribute('data-wpopt-style-color');

            if (value) {
                node.style.color = value;
            }
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-wpopt-style-ratio]'), function (node) {
            var value = node.getAttribute('data-wpopt-style-ratio');

            if (value !== null && value !== '') {
                node.style.setProperty('--wpopt-ratio', value);
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            applyPerformanceMonitorStyles(document);
        });
        return;
    }

    applyPerformanceMonitorStyles(document);
}());
