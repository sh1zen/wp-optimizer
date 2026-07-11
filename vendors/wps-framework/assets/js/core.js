/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

"use strict";

(function ($, window) {
    if (!$) return null;

    let guid = 1,
        semaphoreList = {};

    const toString = Object.prototype.toString;

    const wps = function (selector, context) {
        return new wps.fn.init(selector, context);
    };

    let locales = {};

    wps.locale = {
        add: ($locale = {}) => wps.parse_args(locales, wps.json.parse($locale)),
        get: ($locale, $default = '') => $locale ? (locales[$locale] || $default) : locales
    };

    wps.fn = $.fn;
    wps.prototype = $.prototype;
    wps.extend = wps.fn.extend = $.extend;

    wps.extend({
        getUID: () => guid++,
        isObject: function (item, not = false) {
            return item !== null && item !== undefined && toString.call(item) === '[object Object]' ? (not === false || item) : not;
        },
        isFunction: $.isFunction,
        isjQuery: o => !!(o && o instanceof jQuery),

        parse_args: function (deFault, ...sources) {
            return this.isObject(deFault) ? Object.assign(deFault, ...sources.filter(s => this.isObject(s))) : deFault;
        },

        maybe_exec(item, runtime_args = null, context = null, asFilter = false) {
            if (this.isFunction(item)) return item.call(context, runtime_args);
            if (this.isObject(item) && this.isFunction(item.callback)) {
                return item.callback.call(context, item.args, runtime_args);
            }
            return asFilter ? runtime_args : item;
        },

        json: {
            stringify: JSON.stringify,
            parse(data, deFault) {
                if (wps.isObject(data)) return data;
                if (!data) return deFault;
                try {
                    return JSON.parse(data);
                } catch {
                    return data || deFault;
                }
            }
        },

        semaphore: {
            release: (context = 'core') => {
                semaphoreList[context] = false;
            },
            lock: (context = 'core') => {
                semaphoreList[context] = true;
            },
            is_locked: (context = 'core') => semaphoreList[context] || false
        },

        ajaxHandler(options) {
            options = wps.parse_args({
                mod: 'none', mod_context: '', mod_action: 'none', mod_nonce: '', mod_args: '', mod_form: '',
                use_loading: false, callback: null
            }, options);

            if (!options.mod_context) {
                options.mod_context = wps.currentAdminContext();
            }

            wps.semaphore.lock(options.mod_action);
            if (options.use_loading) options.use_loading.addClass("wps-loader");

            $.ajax({
                url: ajaxurl, type: "GET", dataType: "json", global: false, cache: false,
                data: {
                    action: 'wps', mod: options.mod, mod_context: options.mod_context, mod_action: options.mod_action,
                    mod_nonce: options.mod_nonce, mod_args: options.mod_args, mod_form: options.mod_form
                },
                complete(jqXHR) {
                    if (typeof options.callback === "function") {
                        const res = wps.json.parse(jqXHR.responseText) || jqXHR.responseText;
                        setTimeout(() => options.callback(res.data, res.status), 100);
                    }
                    if (options.use_loading) options.use_loading.removeClass("wps-loader");
                    wps.semaphore.release(options.mod_action);
                }
            });
        }
    });

    wps.fn.extend({
        remove_query_arg(paramKey) {
            const url = new URL(window.location.href);
            const keysToRemove = [...url.searchParams.keys()].filter(key => key.replace(/\[.*]/g, "") === paramKey);
            if (keysToRemove.length) {
                keysToRemove.forEach(key => url.searchParams.delete(key));
                window.history.replaceState({}, document.title, url.href);
            }
        },

        addNotice(response, status) {
            const $this = $(this);
            let text = response.text || response;
            if (typeof text !== 'string') text = wps.locale.get(status, 'Request processed.');
            $this.append(`<p class="${status}">${text}</p>`);
            if (response.list) {
                const fragment = document.createDocumentFragment();
                response.list.forEach(data => {
                    const p = document.createElement('p');
                    p.className = data.status;
                    p.textContent = data.text;
                    fragment.appendChild(p);
                });
                $this.append(fragment);
            }
        }
    });

    wps.ui = {
        popup: {
            defaults: {
                alertTitle: 'Notice',
                confirmTitle: 'Confirm action',
                confirmLabel: 'Confirm',
                cancelLabel: 'Cancel',
                closeLabel: 'Close'
            },
            _escape(value) {
                return $('<div>').text(value == null ? '' : String(value)).html();
            },
            _normalizeAction(action, index) {
                if (typeof action === 'string') {
                    action = {label: action};
                }

                action = wps.parse_args({
                    key: index === 0 ? 'confirm' : 'action_' + index,
                    label: index === 0 ? this._label('confirmLabel') : 'Action',
                    className: '',
                    style: 'secondary',
                    close: true,
                    autofocus: false,
                    callback: null
                }, action || {});

                action.key = String(action.key || ('action_' + index));
                action.label = String(action.label || action.key);

                return action;
            },
            _renderActions(actions) {
                actions = (Array.isArray(actions) ? actions : []).map((action, index) => this._normalizeAction(action, index));

                if (!actions.length) {
                    actions.push(this._normalizeAction({
                        key: 'close',
                        label: this._label('closeLabel'),
                        style: 'primary',
                        autofocus: true
                    }, 0));
                }

                return actions.map((action) => {
                    const classes = [
                        'wps-popup-action',
                        'wps-popup-action--' + action.style,
                        action.className
                    ].filter(Boolean).join(' ');

                    return `<button type="button" class="${this._escape(classes)}" data-wps-popup-action="${this._escape(action.key)}"${action.autofocus ? ' autofocus' : ''}>${this._escape(action.label)}</button>`;
                }).join('');
            },
            _messageBody(message) {
                return `<p class="wps-popup-message">${this._escape(message).replace(/\n/g, '<br>')}</p>`;
            },
            _label(key) {
                return wps.locale.get('popup_' + key, this.defaults[key]);
            },
            close(elem, options = {}) {
                options = wps.parse_args({remove: true, restore: false, beforeClose: null}, options);
                wps.maybe_exec(options.beforeClose, null, elem);
                $(elem).closest('.wps-modalWrapper').fadeOut(400, function () {
                    const $this = $(this);
                    if (options.restore) $this.find('.wps-modal__content').children().detach().appendTo(options.restore);
                    if (options.remove) $this.remove();
                    $('body').removeClass('sw-notScrollable');
                });
            },
            open(options = {}) {
                const popup = this;
                options = wps.parse_args({
                    title: false, body: false, restore: true, parseElement: false,
                    beforeAppend: null, afterAppend: null, beforeClose: null, afterClose: null,
                    size: 'small', message: false, remove: true, actions: []
                }, options);

                if (options.message && !options.body) {
                    options.body = popup._messageBody(options.message);
                }

                const actions = (Array.isArray(options.actions) ? options.actions : []).map((action, index) => popup._normalizeAction(action, index));
                if (actions.length) {
                    options.bottom = `<div class="wps-popup-actions">${popup._renderActions(actions)}</div>`;
                }

                let resolver = null;
                const promise = new Promise((resolve) => {
                    resolver = resolve;
                });

                const previousAfterAppend = options.afterAppend;
                options.afterAppend = function (current_modal) {
                    const $modal = $(current_modal);

                    if (previousAfterAppend) {
                        wps.maybe_exec(previousAfterAppend, current_modal, this);
                    }

                    if (actions.length) {
                        $modal.on('click', '[data-wps-popup-action]', function (event) {
                            const key = $(this).data('wps-popup-action');
                            const action = actions.find((item) => item.key === key) || actions[0];
                            let result = true;

                            if (action.callback) {
                                result = wps.maybe_exec(action.callback, {
                                    action,
                                    key,
                                    modal: $modal,
                                    event
                                }, this);
                            }

                            if (result === false) {
                                return;
                            }

                            resolver({key, action, modal: $modal});

                            if (action.close !== false) {
                                popup.close($modal, {
                                    restore: $modal.data('wpsRestoreContainer') || false,
                                    remove: options.remove,
                                    beforeClose: {callback: options.beforeClose, args: $modal}
                                });
                            }
                        });
                    }
                };

                const modalId = popup.render(options);

                return {
                    id: modalId,
                    promise,
                    close: () => popup.close($('#' + modalId), {remove: options.remove})
                };
            },
            alert(message, options = {}) {
                const closeLabel = options.closeLabel || this._label('closeLabel');
                options = wps.parse_args({
                    title: this._label('alertTitle'),
                    actions: [{key: 'ok', label: closeLabel, style: 'primary', autofocus: true}]
                }, options, {message});

                return this.open(options).promise;
            },
            confirm(message, options = {}) {
                const confirmLabel = options.confirmLabel || this._label('confirmLabel');
                const cancelLabel = options.cancelLabel || this._label('cancelLabel');
                const isDanger = !!options.danger;

                options = wps.parse_args({
                    title: this._label('confirmTitle'),
                    actions: [
                        {key: 'cancel', label: cancelLabel, style: 'secondary'},
                        {key: 'confirm', label: confirmLabel, style: isDanger ? 'danger' : 'primary', autofocus: true}
                    ]
                }, options, {message});

                return this.open(options).promise.then((result) => result && result.key === 'confirm');
            },
            create(options = {}) {
                const popup = this;
                const state = wps.parse_args({actions: []}, options);

                return {
                    title(title) {
                        state.title = title;
                        return this;
                    },
                    body(body) {
                        state.body = body;
                        return this;
                    },
                    message(message) {
                        state.message = message;
                        return this;
                    },
                    size(size) {
                        state.size = size;
                        return this;
                    },
                    action(action) {
                        state.actions.push(action);
                        return this;
                    },
                    actions(actions) {
                        state.actions = Array.isArray(actions) ? actions : [];
                        return this;
                    },
                    open() {
                        return popup.open(state);
                    }
                };
            },
            render(options) {
                options = wps.parse_args({
                    title: false, body: false, restore: true, parseElement: false,
                    beforeAppend: null, afterAppend: null, beforeClose: null, afterClose: null,
                    size: 'small', message: false, remove: true
                }, options);
                if (options.message) {
                    options.body = this._messageBody(options.message);
                }

                const modal_id = 'swModal' + wps.getUID();
                let bodyContent, detached = false, restoreContainer = false;

                if (wps.isjQuery(options.body)) {
                    if (options.parseElement) {
                        bodyContent = $.parseHTML(options.body.html(), document, true);
                    } else {
                        bodyContent = '';
                        if (options.restore) restoreContainer = options.body.parent();
                        detached = options.body.detach();
                    }
                } else {
                    bodyContent = wps.maybe_exec(options.body, null, this);
                }

                options._restoreContainer = restoreContainer;

                let modal_form = `<section class="wps-modalWrapper">
                    <section id="${modal_id}" class="wps-modal wps-modal--${options.size}" style="display: none">
                        <span class="wps-modal__close" role="button">&times;</span>
                        ${options.title ? `<div class="wps-modal__header"><h4 class="wps-modal__title">${options.title}</h4></div>` : ''}
                        <div class="wps-modal__content">${bodyContent}</div>
                        ${options.bottom ? `<div class="wps-modal__bottom">${wps.maybe_exec(options.bottom, null, this)}</div>` : ''}
                    </section></section>`;

                if (options.beforeAppend) modal_form = wps.maybe_exec(options.beforeAppend, modal_form, this);

                $('body').append(modal_form).addClass('sw-notScrollable');
                const current_modal = $('#' + modal_id);
                current_modal.data('wpsRestoreContainer', restoreContainer);
                if (detached) current_modal.find('.wps-modal__content').append(detached);
                current_modal.fadeIn(300);
                if (options.afterAppend) wps.maybe_exec(options.afterAppend, current_modal, this);

                current_modal.one('click', '.wps-modal__close', function () {
                    wps.ui.popup.close(current_modal, {
                        restore: restoreContainer, remove: options.remove,
                        beforeClose: {callback: options.beforeClose, args: current_modal}
                    });
                    current_modal.off();
                });
                return modal_id;
            }
        },

        circleChart: (percent, color, size, stroke) => `
            <svg class="wps-progressbarCircle__chart" viewbox="0 0 36 36" width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg">
                <path class="wps-progressbarCircle__bg" stroke="#eeeeee" stroke-width="${stroke * 0.5}" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                <path class="wps-progressbarCircle__stroke" stroke="${color}" stroke-width="${stroke}" stroke-dasharray="${percent},100" stroke-linecap="round" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                <text class="wps-progressbarCircle__info" x="50%" y="50%" alignment-baseline="central" text-anchor="middle" font-size="8">${percent}%</text>
            </svg>`
    };

    window.wps = wps;
    return wps;

})(jQuery, typeof window !== "undefined" ? window : this);

