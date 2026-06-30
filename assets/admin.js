(function ($) {
    'use strict';

    function confirmAction(message, options) {
        if (!message) {
            return Promise.resolve(true);
        }

        if (window.wps && window.wps.ui && window.wps.ui.popup && typeof window.wps.ui.popup.confirm === 'function') {
            return window.wps.ui.popup.confirm(message, options || {});
        }

        return Promise.resolve(false);
    }

    function initDashboardKpiPopups() {
        document.addEventListener('click', function (event) {
            const card = event.target.closest('[data-wpopt-kpi-popup]');

            if (!card) {
                return;
            }

            event.preventDefault();

            if (!window.wps || !window.wps.ui || !window.wps.ui.popup || typeof window.wps.ui.popup.alert !== 'function') {
                return;
            }

            window.wps.ui.popup.alert(
                card.getAttribute('data-detail') || '',
                {
                    title: card.getAttribute('data-title') || ''
                }
            );
        });
    }

    function closeFaq($item) {
        const $question = $item.find('> .wps-faq-question-wrapper > .wpopt-faq-toggle');
        const $answer = $question.next('.wps-faq-answer');
        const answer = $answer.get(0);

        if (!answer || !$item.hasClass('is-open')) {
            return;
        }

        $item.removeClass('is-open');
        $question.attr('aria-expanded', 'false');
        $question.children('.wps-collapse-icon').removeClass('wps-collapse-icon-close');

        answer.style.height = answer.scrollHeight + 'px';
        answer.style.opacity = '1';

        requestAnimationFrame(function () {
            answer.style.height = '0px';
            answer.style.opacity = '0';
        });
    }

    function openFaq($item) {
        const $question = $item.find('> .wps-faq-question-wrapper > .wpopt-faq-toggle');
        const $answer = $question.next('.wps-faq-answer');
        const answer = $answer.get(0);

        if (!answer || $item.hasClass('is-open')) {
            return;
        }

        $item.addClass('is-open');
        $question.attr('aria-expanded', 'true');
        $question.children('.wps-collapse-icon').addClass('wps-collapse-icon-close');

        answer.style.display = 'block';
        answer.style.height = '0px';
        answer.style.opacity = '0';

        requestAnimationFrame(function () {
            answer.style.height = answer.scrollHeight + 'px';
            answer.style.opacity = '1';
        });
    }

    function initFaq() {
        $('.wpopt-faq-shell .wps-faq-item').each(function (index) {
            const $item = $(this);
            const $question = $item.find('> .wps-faq-question-wrapper > .wpopt-faq-toggle');
            const $answer = $question.next('.wps-faq-answer');
            const answerId = 'wpopt-faq-answer-' + index;

            $question.attr({
                role: 'button',
                tabindex: '0',
                'aria-expanded': 'false',
                'aria-controls': answerId
            });

            $answer.attr('id', answerId).css({
                display: 'none',
                height: '0px',
                opacity: 0
            });
        });
    }

    function initPageTest() {
        const config = window.wpoptPageTest || {};
        const labels = config.labels || {};
        const $form = $('[data-wpopt-page-test-form]');

        if (!$form.length) {
            return;
        }

        const $input = $('[data-wpopt-page-test-url]', $form);
        const $button = $('[data-wpopt-page-test-submit]', $form);
        const $buttonText = $('[data-wpopt-page-test-button-text]', $button);
        const $status = $('[data-wpopt-page-test-status]');
        const $results = $('[data-wpopt-page-test-results]');
        const $summary = $('[data-wpopt-page-test-summary]');
        const $resultBody = $('[data-wpopt-page-test-result-body]');
        const $diagnostics = $('[data-wpopt-page-test-diagnostics]');
        const $diagnosticsHooks = $('[data-wpopt-page-test-diagnostics-hooks] .wpopt-page-test-diagnostics-content');
        const $diagnosticsQueries = $('[data-wpopt-page-test-diagnostics-queries] .wpopt-page-test-diagnostics-content');
        const $diagnosticsDuplicates = $('[data-wpopt-page-test-diagnostics-duplicates] .wpopt-page-test-diagnostics-content');
        const defaultButtonText = $buttonText.text();

        function label(key, fallback) {
            return labels[key] || fallback;
        }

        function setStatus(text, state) {
            $status
                .removeClass('is-running is-success is-error')
                .addClass(state ? 'is-' + state : '')
                .text(text);
        }

        function setStep(step, state) {
            $('[data-wpopt-page-test-steps] [data-step="' + step + '"]')
                .removeClass('is-running is-done is-failed')
                .addClass(state ? 'is-' + state : '');
        }

        function setProgress(percent) {
            const safePercent = Math.max(0, Math.min(100, Number(percent) || 0));

            $button.css('--wpopt-page-test-progress', safePercent + '%');
        }

        function setButtonRunning(running) {
            $button
                .toggleClass('is-running', running)
                .prop('disabled', running);
            $buttonText.text(running ? label('running', 'Running') : defaultButtonText);
        }

        function resetUi() {
            $('[data-wpopt-page-test-steps] [data-step]').removeClass('is-running is-done is-failed');
            $('[data-wpopt-page-test-speed], [data-wpopt-page-test-memory]')
                .removeClass('is-good is-bad')
                .text('--');
            $('[data-summary-card]', $summary)
                .removeClass('is-good is-bad is-neutral')
                .addClass('is-neutral')
                .find('[data-summary-value]')
                .text('--');
            $('[data-summary-card] [data-summary-detail]', $summary).text(label('currentVsBase', 'Current vs baseline'));
            $results.prop('hidden', true);
            $resultBody.empty();
            resetDiagnostics();
            setProgress(0);
        }

        function resetDiagnostics() {
            $diagnostics.prop('hidden', true);
            $diagnosticsHooks.empty();
            $diagnosticsQueries.empty();
            $diagnosticsDuplicates.empty();
        }

        function normalizeUrl(value) {
            try {
                return new URL(value, config.homeUrl || window.location.origin).toString();
            }
            catch (error) {
                return '';
            }
        }

        function prepareTestUrl(url) {
            return $.ajax({
                url: config.ajaxUrl || window.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpopt_page_test_prepare',
                    nonce: config.nonce,
                    url: url
                }
            }).then(function (response) {
                if (!response || !response.success || !response.data || !response.data.disabled_url) {
                    throw new Error(response && response.data && response.data.message ? response.data.message : label('sameSiteError', 'Enter a valid URL from this WordPress site.'));
                }

                return response.data;
            });
        }

        function now() {
            return window.performance && performance.now ? performance.now() : Date.now();
        }

        function pauseBetweenScans() {
            const delayMs = Number(config.scanPauseMs || 250);

            if (!Number.isFinite(delayMs) || delayMs <= 0) {
                return Promise.resolve();
            }

            return new Promise(function (resolve) {
                window.setTimeout(resolve, delayMs);
            });
        }

        function formatMs(value) {
            return typeof value === 'number' && isFinite(value) ? Math.round(value) + ' ms' : label('notAvailable', 'N/A');
        }

        function formatBytes(value) {
            if (typeof value !== 'number' || !isFinite(value) || value < 0) {
                return label('notAvailable', 'N/A');
            }

            if (value >= 1048576) {
                return (value / 1048576).toFixed(2) + ' MB';
            }

            if (value >= 1024) {
                return Math.round(value / 1024) + ' KB';
            }

            return Math.round(value) + ' B';
        }

        function formatNumber(value) {
            const number = Number(value);

            if (!Number.isFinite(number)) {
                return label('notAvailable', 'N/A');
            }

            return number.toLocaleString();
        }

        function getResponseSize(response, body) {
            const headerSize = parseInt(response.headers.get('content-length') || '', 10);

            if (Number.isFinite(headerSize) && headerSize >= 0) {
                return headerSize;
            }

            if (window.TextEncoder) {
                return new TextEncoder().encode(body || '').length;
            }

            return (body || '').length;
        }

        function getResponseHeaderInt(response, headerName) {
            const value = parseInt(response.headers.get(headerName) || '', 10);

            return Number.isFinite(value) && value >= 0 ? value : null;
        }

        function formatSpeedDelta(baselineMs, activeMs) {
            if (typeof baselineMs !== 'number' || typeof activeMs !== 'number' || baselineMs <= 0) {
                return {text: label('notAvailable', 'N/A'), detail: label('currentVsBase', 'Current vs baseline'), state: ''};
            }

            const percent = ((baselineMs - activeMs) / baselineMs) * 100;
            const rounded = Math.abs(percent) < 0.5 ? 0 : Math.round(Math.abs(percent));

            if (rounded === 0) {
                return {text: '0%', detail: formatMs(activeMs) + ' vs ' + formatMs(baselineMs), state: 'is-neutral'};
            }

            return {
                text: (percent > 0 ? '+' : '-') + rounded + '% ' + (percent > 0 ? 'faster' : 'slower'),
                detail: formatMs(activeMs) + ' vs ' + formatMs(baselineMs),
                state: percent > 0 ? 'is-good' : 'is-bad'
            };
        }

        function formatLowerIsBetterDelta(baselineValue, activeValue, formatter) {
            if (typeof baselineValue !== 'number' || typeof activeValue !== 'number' || baselineValue <= 0) {
                return {text: label('notAvailable', 'N/A'), detail: label('currentVsBase', 'Current vs baseline'), state: ''};
            }

            const percent = ((baselineValue - activeValue) / baselineValue) * 100;
            const rounded = Math.abs(percent) < 0.5 ? 0 : Math.round(Math.abs(percent));

            if (rounded === 0) {
                return {text: '0%', detail: formatter(activeValue) + ' vs ' + formatter(baselineValue), state: 'is-neutral'};
            }

            return {
                text: (percent > 0 ? '-' : '+') + rounded + '%',
                detail: formatter(activeValue) + ' vs ' + formatter(baselineValue),
                state: percent > 0 ? 'is-good' : 'is-bad'
            };
        }

        function formatMemoryDelta(baselineBytes, activeBytes) {
            if (typeof baselineBytes !== 'number' || typeof activeBytes !== 'number') {
                return {text: label('notAvailable', 'N/A'), detail: label('currentVsBase', 'Current vs baseline'), state: ''};
            }

            const delta = activeBytes - baselineBytes;
            const absDelta = Math.abs(delta);

            if (absDelta < 1024) {
                return {text: '0 B', detail: formatBytes(activeBytes) + ' vs ' + formatBytes(baselineBytes), state: 'is-neutral'};
            }

            return {
                text: (delta > 0 ? '+' : '-') + formatBytes(absDelta),
                detail: formatBytes(activeBytes) + ' vs ' + formatBytes(baselineBytes),
                state: delta < 0 ? 'is-good' : 'is-bad'
            };
        }

        function setSummaryMetric(metric, result) {
            const $card = $('[data-summary-card="' + metric + '"]', $summary);

            $card
                .removeClass('is-good is-bad is-neutral')
                .addClass(result.state || 'is-neutral');
            $('[data-summary-value]', $card).text(result.text);
            $('[data-summary-detail]', $card).text(result.detail || label('currentVsBase', 'Current vs baseline'));
        }

        function setStepMetric(step, metric, value, state) {
            $('[data-wpopt-page-test-steps] [data-step="' + step + '"] [data-wpopt-page-test-' + metric + ']')
                .removeClass('is-good is-bad')
                .addClass(state || '')
                .text(value);
        }

        function updateComparisonMetrics(baseline, active) {
            const speedDelta = formatSpeedDelta(baseline.totalMs, active.totalMs);
            const ttfbDelta = formatLowerIsBetterDelta(baseline.ttfb, active.ttfb, formatMs);
            const memoryDelta = formatMemoryDelta(baseline.memoryPeak, active.memoryPeak);
            const sizeDelta = formatLowerIsBetterDelta(baseline.size, active.size, formatBytes);

            setStepMetric('disabled', 'speed', label('baselineValue', 'Baseline'), '');
            setStepMetric('disabled', 'memory', baseline.memoryPeak !== null ? formatBytes(baseline.memoryPeak) : label('notAvailable', 'N/A'), '');
            setStepMetric('active', 'speed', speedDelta.text, speedDelta.state);
            setStepMetric('active', 'memory', memoryDelta.text, memoryDelta.state);

            setSummaryMetric('speed', speedDelta);
            setSummaryMetric('ttfb', ttfbDelta);
            setSummaryMetric('memory', memoryDelta);
            setSummaryMetric('size', sizeDelta);
        }

        async function scanPage(step, title, url, counted, options) {
            options = options || {};
            setStep(step, 'running');

            if (window.performance && performance.clearResourceTimings) {
                performance.clearResourceTimings();
            }

            const startedAt = now();
            const response = await fetch(url, {
                method: 'GET',
                credentials: options.credentials || 'omit',
                headers: {
                    Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                },
                cache: options.cache || 'default',
                redirect: 'follow'
            });
            const body = await response.text();
            const elapsedMs = now() - startedAt;
            const timingUrl = response.url || url;
            const entries = window.performance && performance.getEntriesByName ? performance.getEntriesByName(timingUrl) : [];
            const timing = entries.length ? entries[entries.length - 1] : null;
            const totalMs = timing && typeof timing.duration === 'number' && isFinite(timing.duration) && timing.duration > 0
                ? timing.duration
                : elapsedMs;
            const ttfb = timing && timing.responseStart ? timing.responseStart - timing.startTime : null;
            const directCache = response.headers.get('x-wpopt-static-direct') || '';
            const staticCache = response.headers.get('x-wpopt-static-cache') || '';
            const metrics = {
                title: title,
                status: response.status,
                ok: response.ok,
                totalMs: counted ? totalMs : null,
                ttfb: counted ? ttfb : null,
                size: counted ? getResponseSize(response, body) : null,
                memoryPeak: counted ? getResponseHeaderInt(response, 'x-wp-optimizer-memory-peak') : null,
                cacheStatus: counted ? formatCacheStatus(directCache, staticCache) : '',
                finalUrl: timingUrl,
                counted: counted
            };

            setStep(step, response.ok ? 'done' : 'failed');

            return metrics;
        }

        function formatCacheStatus(directCache, staticCache) {
            if (directCache) {
                return 'Direct ' + directCache;
            }

            if (staticCache) {
                return 'Static ' + staticCache;
            }

            return label('notAvailable', 'N/A');
        }

        function appendResult(result) {
            const statusText = result.ok ? label('done', 'Done') + ' (' + result.status + ')' : label('failedStatus', 'Failed') + ' (' + result.status + ')';
            const $row = $('<article>').addClass('wpopt-page-test-result-row');
            const addCell = function (heading, value, key) {
                const $cell = $('<div>').addClass('wpopt-page-test-result-cell');

                if (key) {
                    $cell.attr('data-result-key', key);
                }

                $('<span>').text(heading).appendTo($cell);
                $('<strong>').text(value).appendTo($cell);
                $cell.appendTo($row);
            };

            addCell(label('pass', 'Pass'), result.title, 'pass');
            addCell(label('status', 'Status'), statusText, 'status');
            addCell(label('total', 'Total'), result.counted ? formatMs(result.totalMs) : label('notAvailable', 'N/A'), 'total');
            addCell(label('ttfb', 'TTFB'), result.counted ? formatMs(result.ttfb) : label('notAvailable', 'N/A'), 'ttfb');
            addCell(label('cache', 'Cache'), result.counted ? result.cacheStatus : label('notAvailable', 'N/A'), 'cache');
            addCell(label('memory', 'Memory'), result.counted && result.memoryPeak !== null ? formatBytes(result.memoryPeak) : label('notAvailable', 'N/A'), 'memory');
            addCell(label('size', 'Size'), result.counted ? formatBytes(result.size) : label('notAvailable', 'N/A'), 'size');

            $resultBody.append($row);
        }

        function fetchDiagnostics(runId, attempt) {
            if (!runId) {
                return $.Deferred().resolve(null).promise();
            }

            return $.ajax({
                url: config.ajaxUrl || window.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'wpopt_page_test_diagnostics',
                    nonce: config.nonce,
                    run_id: runId
                }
            }).then(function (response) {
                if (response && response.success && response.data && response.data.ready) {
                    return response.data.diagnostics || null;
                }

                if ((attempt || 0) < 4) {
                    return new Promise(function (resolve) {
                        window.setTimeout(resolve, 300);
                    }).then(function () {
                        return fetchDiagnostics(runId, (attempt || 0) + 1);
                    });
                }

                return null;
            }, function () {
                return null;
            });
        }

        function appendEmpty($target) {
            $('<p>')
                .addClass('wpopt-page-test-diagnostics-empty')
                .text(label('diagnosticsEmpty', 'No actionable warmup diagnostics were captured for this run.'))
                .appendTo($target);
        }

        function renderHookRows(items) {
            $diagnosticsHooks.empty();

            if (!items || !items.length) {
                appendEmpty($diagnosticsHooks);
                return;
            }

            items.forEach(function (item) {
                const $item = $('<article>').addClass('wpopt-page-test-diagnostic-row');
                const callbacks = Array.isArray(item.callbacks) ? item.callbacks.join(' | ') : '';
                const $meta = $('<div>').addClass('wpopt-page-test-diagnostic-meta');

                $('<strong>').text(item.hook || '').appendTo($item);
                $('<span>').text(label('time', 'Time') + ': ' + formatMs(Number(item.duration_ms || 0))).appendTo($meta);
                $('<span>').text(label('callbacks', 'Callbacks') + ': ' + formatNumber(item.callback_count || 0)).appendTo($meta);
                $meta.appendTo($item);

                if (callbacks) {
                    $('<small>').text(label('callbackSamples', 'Callback samples')).appendTo($item);
                    $('<code>').text(callbacks).appendTo($item);
                }

                $diagnosticsHooks.append($item);
            });
        }

        function renderSlowQueries(items) {
            $diagnosticsQueries.empty();

            if (!items || !items.length) {
                appendEmpty($diagnosticsQueries);
                return;
            }

            items.forEach(function (item) {
                const $item = $('<article>').addClass('wpopt-page-test-diagnostic-row');
                const $meta = $('<div>').addClass('wpopt-page-test-diagnostic-meta');

                $('<strong>').text(formatMs(Number(item.time_ms || 0))).appendTo($item);
                $('<small>').text(label('caller', 'Caller')).appendTo($item);
                $('<span>').text(item.caller || label('notAvailable', 'N/A')).appendTo($meta);
                $meta.appendTo($item);
                $('<small>').text(label('query', 'Query')).appendTo($item);
                $('<code>').text(item.sql || '').appendTo($item);
                $diagnosticsQueries.append($item);
            });
        }

        function renderDuplicateQueries(items) {
            $diagnosticsDuplicates.empty();

            if (!items || !items.length) {
                appendEmpty($diagnosticsDuplicates);
                return;
            }

            items.forEach(function (item) {
                const $item = $('<article>').addClass('wpopt-page-test-diagnostic-row');
                const $meta = $('<div>').addClass('wpopt-page-test-diagnostic-meta');

                $('<strong>').text(label('repeatedQueries', 'Repeated queries')).appendTo($item);
                $('<span>').text(label('count', 'Count') + ': ' + formatNumber(item.count || 0)).appendTo($meta);
                $('<span>').text(label('time', 'Time') + ': ' + formatMs(Number(item.time_ms || 0))).appendTo($meta);
                $meta.appendTo($item);
                $('<small>').text(label('query', 'Query')).appendTo($item);
                $('<code>').text(item.query || '').appendTo($item);
                $diagnosticsDuplicates.append($item);
            });
        }

        async function renderWarmupDiagnostics(runId) {
            $diagnostics.prop('hidden', false);
            $diagnosticsHooks.empty();
            $diagnosticsQueries.empty();
            $diagnosticsDuplicates.empty();

            const report = await fetchDiagnostics(runId, 0);

            if (!report) {
                renderHookRows([]);
                renderSlowQueries([]);
                renderDuplicateQueries([]);
                return;
            }

            renderHookRows(report.hooks || []);
            renderSlowQueries(report.slow_queries || []);
            renderDuplicateQueries(report.duplicate_queries || []);
        }

        $form.on('submit', async function (event) {
            event.preventDefault();

            const url = normalizeUrl($input.val());

            if (!url) {
                setStatus(label('sameSiteError', 'Enter a valid URL from this WordPress site.'), 'error');
                return;
            }

            resetUi();
            setButtonRunning(true);

            try {
                setProgress(8);
                setStatus(label('preparing', 'Preparing signed test URL...'), 'running');
                const prepared = await prepareTestUrl(url);

                $results.prop('hidden', false);

                setProgress(28);
                setStatus(label('baseline', 'Measuring without WP Optimizer configuration...'), 'running');
                const baselineResult = await scanPage('disabled', $('.wpopt-page-test-step[data-step="disabled"] strong').text(), prepared.disabled_url, true, {cache: 'no-store'});
                appendResult(baselineResult);
                await pauseBetweenScans();

                setProgress(45);
                setStatus(label('activeEmpty', 'Scanning current WP Optimizer configuration without diagnostics...'), 'running');
                await scanPage('active_empty', '', prepared.url, false, {cache: 'no-store'});
                await pauseBetweenScans();

                setProgress(60);
                setStatus(label('warmup', 'Warming up current WP Optimizer configuration...'), 'running');
                await scanPage('warmup', '', prepared.warmup_url || prepared.url, false, {cache: 'no-store'});
                await pauseBetweenScans();
                setProgress(72);
                await renderWarmupDiagnostics(prepared.run_id);

                setProgress(84);
                setStatus(label('active', 'Measuring with current WP Optimizer configuration...'), 'running');
                const activeResult = await scanPage('active', $('.wpopt-page-test-step[data-step="active"] strong').text(), prepared.active_url || prepared.url, true, {cache: 'no-store'});
                appendResult(activeResult);
                updateComparisonMetrics(baselineResult, activeResult);

                setProgress(100);
                setStatus(label('complete', 'Test complete.'), 'success');
            }
            catch (error) {
                setStatus(error && error.message ? error.message : label('failed', 'The test could not be completed.'), 'error');
            }
            finally {
                setButtonRunning(false);
                window.setTimeout(function () {
                    if (!$button.hasClass('is-running')) {
                        setProgress(0);
                    }
                }, 700);
            }
        });
    }

    function initStaticCacheRules() {
        const selector = '[data-wpopt-static-rules-section]';

        if (!$(selector).length) {
            return;
        }

        function showToast(state, text) {
            if (window.wps && typeof window.wps.showToast === 'function') {
                window.wps.showToast(state, text);
            }
        }

        function runAction($section, action, formData, $trigger) {
            if (!action || $section.data('wpoptBusy')) {
                return;
            }

            confirmAction($trigger ? $trigger.data('confirm') : '', {danger: $trigger && $trigger.hasClass('button-link-delete')}).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }

                $section.data('wpoptBusy', true).addClass('wps-loader');
                if ($trigger && $trigger.length) {
                    $trigger.prop('disabled', true).addClass('is-busy');
                }

                wps.ajaxHandler({
                    mod: 'cache',
                    mod_action: action,
                    mod_nonce: wps.locale.get('wpopt_ajax_nonce', ''),
                    mod_form: formData || '',
                    callback: function (data, state) {
                        const success = state === 'success';
                        const text = data && data.text ? data.text : wps.locale.get(success ? 'success' : 'error', 'Request processed.');

                        if (data && data.html) {
                            $section.replaceWith(data.html);
                        }
                        else {
                            $section.removeClass('wps-loader').data('wpoptBusy', false);
                            if ($trigger && $trigger.length) {
                                $trigger.prop('disabled', false).removeClass('is-busy');
                            }
                        }

                        showToast(success ? 'success' : 'warning', text);
                    }
                });
            });
        }

        $(document)
            .on('submit', '[data-wpopt-static-rule-form]', function (event) {
                event.preventDefault();

                const $form = $(this);
                const $section = $form.closest(selector);
                const $submitter = $(event.originalEvent && event.originalEvent.submitter ? event.originalEvent.submitter : $form.find('[data-wpopt-static-action="add_static_rule"]').get(0));

                runAction($section, 'add_static_rule', $form.serialize(), $submitter);
            })
            .on('click', selector + ' [data-wpopt-static-action]', function (event) {
                const $trigger = $(this);
                const action = $trigger.data('wpoptStaticAction');

                if (action === 'add_static_rule') {
                    return;
                }

                event.preventDefault();
                runAction($trigger.closest(selector), action, '', $trigger);
            });
    }

    function initStaticConditionalTextareas() {
        syncStaticConditionalTextarea(
            '[data-wpopt-user-agent-exclusions-toggle]',
            '[data-wpopt-user-agent-patterns]'
        );
        syncStaticConditionalTextarea(
            '[data-wpopt-no-cache-cookies-toggle]',
            '[data-wpopt-no-cache-cookies-patterns]'
        );
    }

    function syncStaticConditionalTextarea(toggleSelector, patternsSelector) {
        const $toggle = $(toggleSelector);
        const $patterns = $(patternsSelector);

        if (!$toggle.length || !$patterns.length) {
            return;
        }

        function syncPatternsVisibility() {
            $patterns.toggleClass('is-hidden', !$toggle.is(':checked'));
        }

        syncPatternsVisibility();
        $(document).on('change', toggleSelector, syncPatternsVisibility);
    }

    function initCacheMultiselects() {
        const selector = '[data-wpopt-cache-multiselect]';

        if (!$(selector).length) {
            return;
        }

        function closeDropdowns(except) {
            $(selector).not(except || null).each(function () {
                const $dropdown = $(this);
                $dropdown.removeClass('is-open');
                $dropdown.find('.wps-multiselect__wrapper').slideUp(120);
            });
        }

        function syncDropdown(dropdown) {
            const $dropdown = $(dropdown);
            const inputName = $dropdown.data('inputName');
            const emptyLabel = $dropdown.data('emptyLabel') || 'No items selected';
            const selected = [];
            const selectedLabels = [];

            $dropdown.find('.wps-multiselect__element.is-selected').each(function () {
                const $item = $(this);
                selected.push(String($item.data('value')));
                selectedLabels.push(String($item.data('label') || $item.text()).trim());
            });

            $dropdown.find('[data-wpopt-cache-label]').text(selected.length ? selectedLabels.slice(0, 4).join(', ') : emptyLabel);
            $dropdown.find('> .wps-input__wrapper > input[type="hidden"]').first().val(selected.join(','));

            const $inputs = $dropdown.find('[data-wpopt-cache-inputs]').empty();
            if (!selected.length) {
                $('<input>', {
                    type: 'hidden',
                    name: inputName,
                    value: ''
                }).appendTo($inputs);
                return;
            }

            selected.forEach(function (value) {
                $('<input>', {
                    type: 'hidden',
                    name: inputName + '[]',
                    value: value
                }).appendTo($inputs);
            });
        }

        document.addEventListener('click', function (event) {
            const dropdown = event.target.closest(selector);

            if (!dropdown) {
                closeDropdowns();
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const $dropdown = $(dropdown);
            const item = event.target.closest('.wps-multiselect__element');

            if (item && dropdown.contains(item)) {
                $(item).toggleClass('is-selected');
                syncDropdown(dropdown);
                return;
            }

            if (event.target.closest('.wps-input__wrapper')) {
                const open = !$dropdown.hasClass('is-open');
                closeDropdowns(dropdown);
                $dropdown.toggleClass('is-open', open);
                $dropdown.find('.wps-multiselect__wrapper')[open ? 'slideDown' : 'slideUp'](120);
            }
        }, true);

        $(selector).each(function () {
            syncDropdown(this);
        });
    }

    $(function () {
        initDashboardKpiPopups();
        initFaq();
        initPageTest();
        initStaticCacheRules();
        initStaticConditionalTextareas();
        initCacheMultiselects();

        $('body')
            .on('click', '.wpopt-faq-shell .wpopt-faq-toggle', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $item = $(this).closest('.wps-faq-item');

                if ($item.hasClass('is-open')) {
                    closeFaq($item);
                }
                else {
                    openFaq($item);
                }
            })
            .on('keydown', '.wpopt-faq-shell .wpopt-faq-toggle', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                $(this).trigger('click');
            })
            .on('transitionend', '.wpopt-faq-shell .wps-faq-answer', function (event) {
                if (event.originalEvent.propertyName !== 'height') {
                    return;
                }

                const $answer = $(this);
                const $item = $answer.closest('.wps-faq-item');

                if ($item.hasClass('is-open')) {
                    this.style.height = 'auto';
                }
                else {
                    this.style.display = 'none';
                }
            });
    });
})(jQuery);
