(function () {
    'use strict';
    if (window.wpsUsedCssFallback) return;
    window.wpsUsedCssFallback = true;
    document.addEventListener('error', function (event) {
        var link = event.target;
        if (!link || link.tagName !== 'LINK' || !link.dataset.wpsOriginalHref) return;
        var original = link.dataset.wpsOriginalHref;
        delete link.dataset.wpsOriginalHref;
        delete link.dataset.wpsUsedCss;
        link.href = original;
    }, true);
})();