// Document ready
(function ($) {
    let $window, $body, media_uploader;
    let wpsAutosaveNonce = null;

    wps.currentAdminContext = function () {
        const body = document.body;

        if (body?.classList.contains("wpopt-admin-screen")) return "wpopt";
        if (body?.classList.contains("wpfs-admin-screen")) return "wpfs";
        if (body?.classList.contains("wpmc-admin-screen")) return "wpmc";

        return "";
    };

    function ensureWpoptToastHost() {
        let $host = $("#wpopt-toast-host");
        if ($host.length) return $host;

        $host = $("<div/>", {
            id: "wpopt-toast-host",
            "aria-live": "polite",
            "aria-atomic": "true"
        });

        $("body").append($host);
        return $host;
    }

    function showWpoptToast(state, text) {
        const $host = ensureWpoptToastHost();
        const $toast = $("<div/>", {
            "class": "wpopt-toast is-" + state,
            text: text
        });

        $host.append($toast);

        window.setTimeout(function () {
            $toast.addClass("is-visible");
        }, 10);

        window.setTimeout(function () {
            $toast.removeClass("is-visible");
            window.setTimeout(function () {
                $toast.remove();
            }, 220);
        }, 1800);
    }

    wps.showToast = showWpoptToast;

    function saveFeedbackKey() {
        const context = wps.currentAdminContext();
        return context ? "wps-save-feedback:" + context : "";
    }

    function currentFeedbackRoute() {
        const params = new URLSearchParams(window.location.search);

        return [
            params.get("page") || "",
            params.get("wps-page") || "",
            window.location.pathname
        ].join("|");
    }

    function noticeState($notice) {
        if ($notice.hasClass("notice-error") || $notice.hasClass("error") || $notice.find(".error").length) return "error";
        if ($notice.hasClass("notice-warning") || $notice.hasClass("update-nag") || $notice.find(".warning").length) return "warning";
        if ($notice.hasClass("notice-info")) return "info";

        return "success";
    }

    function clearPendingSaveFeedback() {
        const key = saveFeedbackKey();
        if (!key) return;

        try {
            window.sessionStorage.removeItem(key);
        } catch (e) {
        }
    }

    function rememberPendingSaveFeedback(form) {
        const key = saveFeedbackKey();
        if (!key) return;

        const $form = $(form);
        const method = String($form.attr("method") || "get").toLowerCase();
        const isSettingsForm = String($form.attr("action") || "").includes("options.php")
            || $form.find("input[name='option_panel'], .wps-submit, input[type='submit'].button-primary, button[type='submit'].button-primary").length > 0;

        if (method !== "post") return;
        if (!isSettingsForm) return;
        if (!$form.closest(".wps-admin-app, .wps-wrap, .wps-core-settings-page").length) return;

        try {
            window.sessionStorage.setItem(key, JSON.stringify({
                route: currentFeedbackRoute(),
                time: Date.now()
            }));
        } catch (e) {
        }
    }

    function showPendingSaveFeedbackFallback() {
        const key = saveFeedbackKey();
        if (!key) return;

        let pending = null;

        try {
            pending = JSON.parse(window.sessionStorage.getItem(key) || "null");
            window.sessionStorage.removeItem(key);
        } catch (e) {
            return;
        }

        if (!pending || pending.route !== currentFeedbackRoute()) return;
        if (Date.now() - Number(pending.time || 0) > 120000) return;

        showWpoptToast("success", wps.locale.get("saved", "Settings Saved"));
    }

    function showNoticeElementAsToast(element) {
        const $notice = $(element);
        const text = ($notice.find("p").first().text() || $notice.text()).trim();

        if (!text) return false;
        if ($notice.data("wps-toast-shown")) return false;

        $notice.data("wps-toast-shown", true);
        showWpoptToast(noticeState($notice), text);

        if ($notice.is("#wps-ajax-message, #message")) {
            $notice.empty();
            $notice.removeData("wps-toast-shown");
        } else {
            $notice.remove();
        }

        return true;
    }

    function initServerNoticeToasts() {
        if (!$body?.hasClass("wps-admin-screen")) return false;

        let shown = false;
        const $notices = $("#wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .settings-error")
            .not(".inline, .hidden");

        $notices.each(function () {
            shown = showNoticeElementAsToast(this) || shown;
        });

        const params = new URLSearchParams(window.location.search);

        if (params.get("settings-updated") === "true" && !shown) {
            showWpoptToast("success", wps.locale.get("saved", "Settings Saved"));
            shown = true;
        }

        if (shown) {
            clearPendingSaveFeedback();
        }

        return shown;
    }

    function initDynamicNoticeToasts() {
        if (!$body?.hasClass("wps-admin-screen") || !window.MutationObserver) return;

        const target = document.getElementById("wpbody-content");
        if (!target) return;

        const noticeSelector = ".notice, .updated, .error, .settings-error, #wps-ajax-message.wps-notice, #message.wps-notice";
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                $(mutation.addedNodes).each(function () {
                    if (this.nodeType !== 1) return;

                    const $node = $(this);
                    if ($node.is(noticeSelector)) {
                        showNoticeElementAsToast(this);
                    }

                    $node.find(noticeSelector).each(function () {
                        showNoticeElementAsToast(this);
                    });
                });

                if (mutation.type === "characterData" && mutation.target.parentElement) {
                    const notice = mutation.target.parentElement.closest(noticeSelector);
                    if (notice) {
                        showNoticeElementAsToast(notice);
                    }
                }
            });
        });

        observer.observe(target, {
            childList: true,
            subtree: true,
            characterData: true
        });
    }

    function wpsCurrentRoute() {
        return new URLSearchParams(window.location.search).get("wps-page") || "dashboard";
    }

    function wpsNavIconHref(icon, context) {
        const href = $(`.wps-app-${context} .wps-app-nav-icon use, .wps-app-${context} .wps-app-logo-icon use`)
            .first()
            .attr("href") || "";

        if (!href) return "";

        return href.replace(/#wps-icon-[^#]+$/, "#wps-icon-" + String(icon || "tools"));
    }

    function wpsBuildNavIcon(icon, context) {
        const href = wpsNavIconHref(icon, context);
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("class", "wps-svg-icon wps-app-nav-icon");
        svg.setAttribute("aria-hidden", "true");
        svg.setAttribute("focusable", "false");

        if (href) {
            const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
            use.setAttribute("href", href);
            svg.appendChild(use);
        }

        return svg;
    }

    function wpsBuildNavSection(section, context) {
        const fragment = document.createDocumentFragment();
        const label = document.createElement("span");
        const activeRoute = wpsCurrentRoute();

        label.className = "wps-app-nav-section";
        label.dataset.wpsNavKind = section.kind || "";
        label.textContent = section.label || "";
        fragment.appendChild(label);

        (section.items || []).forEach(function (item) {
            const link = document.createElement("a");
            const text = document.createElement("span");

            link.className = "wps-app-nav-item";
            link.href = item.url || "#";
            link.dataset.wpsNavKind = section.kind || "";
            link.dataset.wpsNavId = item.id || "";

            if (item.id === activeRoute) {
                link.classList.add("is-active");
            }

            text.textContent = item.label || item.id || "";
            link.appendChild(wpsBuildNavIcon(item.icon, context));
            link.appendChild(text);
            fragment.appendChild(link);
        });

        return fragment;
    }

    function wpsNavItemRoute(element) {
        try {
            return new URL($(element).attr("href") || "", window.location.href).searchParams.get("wps-page") || "";
        } catch (e) {
            return "";
        }
    }

    function wpsNavSectionKind(elements) {
        let kind = "";

        elements.each(function () {
            if (!$(this).hasClass("wps-app-nav-item")) return;

            const route = wpsNavItemRoute(this);
            if (route.indexOf("module-setting-") === 0) {
                kind = "settings";
                return false;
            }

            if (route.indexOf("module-") === 0) {
                kind = "tools";
                return false;
            }
        });

        return kind;
    }

    function wpsCollectNavSections($nav) {
        const sections = [];
        let current = null;

        $nav.children().each(function () {
            const $node = $(this);

            if ($node.hasClass("wps-app-nav-section")) {
                current = $();
                sections.push(current);
            }

            if (current) {
                current = current.add(this);
                sections[sections.length - 1] = current;
            }
        });

        return sections;
    }

    function wpsRefreshDynamicNav(navUpdate, context) {
        if (!navUpdate || !Array.isArray(navUpdate.sections)) return;

        context = context || wps.currentAdminContext();

        const $nav = $(`.wps-app-${context} .wps-app-nav`).first();
        if (!$nav.length) return;

        wpsCollectNavSections($nav).forEach(function ($section) {
            const kind = wpsNavSectionKind($section);

            if (kind === "settings" || kind === "tools") {
                $section.remove();
            }
        });

        const fragment = document.createDocumentFragment();
        navUpdate.sections.forEach(function (section) {
            if (section && Array.isArray(section.items) && section.items.length) {
                fragment.appendChild(wpsBuildNavSection(section, context));
            }
        });

        $nav.append(fragment);
    }

    wps.refreshDynamicNav = wpsRefreshDynamicNav;

    function initWpsAutosaveForm(form) {
        const $form = $(form);

        if (!wpsAutosaveNonce) return;
        if ($form.data("wps-autosave-init")) return;
        if ($form.closest(".wpfs-core-settings-page, .wpfs-breadcrumbs-page, .wpfs-settings-page").length) return;

        $form.data("wps-autosave-init", true);

        let lastSaved = $form.serialize();
        let timer = null;
        let inFlight = false;
        let queued = false;
        const isModulesHandlerForm = $form.find("input[name='option_panel'][value='settings-modules_handler']").length > 0;

        const $submit = $form.find(".wps-submit");
        $submit.find("input[type='submit'], button[type='submit'], .button-primary").remove();
        if (!$submit.find("*").length && !$submit.text().trim()) {
            $submit.hide();
        }

        const doSave = function () {
            const snapshot = $form.serialize();

            if (snapshot === lastSaved) return;
            if (inFlight) {
                queued = true;
                return;
            }

            inFlight = true;

            wps.ajaxHandler({
                mod: "settings",
                mod_action: "autosave_settings",
                mod_nonce: wpsAutosaveNonce,
                mod_form: snapshot,
                callback(data, state) {
                    inFlight = false;

                    if (state === "success") {
                        lastSaved = snapshot;
                        if (data?.module === "modules_handler") {
                            wpsRefreshDynamicNav(data.nav_update);
                        }
                        showWpoptToast("success", data?.text || wps.locale.get("autosaved", "All changes saved"));
                    } else {
                        showWpoptToast("error", data?.text || wps.locale.get("autosave_failed", "Autosave failed"));
                    }

                    if (queued) {
                        queued = false;
                        doSave();
                    }
                }
            });
        };

        const debounceSave = function () {
            clearTimeout(timer);
            timer = setTimeout(doSave, 700);
        };

        const scheduleSave = function (event) {
            if (isModulesHandlerForm && event.type === "change") {
                clearTimeout(timer);
                doSave();
                return;
            }

            debounceSave();
        };

        $form.on("change input", ":input:not([type='submit']):not([type='button']):not([type='hidden'])", scheduleSave);
        $form.on("submit", function (e) {
            e.preventDefault();
            doSave();
        });
    }

    function initWpsModuleReset() {
        $(document).on("click", "[data-wps-module-reset], [data-wpopt-module-reset]", function (event) {
            event.preventDefault();

            if (!wpsAutosaveNonce) return;

            const $button = $(this);
            const moduleSlug = String($button.data("wps-module-reset") || $button.data("wpopt-module-reset") || "");
            const moduleName = String($button.data("module-name") || moduleSlug);

            if (!moduleSlug || $button.prop("disabled")) return;

            const message = wps.locale.get(
                "wps_reset_module_confirm",
                "Reset %s to factory settings? Current module settings will be overwritten and the cleanup pipeline will run."
            ).replace("%s", moduleName);

            wps.ui.popup.confirm(message, {danger: true}).then(function (confirmed) {
                if (!confirmed) return;

                $button.prop("disabled", true).addClass("is-running");

                wps.ajaxHandler({
                    mod: "settings",
                    mod_action: "reset_module",
                    mod_nonce: wpsAutosaveNonce,
                    mod_args: {module: moduleSlug},
                    callback(data, state) {
                        $button.prop("disabled", false).removeClass("is-running");

                        const success = state === "success";
                        showWpoptToast(
                            success ? "success" : "error",
                            data?.text || wps.locale.get(success ? "wps_reset_module_success" : "wps_reset_module_failed", success ? "Module reset completed." : "Module reset failed.")
                        );
                    }
                });
            });
        });
    }

    function resumeConfirmedElement(element) {
        const $element = $(element);
        const form = element.form || $element.closest('form').get(0);

        $element.data('wps-confirmed', true);

        if (form && /^(submit|image)$/i.test(element.type || '')) {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(element);
            } else {
                const name = $element.attr('name');
                let hidden = null;

                if (name) {
                    hidden = $('<input>', {
                        type: 'hidden',
                        name,
                        value: $element.val()
                    }).appendTo(form);
                }

                form.submit();
                if (hidden) hidden.remove();
            }
            return;
        }

        if (element.tagName === 'A' && element.href) {
            window.location.href = element.href;
            return;
        }

        element.click();
    }

    function initConfirmableActions() {
        $(document).on('click', '[data-wps-confirm]', function (event) {
            const $element = $(this);
            const message = $element.data('wps-confirm');

            if (!message || $element.data('wps-confirmed')) {
                $element.removeData('wps-confirmed');
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            wps.ui.popup.confirm(message, {
                title: $element.data('wps-confirm-title') || wps.ui.popup._label('confirmTitle'),
                actions: [
                    {key: 'cancel', label: $element.data('wps-cancel-label') || wps.ui.popup._label('cancelLabel'), style: 'secondary'},
                    {key: 'confirm', label: $element.data('wps-confirm-label') || wps.ui.popup._label('confirmLabel'), style: ($element.data('wps-confirm-danger') !== undefined || $element.hasClass('is-danger') || $element.hasClass('button-link-delete')) ? 'danger' : 'primary', autofocus: true}
                ]
            }).then(function (confirmed) {
                if (confirmed) {
                    resumeConfirmedElement($element.get(0));
                }
            });
        });
    }

    function animateTabPanel(panelId) {
        if (!panelId) return;

        const $panel = $("#" + panelId);
        if (!$panel.length) return;

        $panel.removeClass("wpopt-tab-animate");
        window.requestAnimationFrame(function () {
            $panel.addClass("wpopt-tab-animate");
        });
    }

    function handleDependent($parent, visible = true, deep = true) {
        const parent = $parent.attr('id');
        if (!parent) return;

        const escaped = parent.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const parentRegex = new RegExp('(^|:)!?' + escaped + '(:|$)');
        const negateRegex = new RegExp('(^|:)!' + escaped + '(:|$)');

        $('[data-parent]').filter(function () {
            return parentRegex.test(String($(this).data('parent')));
        }).each(function () {
            const $this = $(this);
            const parents = String($this.data('parent'));
            const visibleAction = negateRegex.test(parents) ? !visible : visible;

            const $dropdown = $this.closest('dropdown');
            const $context = $dropdown.length
                ? $dropdown.closest('.wps-row, .wps-row-title')
                : $this.closest('.wps-row, .wps-row-title');

            if ($this.is('input')) $this.prop("readonly", !visibleAction);
            if ($context.length) $context.toggleClass('wps-disabled-blur', !visibleAction);
            if (deep && $this.is(':checkbox')) {
                handleDependent($this, visibleAction && $this.prop('checked'), deep);
            }
        });
    }

    function padTimePart(value) {
        return String(value).padStart(2, '0');
    }

    function normalizeTimeValue(value) {
        const parts = String(value || '').split(':');
        const hours = Number.parseInt(parts[0], 10);
        const minutes = Number.parseInt(parts[1], 10);

        return {
            hours: Number.isFinite(hours) ? Math.min(Math.max(hours, 0), 23) : 0,
            minutes: Number.isFinite(minutes) ? Math.min(Math.max(minutes, 0), 59) : 0,
        };
    }

    function formatTimeInput(input) {
        if (!input || input.disabled || input.readOnly || input.value === '') return;

        const current = normalizeTimeValue(input.value);
        input.value = `${padTimePart(current.hours)}:${padTimePart(current.minutes)}`;
    }

    function triggerFieldChange(input) {
        $(input).trigger('input').trigger('change');
    }

    function stepTimeInput(input, direction) {
        if (!input || input.disabled || input.readOnly) return;

        const step = Math.max(Number.parseInt(input.getAttribute('step') || '900', 10), 900);
        const deltaMinutes = Math.max(Math.round(step / 60), 1) * direction;
        const current = normalizeTimeValue(input.value);
        const dayMinutes = 24 * 60;
        let nextMinutes = (current.hours * 60) + current.minutes + deltaMinutes;

        nextMinutes = ((nextMinutes % dayMinutes) + dayMinutes) % dayMinutes;
        input.value = `${padTimePart(Math.floor(nextMinutes / 60))}:${padTimePart(nextMinutes % 60)}`;
        triggerFieldChange(input);
    }

    function decimalPlaces(value) {
        const text = String(value);

        if (text.includes('e-')) {
            return Number.parseInt(text.split('e-')[1], 10) || 0;
        }

        return text.includes('.') ? text.split('.')[1].length : 0;
    }

    function stepNumberInput(input, direction) {
        if (!input || input.disabled || input.readOnly) return;

        const step = Number.parseFloat(input.getAttribute('step') || '1') || 1;
        const min = Number.parseFloat(input.getAttribute('min'));
        const max = Number.parseFloat(input.getAttribute('max'));
        const current = Number.parseFloat(input.value);
        const precision = decimalPlaces(step);
        let next = (Number.isFinite(current) ? current : 0) + (step * direction);

        if (Number.isFinite(min)) next = Math.max(min, next);
        if (Number.isFinite(max)) next = Math.min(max, next);

        input.value = precision > 0 ? next.toFixed(precision) : String(Math.round(next));
        triggerFieldChange(input);
    }

    function handleStepperButtonClick(e) {
        e.preventDefault();

        const isTimeStepper = this.classList.contains('wps-time-stepper-btn');
        const direction = Number.parseInt(
            this.getAttribute(isTimeStepper ? 'data-wps-time-step' : 'data-wps-number-step') || '0',
            10
        );
        const $button = $(this);
        const input = $button.siblings(isTimeStepper ? '.wps-time-stepper-input' : 'input[type="number"]').get(0);

        if (isTimeStepper) {
            stepTimeInput(input, direction);
            return;
        }

        stepNumberInput(input, direction);
    }

    function setWpsAppNavOpen($app, open) {
        if (!$app || !$app.length) return;

        $app.toggleClass('is-nav-open', open);
        $app.find('> .wps-app-main .wps-app-menu-toggle').attr('aria-expanded', open ? 'true' : 'false');
        $app.find('> .wps-app-sidebar .wps-app-menu-close').attr('aria-expanded', open ? 'true' : 'false');

        if ($body) {
            $body.toggleClass('wps-app-nav-open', $('.wps-admin-app.is-nav-open').length > 0);
        }
    }

    function positionWpsDropdownList($dropdown) {
        if (!$dropdown || !$dropdown.length || !$dropdown.hasClass('is-open')) return;

        const trigger = $dropdown.find('.wps-input__wrapper').get(0);
        const list = $dropdown.data('wps-dropdown-list');

        if (!trigger || !list || !list.length) return;

        const rect = trigger.getBoundingClientRect();
        const gap = 6;
        const viewportPadding = 12;
        const availableBelow = window.innerHeight - rect.bottom - gap - viewportPadding;
        const availableAbove = rect.top - gap - viewportPadding;
        const preferredHeight = 240;
        const openAbove = availableBelow < 120 && availableAbove > availableBelow;
        const maxHeight = Math.max(120, Math.min(preferredHeight, openAbove ? availableAbove : availableBelow));
        const width = Math.min(rect.width, window.innerWidth - (viewportPadding * 2));
        const left = Math.min(
            Math.max(viewportPadding, rect.left),
            window.innerWidth - viewportPadding - width
        );
        const top = openAbove
            ? Math.max(viewportPadding, rect.top - maxHeight - gap)
            : Math.min(window.innerHeight - viewportPadding - maxHeight, rect.bottom + gap);

        list.css({
            position: 'fixed',
            top: `${Math.max(viewportPadding, top)}px`,
            left: `${left}px`,
            width: `${width}px`,
            maxHeight: `${maxHeight}px`
        });
    }

    function closeWpsDropdown($dropdown) {
        if (!$dropdown || !$dropdown.length) return;

        const list = $dropdown.data('wps-dropdown-list') || $dropdown.find('.wps-multiselect__wrapper');

        if (list && list.length) {
            list.hide()
                .removeClass('wps-dropdown-portal')
                .removeAttr('style')
                .appendTo($dropdown)
                .removeData('wps-dropdown-owner');
        }

        $dropdown.removeClass('is-open').removeData('wps-dropdown-list');
    }

    function closeOpenWpsDropdowns(except) {
        $('.wps-dropdown.is-open').each(function () {
            const $dropdown = $(this);

            if (except && $dropdown.is(except)) {
                return;
            }

            closeWpsDropdown($dropdown);
        });
    }

    function openWpsDropdown($dropdown) {
        closeOpenWpsDropdowns($dropdown);

        const list = $dropdown.children('.wps-multiselect__wrapper');

        if (!list.length) return;

        $dropdown.addClass('is-open').data('wps-dropdown-list', list);

        list
            .data('wps-dropdown-owner', $dropdown)
            .addClass('wps-dropdown-portal')
            .appendTo($body)
            .show();

        positionWpsDropdownList($dropdown);
    }

    $(function () {
        $window = $(window);
        $body = $('body');
        const adminContext = wps.currentAdminContext();
        wpsAutosaveNonce = adminContext ? wps.locale.get(adminContext + "_ajax_nonce", "") : "";
        const hadServerNoticeToast = initServerNoticeToasts();
        if (!hadServerNoticeToast) {
            showPendingSaveFeedbackFallback();
        }
        initDynamicNoticeToasts();
        initConfirmableActions();

        // Event delegation
        $body.on('click', '.wps-uploader__init', function (e) {
            e.preventDefault();
            const btn = $(this);
            if (!media_uploader) {
                media_uploader = wp.media({
                    title: 'Upload media',
                    library: {type: btn.data('type') || 'image'},
                    multiple: false
                }).on('select', function () {
                    btn.parent().find('input').val(media_uploader.state().get('selection').first().toJSON().url);
                });
            }
            media_uploader.open();
        })
            .on('click', '.wps-collapse-handler', function () {
                const $this = $(this);
                $this.children('.wps-collapse-icon').toggleClass('wps-collapse-icon-close');
                $this.next().toggle(300);
            })
            .on('click', '.wps-dropdown .wps-input__wrapper', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $dropdown = $(this).closest('.wps-dropdown');

                if ($dropdown.hasClass('is-open')) {
                    closeWpsDropdown($dropdown);
                    return;
                }

                openWpsDropdown($dropdown);
            })
            .on('click', '.wps-dropdown.is-open li, .wps-dropdown-portal li', function (e) {
                e.stopPropagation();
                const $list = $(this).closest('.wps-multiselect__wrapper');
                const $dropdown = $list.data('wps-dropdown-owner') || $(this).closest('.wps-dropdown');
                const input = $dropdown.find('input');

                input.val($(this).data('value'));
                $dropdown.find(`[data-input="${input.attr('id')}"]`).text($(this).text());
                triggerFieldChange(input.get(0));
                closeWpsDropdown($dropdown);
            })
            .on('click', function (e) {
                const $target = $(e.target);

                if (!$target.closest('.wps-dropdown, .wps-dropdown-portal').length) {
                    closeOpenWpsDropdowns();
                }
            })
            .on('click', 'icon.wps-option-info-icon', function () {
                const $icon = $(this);
                const $info = $icon.closest('row').find('label.wps-option-info');
                const wasVisible = $info.is(":visible");

                $info.slideToggle(160, function () {
                    const isVisible = $info.is(":visible");
                    $icon.toggleClass("is-active", isVisible);

                    if (!wasVisible && isVisible) {
                        $info.removeClass("is-entering");
                        window.requestAnimationFrame(function () {
                            $info.addClass("is-entering");
                        });
                        window.setTimeout(function () {
                            $info.removeClass("is-entering");
                        }, 420);
                    }
                });
            })
            .on('click', 'button[data-wps="ajax-action"]', function (e) {
                e.preventDefault();
                const $this = $(this);
                const e_args = {};
                if ($this.data('refer')) {
                    const o = $body.find(`[data-referred="${$this.data('refer')}"]`);
                    e_args[o.attr('name')] = o.val();
                }
                wps.ajaxHandler({
                    use_loader: $body,
                    mod: $this.data('mod') || $this.data('module'),
                    mod_action: $this.data('action'),
                    mod_nonce: $this.data('nonce'),
                    mod_args: wps.parse_args($this.data('args') || {}, e_args),
                    callback(res) {
                        res = res || {title: 'Error', body: 'Parsing response error.'};
                        wps.ui.popup.render({
                            title: res.title || 'Notice',
                            body: typeof res === 'string' ? res : (res.body || 'Something went wrong.'),
                            size: 'small'
                        });
                    }
                });
            })
            .on('submit', '.wps-admin-app form, .wps-wrap form, .wps-core-settings-page form', function () {
                rememberPendingSaveFeedback(this);
            })
            .on('click', '.wps-app-menu-toggle', function (e) {
                e.preventDefault();
                setWpsAppNavOpen($(this).closest('.wps-admin-app'), true);
            })
            .on('click', '.wps-app-menu-close', function (e) {
                e.preventDefault();
                setWpsAppNavOpen($(this).closest('.wps-admin-app'), false);
            })
            .on('click', '.wps-admin-app.is-nav-open .wps-app-nav-item', function () {
                if (window.matchMedia('(max-width: 960px)').matches) {
                    setWpsAppNavOpen($(this).closest('.wps-admin-app'), false);
                }
            })
            .on('keydown', function (e) {
                if (e.key === 'Escape') {
                    closeOpenWpsDropdowns();
                    $('.wps-admin-app.is-nav-open').each(function () {
                        setWpsAppNavOpen($(this), false);
                    });
                }
            })
            .on('click change', '.wps-apple-switch', function () {
                handleDependent($(this), this.checked);
            })
            .on('click', '.wps-time-stepper-btn, .wps-number-stepper-btn', handleStepperButtonClick)
            .on('blur', '.wps-time-stepper-input', function () {
                formatTimeInput(this);
            });

        $window.on('resize', function () {
            $('.wps-dropdown.is-open').each(function () {
                positionWpsDropdownList($(this));
            });

            if (!window.matchMedia('(max-width: 960px)').matches) {
                $('.wps-admin-app.is-nav-open').each(function () {
                    setWpsAppNavOpen($(this), false);
                });
            }
        });

        document.addEventListener('scroll', function () {
            $('.wps-dropdown.is-open').each(function () {
                positionWpsDropdownList($(this));
            });
        }, true);

        // Circle charts
        $('.wps-progressbarCircle').each(function () {
            const $chart = $(this);
            $chart.html(wps.ui.circleChart(
                $chart.data('percent') || 0,
                $chart.data('color') || 'var(--main-dark-color)',
                $chart.data('size') || 100,
                $chart.data('stroke') || 1
            ));
        });

        $('.wps-admin-app').each(function (appIndex) {
            const $app = $(this);
            const $tabsbar = $app.find('> .wps-app-main > .wps-app-tabsbar').first();
            if (!$tabsbar.length) return;

            const $tabs = $app.find('> .wps-app-main > .wps-app-content .wps-ar-tabs').filter(function () {
                return $(this).closest('.wps-ar-tabcontent').length === 0;
            }).first();

            if (!$tabs.length) return;

            const $tabList = $tabs.children('.wps-ar-tablist').first();
            if (!$tabList.length || $tabList.closest('.wps-app-tabsbar').length) return;

            const tabsId = $tabs.attr('data-wps-tabs-id') || `wps-tabs-${appIndex}`;
            $tabs.attr('data-wps-tabs-id', tabsId).addClass('wps-ar-tabs-has-external-list');
            $tabList.attr('data-wps-tabs-owner', tabsId);
            $tabsbar.empty().append($tabList).removeAttr('hidden');
        });

        // Tabs
        $('.wps-ar-tabs').each(function () {
            const $tab = $(this);
            const tabsId = $tab.attr('data-wps-tabs-id');
            const $externalTabList = tabsId
                ? $tab.closest('.wps-admin-app').find('.wps-app-tabsbar .wps-ar-tablist').filter(function () {
                    return $(this).attr('data-wps-tabs-owner') === tabsId;
                }).first()
                : $();
            const $tabList = $externalTabList.length ? $externalTabList : $tab.children('.wps-ar-tablist').first();
            const $tabLinks = $tabList.find('li[aria-controls]');
            const $tabContents = $tab.find('.wps-ar-tabcontent');
            const hash = window.location.hash.substring(1);
            let hasSelected = false;

            $tabContents.each(function () {
                const $this = $(this), id = $this.attr('id');
                const $link = $tabLinks.filter(`[aria-controls="${id}"]`).first();
                if (hash === id && $this.attr('aria-disabled') !== 'true' && $link.attr('aria-disabled') !== 'true') {
                    hasSelected = true;
                    $link.attr('aria-selected', 'true');
                    $this.attr({'aria-hidden': 'false', 'aria-selected': 'true'});
                } else {
                    $this.attr({'aria-hidden': 'true', 'aria-selected': 'false'});
                }
            });

            if (!hasSelected) {
                const $first = $tabLinks.filter(':not([aria-disabled="true"])').first();
                const firstTarget = $first.attr('aria-controls');
                if (firstTarget) {
                    $tabLinks.attr('aria-selected', 'false');
                    $tabContents.attr({'aria-hidden': 'true', 'aria-selected': 'false'});
                    $first.attr('aria-selected', 'true');
                    $tabContents.filter('#' + firstTarget).attr({'aria-hidden': 'false', 'aria-selected': 'true'});
                }
            }

            $tabList.on('click', 'li[aria-controls]:not([aria-disabled="true"])', function (e) {
                e.preventDefault();
                const $this = $(this), targetId = $this.attr('aria-controls');
                $tabLinks.attr('aria-selected', 'false');
                $tabContents.attr({'aria-hidden': 'true', 'aria-selected': 'false'});
                $this.attr('aria-selected', 'true');
                $('#' + targetId).attr({'aria-hidden': 'false', 'aria-selected': 'true'});
                history.pushState(null, null, location.pathname + location.search + '#' + targetId);
                animateTabPanel(targetId);
            });
        });

        if (window.location.hash) {
            animateTabPanel(window.location.hash.substring(1));
        }

        $(".wps-core-settings-page form[action='options.php'], .wps-ar-tabcontent form[action='options.php'], #wps-options[action='options.php']").each(function () {
            initWpsAutosaveForm(this);
        });
        initWpsModuleReset();

        // Init switches
        $('.wps-apple-switch').each(function () {
            handleDependent($(this), this.checked);
        });

        // Beforeunload warning
        $window.on('beforeunload', function (e) {
            if ($body?.hasClass('wps-doingAction')) {
                const msg = wps.locale.get('text_close_warning');
                (e || window.event).returnValue = msg;
                return msg;
            }
        });
    });

})(jQuery);
