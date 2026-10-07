(function () {
    'use strict';
    var node = document.getElementById('wps-lcp-config');
    if (!node || !window.PerformanceObserver || window.wpsLcpObserver) return;
    window.wpsLcpObserver = true;
    var config = JSON.parse(node.textContent), candidate, sent = false;
    function excluded(value, rules) { return (rules || []).some(function (rule) { return value.toLowerCase().indexOf(rule.toLowerCase()) !== -1; }); }
    function record(entry) {
        var el = entry.element;
        candidate = null;
        if (!el || excluded(el.id || '', config.exclusions.images) || excluded(String(el.className || ''), config.exclusions.classes)) return;
        var url = entry.url || (el.tagName === 'IMG' ? el.currentSrc || el.src : '');
        if (url && !excluded(url, config.exclusions.urls)) candidate = url;
    }
    var observer;
    function send() {
        if (observer) observer.takeRecords().forEach(record);
        if (sent || !candidate) return;
        sent = true;
        var body = new URLSearchParams({action: 'wps_lcp_measure', key: config.key, expires: config.expires,
            token: config.token, viewport: matchMedia('(max-width: 767px)').matches ? 'mobile' : 'desktop', url: candidate});
        fetch(config.endpoint, {method: 'POST', body: body, credentials: 'same-origin', keepalive: true}).catch(function () {});
    }
    try {
        observer = new PerformanceObserver(function (list) { list.getEntries().forEach(record); });
        observer.observe({type: 'largest-contentful-paint', buffered: true});
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') send(); });
        window.addEventListener('pagehide', send);
        ['pointerdown', 'keydown'].forEach(function (event) { window.addEventListener(event, send, {once: true, passive: true}); });
    } catch (error) { /* Keep original image loading when measurement is unavailable. */ }
})();
