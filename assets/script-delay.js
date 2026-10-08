(function () {
    'use strict';
    if (window.wpsDelay) return;
    var started = false, failed = Object.create(null);
    function execute(old) {
        return new Promise(function (resolve) {
            var handle = old.dataset.wpsHandle;
            if (failed[handle] || (old.dataset.wpsDeps || '').split(' ').some(function (dep) { return failed[dep]; })) {
                failed[handle] = true; resolve(); return;
            }
            var script = document.createElement('script');
            Array.prototype.forEach.call(old.attributes, function (attr) {
                if (attr.name !== 'type' && attr.name !== 'src' && attr.name.indexOf('data-wps-') !== 0) script.setAttribute(attr.name, attr.value);
            });
            script.async = false;
            if (old.dataset.wpsType) script.type = old.dataset.wpsType;
            var external = old.dataset.wpsSrc, module = script.type === 'module';
            if (external || module) {
                script.onload = resolve;
                script.onerror = function () {
                    failed[handle] = true;
                    window.dispatchEvent(new CustomEvent('wps:script-delay-error', {detail: {handle: handle}}));
                    resolve();
                };
            }
            if (external) script.src = external;
            else script.textContent = old.textContent;
            old.replaceWith(script);
            if (!external && !module) resolve();
        });
    }
    function start() {
        if (started) return;
        started = true;
        var chain = Promise.resolve();
        document.querySelectorAll('script[type="application/x-wps-delayed"]').forEach(function (script) {
            chain = chain.then(function () { return execute(script); });
        });
        chain.then(function () { window.dispatchEvent(new CustomEvent('wps:script-delay-complete', {detail: {failed: Object.keys(failed)}})); });
    }
    // No event capture, cancellation or replay: the first click belongs to the site.
    window.wpsDelay = {start: start};
    ['pointerup', 'keydown'].forEach(function (event) {
        window.addEventListener(event, function () { setTimeout(start, 0); }, {once: true, passive: true});
    });
    function idle() {
        if ('requestIdleCallback' in window) requestIdleCallback(start, {timeout: 3000});
        else setTimeout(start, 2000);
    }
    if (document.readyState === 'complete') idle();
    else window.addEventListener('load', idle, {once: true});
    setTimeout(start, 5000);
})();
