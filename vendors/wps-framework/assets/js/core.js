/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

"use strict";

(function ($, window, noGlobal) {
    if (!$) return null;

    let guid = 1,
        semaphoreList = {},
        cacheKeys = [],
        wpsCore = {ux: {}, options: {}, cache: {}};

    const toString = Object.prototype.toString,
        hasOwn = Object.prototype.hasOwnProperty;

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

    wps.extend(wpsCore, {
        setup: function () {
            const matchLandscape = window.matchMedia("(orientation: landscape)");
            wps.addUX({
                'is-landscape': () => matchLandscape.matches,
                'is-mobile': () => {
                    const w = screen.availWidth, h = screen.availHeight;
                    return wps.getUX('is-landscape') ? (w <= 1366 || h <= 1024) : (w <= 1024 || h <= 1366);
                },
                'is-phone': () => (screen.availWidth <= 480 || screen.availHeight <= 480),
                'is-tablet': () => wps.getUX('is-mobile') && !wps.getUX('is-phone'),
                'is-laptop': () => !wps.getUX('is-mobile')
            });
        }
    });

    wps.cache = {
        add(key, value) {
            const cacheKey = key + " ";
            if (cacheKeys.push(cacheKey) > 250) {
                delete wpsCore.cache[cacheKeys.shift()];
            }
            return (wpsCore.cache[cacheKey] = value);
        },
        remove(key) {
            const cacheKey = key + " ", idx = cacheKeys.indexOf(cacheKey);
            if (idx > -1) {
                cacheKeys.splice(idx, 1);
                delete wpsCore.cache[cacheKey];
            }
        },
        get: (key, default_ = false) => {
            const cacheKey = key + " ";
            return hasOwn.call(wpsCore.cache, cacheKey) ? wpsCore.cache[cacheKey] : default_;
        }
    };

    wps.extend({
        getUID: () => guid++,
        isDefined: (value, not = false) => value !== null && value !== undefined ? (not === false || value) : not,
        isArray: function (item, not = false) {
            return this.isDefined(item) && Array.isArray(item) ? (not === false || item) : not;
        },
        isObject: function (item, not = false) {
            return this.isDefined(item) && toString.call(item) === '[object Object]' ? (not === false || item) : not;
        },
        isFunction: $.isFunction,
        isjQuery: o => !!(o && o instanceof jQuery),
        isNode: o => !!(o && (o.nodeType === 1 || o.nodeType === 9 || o.nodeType === 11)),
        isElement: o => !!(o && o.nodeType === 1),

        booleanize(string, strict = false) {
            if (!string) return false;
            if (typeof string === 'string') {
                switch (string.toLowerCase().trim()) {
                    case "true":
                    case "si":
                    case "yes":
                    case "1":
                    case "on":
                        return true;
                    case "false":
                    case "no":
                    case "0":
                    case "off":
                        return false;
                }
            }
            return strict ? string === true : Boolean(string);
        },

        removeEmpty(item, deFault = null, strict = false) {
            if (!this.isDefined(item)) return deFault;
            if (this.isObject(item)) {
                if ($.isEmptyObject(item)) return deFault;
                for (const propName in item) {
                    if (hasOwn.call(item, propName)) {
                        item[propName] = this.removeEmpty(item[propName], null, strict);
                        if (!this.isDefined(item[propName])) delete item[propName];
                    }
                }
                return $.isEmptyObject(item) ? deFault : item;
            }
            if (this.isArray(item)) {
                if (!item.length) return deFault;
                const filtered = item
                    .map(el => this.removeEmpty(el, null, strict))
                    .filter(el => this.isDefined(el) && (!this.isArray(el) || el.length > 0));
                return filtered.length ? filtered : deFault;
            }
            return (!strict || this.booleanize(item)) ? item : deFault;
        },

        parse_args_deep(deFault, ...sources) {
            for (const source of sources) {
                if (this.isObject(deFault) && this.isObject(source)) {
                    for (const key in source) {
                        if (hasOwn.call(source, key)) {
                            deFault[key] = this.isObject(source[key])
                                ? this.parse_args_deep(deFault[key] || {}, source[key])
                                : source[key];
                        }
                    }
                }
            }
            return deFault;
        },

        parse_args: function (deFault, ...sources) {
            return this.isObject(deFault) ? Object.assign(deFault, ...sources.filter(s => this.isObject(s))) : deFault;
        },

        filter_args_deep(deFault, ...sources) {
            for (const source of sources) {
                if (this.isObject(deFault) && this.isObject(source)) {
                    for (const key in source) {
                        if (hasOwn.call(deFault, key)) {
                            deFault[key] = this.isObject(deFault[key])
                                ? this.filter_args_deep(deFault[key], source[key] || {})
                                : source[key];
                        }
                    }
                }
            }
            return deFault;
        },

        filter_args(deFault, ...sources) {
            if (!this.isObject(deFault)) return Object.assign({}, ...sources);
            const merged = Object.assign({}, ...sources);
            for (const key in deFault) {
                if (hasOwn.call(merged, key)) deFault[key] = merged[key];
            }
            return deFault;
        },

        delete: (array, position = 0) => (delete array[position], array),

        maybe_exec(item, runtime_args = null, context = null, asFilter = false) {
            if (this.isFunction(item)) return item.call(context, runtime_args);
            if (this.isObject(item) && this.isFunction(item.callback)) {
                return item.callback.call(context, item.args, runtime_args);
            }
            return asFilter ? runtime_args : item;
        },

        serialize(obj, prefix) {
            const str = [];
            for (const p in obj) {
                if (hasOwn.call(obj, p)) {
                    const k = prefix ? `${prefix}[${p}]` : p, v = obj[p];
                    str.push(v !== null && typeof v === "object"
                        ? this.serialize(v, k)
                        : `${encodeURIComponent(k)}=${encodeURIComponent(v)}`);
                }
            }
            return str.join("&");
        },

        domPath(elem, path) {
            const parts = path.replace(/\[(\w+)]/g, '.$1').replace(/^\./, '').split('.');
            for (const k of parts) {
                if (!(k in elem)) return undefined;
                elem = elem[k];
            }
            return elem;
        },

        addUX: ux => wps.extend(wpsCore.ux, ux),
        getUX: (item, default_ = '', args = null) => hasOwn.call(wpsCore.ux, item) ? wps.maybe_exec(wpsCore.ux[item], args) : default_,
        removeUX(item) {
            if (!hasOwn.call(wpsCore.ux, item)) return false;
            delete wpsCore.ux[item];
            return true;
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

        storage: {
            add(key, value, limit = null) {
                if (limit !== null) {
                    const items = this.get(key, []);
                    items.unshift(value);
                    if (items.length > limit) items.length = limit;
                    value = items;
                }
                localStorage.setItem(key, wps.json.stringify(value));
            },
            get: (key, deFault = {}) => wps.json.parse(localStorage.getItem(key), deFault),
            remove(key, index = null) {
                const storage = this.get(key, []);
                if (index !== null) {
                    const removed = storage.splice(index, 1);
                    this.add(key, storage);
                    return removed;
                }
                localStorage.removeItem(key);
                return storage;
            }
        },

        hash(string, length = 12) {
            const dictionary = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
            const hash = new Array(length);
            let seed = 0x1539;
            const len = string.length;
            if (len) {
                const half = len >> 1;
                seed = ((seed << 5) - seed + len + string.charCodeAt(0)) | 0;
                for (let i = 0; i < half; i++) {
                    seed = ((seed << 5) - seed) + string.charCodeAt(i) - string.charCodeAt(len - i - 1);
                }
            }
            for (let i = 0; i < length; i++) {
                seed = (214013 * seed + 2531011) >>> 2;
                hash[i] = dictionary[seed % 62];
            }
            return hash.join('');
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
                mod: 'none', mod_action: 'none', mod_nonce: '', mod_args: '', mod_form: '',
                use_loading: false, callback: null
            }, options);

            wps.semaphore.lock(options.mod_action);
            if (options.use_loading) options.use_loading.addClass("wps-loader");

            $.ajax({
                url: ajaxurl, type: "GET", dataType: "json", global: false, cache: false,
                data: {
                    action: 'wps', mod: options.mod, mod_action: options.mod_action,
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
        },

        TextBoxHighlighter(options) {
            return this.each(function () {
                const $this = $(this);
                let plugin = $this.data(wps.ui.textBoxHighLighter.id);
                if (plugin) plugin.destroy();
                plugin = wps.ui.textBoxHighLighter.init($this, options);
                if (plugin.isGenerated) $this.data(wps.ui.textBoxHighLighter.id, plugin);
            });
        }
    });

    wps.ui = {
        textBoxHighLighter: {
            id: 'hwt',
            init($el, config) {
                this.$el = $el;
                if (this.getType(config) === 'function') config = {highlight: config};
                if (this.getType(config) === 'custom') {
                    this.highlight = config;
                    this.generate();
                }
                return this;
            },
            getType(instance) {
                if (!instance) return 'falsey';
                if (Array.isArray(instance)) {
                    return (instance.length === 2 && typeof instance[0] === 'number' && typeof instance[1] === 'number') ? 'range' : 'array';
                }
                const type = typeof instance;
                if (type === 'object') {
                    if (instance instanceof RegExp) return 'regexp';
                    if (hasOwn.call(instance, 'highlight')) return 'custom';
                }
                return (type === 'function' || type === 'string') ? type : 'other';
            },
            generate() {
                const id = this.id;
                this.$el.addClass(`${id}-input ${id}-content`)
                    .on(`input.${id}`, this.handleInput.bind(this))
                    .on(`scroll.${id}`, this.handleScroll.bind(this));
                this.$highlights = $('<div>', {class: `${id}-highlights ${id}-content`});
                this.$backdrop = $('<div>', {class: `${id}-backdrop`}).append(this.$highlights);
                this.$container = $('<div>', {class: `${id}-container`})
                    .insertAfter(this.$el).append(this.$backdrop, this.$el)
                    .on('scroll', this.blockContainerScroll.bind(this));
                this.browser = this.detectBrowser();
                if (this.browser === 'firefox') this.fixFirefox();
                else if (this.browser === 'ios') this.fixIOS();
                this.isGenerated = true;
                this.handleInput();
            },
            detectBrowser() {
                const ua = navigator.userAgent.toLowerCase();
                if (ua.includes('firefox')) return 'firefox';
                if (/msie|trident\/7|edge/.test(ua)) return 'ie';
                if (/ipad|iphone|ipod/.test(ua) && !ua.includes('windows phone')) return 'ios';
                return 'other';
            },
            fixFirefox() {
                const p = this.$highlights.css(['padding-top', 'padding-right', 'padding-bottom', 'padding-left']);
                const b = this.$highlights.css(['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width']);
                this.$highlights.css({padding: 0, 'border-width': 0});
                this.$backdrop.css({
                    'margin-top': `+=${p['padding-top']}`, 'margin-right': `+=${p['padding-right']}`,
                    'margin-bottom': `+=${p['padding-bottom']}`, 'margin-left': `+=${p['padding-left']}`
                }).css({
                    'margin-top': `+=${b['border-top-width']}`, 'margin-right': `+=${b['border-right-width']}`,
                    'margin-bottom': `+=${b['border-bottom-width']}`, 'margin-left': `+=${b['border-left-width']}`
                });
            },
            fixIOS() {
                this.$highlights.css({'padding-left': '+=3px', 'padding-right': '+=3px'});
            },
            handleInput() {
                const input = this.$el.val();
                const ranges = this.getRanges(input, this.highlight);
                const unstaggered = this.removeStaggeredRanges(ranges);
                this.renderMarks(this.getBoundaries(unstaggered));
            },
            getRanges(input, highlight) {
                const type = this.getType(highlight);
                const handlers = {
                    array: () => highlight.flatMap(h => this.getRanges(input, h)),
                    function: () => this.getRanges(input, highlight(input)),
                    regexp: () => {
                        const ranges = [];
                        let match;
                        while ((match = highlight.exec(input)) !== null) {
                            ranges.push([match.index, match.index + match[0].length]);
                            if (!highlight.global) break;
                        }
                        return ranges;
                    },
                    string: () => {
                        const ranges = [], inputLower = input.toLowerCase(), strLower = highlight.toLowerCase();
                        let idx = 0;
                        while ((idx = inputLower.indexOf(strLower, idx)) !== -1) {
                            ranges.push([idx, idx + strLower.length]);
                            idx += strLower.length;
                        }
                        return ranges;
                    },
                    range: () => [highlight],
                    custom: () => {
                        const ranges = this.getRanges(input, highlight.highlight);
                        if (highlight.className) {
                            ranges.forEach(r => r.className = r.className ? `${highlight.className} ${r.className}` : highlight.className);
                        }
                        return ranges;
                    }
                };
                return handlers[type]?.() || [];
            },
            removeStaggeredRanges(ranges) {
                const unstaggered = [];
                ranges.forEach(range => {
                    const isStaggered = unstaggered.some(ur => {
                        const startIn = range[0] > ur[0] && range[0] < ur[1];
                        const stopIn = range[1] > ur[0] && range[1] < ur[1];
                        return startIn !== stopIn;
                    });
                    if (!isStaggered) unstaggered.push(range);
                });
                return unstaggered;
            },
            getBoundaries(ranges) {
                const boundaries = [];
                ranges.forEach(r => {
                    boundaries.push({type: 'start', index: r[0], className: r.className});
                    boundaries.push({type: 'stop', index: r[1]});
                });
                boundaries.sort((a, b) => {
                    if (a.index !== b.index) return b.index - a.index;
                    if (a.type === 'stop' && b.type === 'start') return 1;
                    if (a.type === 'start' && b.type === 'stop') return -1;
                    return 0;
                });
                return boundaries;
            },
            renderMarks(boundaries) {
                let input = this.$el.val();
                boundaries.forEach((b, i) => {
                    const markup = b.type === 'start' ? `{{hwt-mark-start|${i}}}` : '{{hwt-mark-stop}}';
                    input = input.slice(0, b.index) + markup + input.slice(b.index);
                });
                input = input.replace(/\n(\{\{hwt-mark-stop}})?$/, '\n\n$1').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                if (this.browser === 'ie') input = input.replace(/ /g, ' <wbr>');
                input = input
                    .replace(/\{\{hwt-mark-start\|(\d+)}}/g, (_, idx) => {
                        const cn = boundaries[+idx].className;
                        return cn ? `<mark class="${cn}">` : '<mark>';
                    })
                    .replace(/\{\{hwt-mark-stop}}/g, '</mark>');
                this.$highlights.html(input);
            },
            handleScroll() {
                this.$backdrop.scrollTop(this.$el.scrollTop());
                const scrollLeft = this.$el.scrollLeft();
                this.$backdrop.css('transform', scrollLeft > 0 ? `translateX(${-scrollLeft}px)` : '');
            },
            blockContainerScroll() {
                this.$container.scrollLeft(0);
            },
            destroy() {
                const id = this.id;
                this.$backdrop.remove();
                this.$el.unwrap().removeClass(`${id}-text ${id}-input`).off(id).removeData(id);
            }
        },

        popup: {
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
            render(options) {
                options = wps.parse_args({
                    title: false, body: false, restore: true, parseElement: false,
                    beforeAppend: null, afterAppend: null, beforeClose: null, afterClose: null,
                    size: 'small', message: false, remove: true
                }, options);
                if (options.message) {
                    alert(options.message);
                    return;
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

    wps.clipboard = {
        write(value = window.location.href) {
            if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(value).catch(() => this._legacyWrite(value));
            } else {
                this._legacyWrite(value);
            }
        },
        _legacyWrite(value) {
            const input = document.createElement('input');
            document.body.appendChild(input);
            input.value = value;
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        },
        read: () => navigator.clipboard?.readText() ?? Promise.reject('Clipboard API not available')
    };

    if (typeof noGlobal === "undefined") window.wps = wps;
    return wps;

})(jQuery, typeof window !== "undefined" ? window : this);

// Document ready
(function ($) {
    let $window, $body, media_uploader;
    let wpoptAutosaveNonce = null;

    function wpoptStatusText(key, fallback) {
        return wps.locale.get(key, fallback);
    }

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

    function initWpoptAutosaveForm(form) {
        const $form = $(form);

        if (!wpoptAutosaveNonce) return;
        if ($form.data("wpopt-autosave-init")) return;

        $form.data("wpopt-autosave-init", true);

        let lastSaved = $form.serialize();
        let timer = null;
        let inFlight = false;
        let queued = false;

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
                mod_nonce: wpoptAutosaveNonce,
                mod_form: snapshot,
                callback(data, state) {
                    inFlight = false;

                    if (state === "success") {
                        lastSaved = snapshot;
                        showWpoptToast("success", data?.text || wpoptStatusText("autosaved", "All changes saved"));
                    } else {
                        showWpoptToast("error", data?.text || wpoptStatusText("autosave_failed", "Autosave failed"));
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

        $form.on("change input", ":input:not([type='submit']):not([type='button']):not([type='hidden'])", debounceSave);
        $form.on("submit", function (e) {
            e.preventDefault();
            doSave();
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

    $(function () {
        $window = $(window);
        $body = $('body');
        wpoptAutosaveNonce = wps.locale.get("wpopt_ajax_nonce", "");

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
            .on('click', '.wps-dropdown__opener', function (e) {
                e.preventDefault();
                const $dropdown = $(this).closest('.wps-dropdown');
                $dropdown.find('.wps-multiselect__wrapper').slideToggle();
                $dropdown.toggleClass('is-open');
            })
            .on('click', '.wps-dropdown.is-open li', function (e) {
                e.stopPropagation();
                const $dropdown = $(this).closest('.wps-dropdown');
                const input = $dropdown.find('input');
                input.val($(this).data('value'));
                $dropdown.find(`[data-input="${input.attr('id')}"]`).text($(this).text());
                $dropdown.find('.wps-multiselect__wrapper').slideToggle();
                $dropdown.toggleClass('is-open');
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
            .on('click change', '.wps-apple-switch', function () {
                handleDependent($(this), this.checked);
            });

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

        // Tabs
        $('.wps-ar-tabs').each(function () {
            const $tab = $(this);
            const $tabLinks = $tab.find('li[aria-controls]');
            const $tabContents = $tab.find('.wps-ar-tabcontent');
            const hash = window.location.hash.substring(1);
            let hasSelected = false;

            $tabContents.each(function () {
                const $this = $(this), id = $this.attr('id');
                if (hash === id && $this.attr('aria-disabled') !== 'true') {
                    hasSelected = true;
                    $tabLinks.filter(`[aria-controls="${id}"]`).attr('aria-selected', 'true');
                    $this.attr({'aria-hidden': 'false', 'aria-selected': 'true'});
                } else {
                    $this.attr('aria-hidden', 'true');
                }
            });

            if (!hasSelected) {
                const $first = $tab.find('li[aria-controls]:not([aria-disabled="true"]):first');
                $first.attr('aria-selected', 'true');
                $tabContents.filter('#' + $first.attr('aria-controls')).attr('aria-hidden', 'false');
            }

            $tab.find('ul').on('click', 'li[aria-controls]:not([aria-disabled="true"])', function (e) {
                e.preventDefault();
                const $this = $(this), targetId = $this.attr('aria-controls');
                $tabLinks.attr('aria-selected', 'false');
                $tabContents.attr('aria-hidden', 'true');
                $this.attr('aria-selected', 'true');
                $('#' + targetId).attr('aria-hidden', 'false');
                history.pushState(null, null, location.pathname + location.search + '#' + targetId);
                animateTabPanel(targetId);
            });
        });

        if (window.location.hash) {
            animateTabPanel(window.location.hash.substring(1));
        }

        $(".wps-ar-tabcontent form[action='options.php']").each(function () {
            initWpoptAutosaveForm(this);
        });

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
