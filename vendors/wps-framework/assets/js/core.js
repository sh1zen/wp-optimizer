/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

"use strict";

(function ($, window, noGlobal) {

    if (!$ || typeof $ === 'undefined' || typeof $ === undefined) {
        return null;
    }

    let guid = 1, semaphoreList = {}, wpsCore = {};

    // Define a local copy of wps
    let wps = function (selector, context) {

        // The wps object is actually just the init constructor 'enhanced'
        // Need init if wps is called (just allow error to be thrown if not included)
        return new wps.fn.init(selector, context);
    };

    let locales = {};

    wps.locale = {

        add: function ($locale = {}) {
            wps.parse_args(locales, wps.json.parse($locale))
        },

        get: function ($locale, $default = '') {
            if (!$locale) {
                return locales;
            }
            return locales[$locale] || $default;
        },
    }

    wps.fn = $.fn;
    wps.prototype = $.prototype;
    wps.extend = wps.fn.extend = $.extend;

    wps.extend(wpsCore, {

        ux: {},
        options: {},
        cache: {},

        setup: function () {

            wps.addUX({
                'is-landscape': function () {
                    return window.matchMedia("(orientation: landscape)").matches
                },
                'is-mobile': function () {
                    return wps.getUX('is-landscape') ? (screen.availWidth <= 1366) || (screen.availHeight <= 1024) : (screen.availWidth <= 1024) || (screen.availHeight <= 1366);
                },
                'is-phone': function () {
                    return (screen.availWidth <= 480) || (screen.availHeight <= 480)
                },
                'is-tablet': function () {
                    return wps.getUX('is-mobile') && !wps.getUX('is-phone')
                },
                'is-laptop': function () {
                    return !wps.getUX('is-mobile')
                }
            });
        }
    });

    wps.cache = {

        add: function (key, value) {
            // Use (key + " ") to avoid collision with native prototype properties
            if (wpsCore.cache.push(key + " ") > 250) {

                // Only keep the most recent entries
                delete [wpsCore.cache.shift()];
            }

            return (wpsCore.cache[key + " "] = value);
        },
        remove: function (key) {

            delete [wpsCore.cache[key + " "]];
        },
        get: function (key, default_ = false) {

            if (typeof wpsCore.cache[key + " "] !== "undefined") {
                return wpsCore.cache[key + " "];
            }

            return default_;
        },
    }

    wps.fn = $.fn;
    wps.prototype = $.prototype;
    wps.extend = wps.fn.extend = $.extend;

    wps.extend({

        getUID: function () {
            return guid++;
        },

        isDefined: function (value, not = false) {
            return !(value === null || typeof value === 'undefined' || typeof value === undefined) ? (not === false ? true : value) : not;
        },

        isArray: function (item, not = false) {
            return this.isDefined(item) && (typeof item === 'object' && Array.isArray(item)) ? (not === false ? true : item) : not
        },

        isObject: function (item, not = false) {
            return this.isDefined(item) && (typeof item === 'object' && !Array.isArray(item)) ? (not === false ? true : item) : not
        },

        isFunction: function (obj) {
            return $.isFunction(obj)
        },

        isjQuery: function (o) {
            return (
                typeof !!o && typeof o === "object" && o instanceof jQuery
            );
        },

        isNode: function (o) {
            return (
                typeof Node === "object" ? o instanceof Node :
                    !!o && typeof o === "object" && typeof o.nodeType === "number" && typeof o.nodeName === "string"
            );
        },

        isElement: function isElement(o) {
            return (
                typeof HTMLElement === "object" ? o instanceof HTMLElement : //DOM2
                    !!o && typeof o === "object" && o.nodeType === 1 && typeof o.nodeName === "string"
            );
        },

        booleanize: function (string, strict = false) {

            if (!string) {
                return false;
            }

            if (typeof string === 'string') {
                string = string.toLowerCase().trim();
            }

            switch (string) {
                case "true":
                case "yes":
                case "1":
                case "on":
                    return true;
                case "false":
                case "no":
                case "0":
                case "off":
                case null:
                    return false;

                default:
                    return strict ? string === true : Boolean(string);
            }
        },

        removeEmpty: function (item, deFault = null, strict = false) {
            let res = null;

            if (this.isDefined(item)) {

                if (this.isObject(item) && !$.isEmptyObject(item)) {

                    for (let propName in item) {

                        item[propName] = wps.removeEmpty(item[propName], null, strict);

                        if (!this.isDefined(item[propName])) {
                            delete item[propName];
                        }
                    }

                    if (!$.isEmptyObject(item)) {
                        res = item;
                    }

                } else if (this.isArray(item)) {
                    if (item.length > 0) {

                        res = item.map(function (el) {
                            return wps.removeEmpty(el, null, strict);
                        }).filter(function (el) {
                            return wps.isDefined(el) && (wps.isArray(el) ? el.length > 0 : true);
                        });

                    }
                    //} else if (strict ? this.booleanize(item) : item !== false) {
                } else if (!strict || (strict && this.booleanize(item))) {
                    res = item;
                }
            }

            return wps.isDefined(res, deFault);
        },


        parse_args_deep: function (deFault, ...sources) {
            if (!sources.length) return deFault;
            const source = sources.shift();

            if (this.isObject(deFault) && this.isObject(source)) {
                for (const key in source) {
                    if (this.isObject(source[key])) {
                        deFault[key] = this.parse_args_deep(deFault[key] || {}, source[key]);
                    } else {
                        Object.assign(deFault, {[key]: source[key]});
                    }
                }
            }

            return this.parse_args_deep(deFault, ...sources);
        },

        parse_args: function (deFault, ...sources) {
            if (!sources.length) return deFault;
            const source = sources.shift();

            if (this.isObject(deFault) && this.isObject(source)) {
                Object.assign(deFault, source);
            }

            return this.parse_args(deFault, ...sources);
        },

        filter_args_deep: function (deFault, ...sources) {
            if (!sources.length) return deFault;
            const source = sources.shift();

            if (this.isObject(deFault) && this.isObject(source)) {
                for (const key in source) {
                    if (key in deFault) {
                        if (this.isObject(deFault[key])) {
                            if (!source[key])
                                Object.assign(source, {[key]: {}});
                            this.filter_args_deep(deFault[key], source[key]);
                        } else {
                            Object.assign(deFault, {[key]: source[key]});
                        }
                    }
                }
            }

            return this.filter_args_deep(deFault, ...sources);
        },

        filter_args: function (deFault, ...sources) {

            if (!this.isObject(deFault))
                return Object.assign({}, ...sources);

            let merged = Object.assign({}, ...sources);

            for (const key in deFault) {
                if (key in merged) {
                    Object.assign(deFault, {[key]: merged[key]});
                }
            }

            return deFault;
        },

        delete: function (array, position = 0) {
            delete array[position];
            return array;
        },

        maybe_exec: function (item, runtime_args = null, context = null, asFilter = false) {

            if (wps.isFunction(item)) {
                return item.call(context, runtime_args);
            }

            if (wps.isObject(item) && wps.isFunction(item.callback)) {
                // context used for this, runtime args, static args
                return item.callback.call(context, item.args, runtime_args)
            }

            return asFilter ? runtime_args : item;
        },

        serialize: function (obj, prefix) {
            let str = [],
                p;
            for (p in obj) {
                if (obj.hasOwnProperty(p)) {
                    let k = prefix ? prefix + "[" + p + "]" : p,
                        v = obj[p];
                    str.push((v !== null && typeof v === "object") ?
                        this.serialize(v, k) :
                        encodeURIComponent(k) + "=" + encodeURIComponent(v));
                }
            }
            return str.join("&");
        },

        domPath: function (elem, path) {
            // convert indexes to properties
            // strip a leading dot
            path = path.replace(/\[(\w+)]/g, '.$1').replace(/^\./, '').split('.');
            let k, i;
            for (i = 0; i < path.length; i++) {
                k = path[i];
                if (k in elem) {
                    elem = elem[k];
                } else {
                    return;
                }
            }
            return elem;
        },

        addUX: function (ux) {
            wps.extend(wpsCore.ux, ux);
        },

        getUX: function (item, default_ = '', args = null) {

            if (typeof wpsCore.ux[item] === "undefined")
                return default_;

            return wps.maybe_exec(wpsCore.ux[item], args)
        },

        removeUX: function (item) {

            if (typeof wpsCore.ux[item] === "undefined")
                return false;

            delete wpsCore.ux[item];
        },

        json: {
            stringify: JSON.stringify,

            parse: function (data, deFault) {

                if (wps.isObject(data))
                    return data;

                let parsed = deFault;

                if (data) {
                    try {
                        parsed = JSON.parse(data);
                    } catch (e) {
                        parsed = data;
                    }
                }

                return parsed || deFault;
            }
        },

        storage: {
            add: function (key, value, limit = null) {

                if (limit !== null) {

                    let items = this.get(key, []);

                    items.unshift(value);

                    while (items.length > limit)
                        items.pop();

                    value = items;
                }

                localStorage.setItem(key, wps.json.stringify(value));
            },
            get: function (key, deFault = {}) {
                return wps.json.parse(localStorage.getItem(key), deFault);
            },
            remove: function (key, index = null) {

                let storage = this.get(key, []);

                if (index !== null) {
                    let removed = storage.splice(index, 1);
                    this.add(key, storage);
                    return removed;
                }

                localStorage.removeItem(key);

                return storage;
            },
        },

        hash: function (string, length = 12) {

            let dictionary = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789",
                hash = [], i, seed = 0x1539,
                stringLength = string.length;

            if (stringLength) {

                let stringLength2 = Math.floor(stringLength / 2)

                seed = (((seed << 5) - seed) + stringLength + string.charCodeAt(0)) | 0;

                for (i = 0; i < stringLength2; i++) {

                    seed = ((seed << 5) - seed) + string.charCodeAt(i) - string.charCodeAt(stringLength - i - 1);
                }
            }

            for (i = 0; i < length; i++) {
                seed = (214013 * seed + 2531011) >>> 2;
                hash[i] = dictionary[seed % 62];
            }

            return hash.join('');
        },

        semaphore: {

            release: function (context = 'core') {
                semaphoreList[context] = false;
            },

            lock: function (context = 'core') {
                semaphoreList[context] = true;
            },

            is_locked: function (context = 'core') {
                return semaphoreList[context] || false;

            },
        },

        ajaxHandler: function (options) {

            let defaults = {
                mod: 'none',
                mod_action: 'none',
                mod_nonce: '',
                mod_args: '',
                mod_form: '',
                use_loading: false,
                callback: null
            };

            options = wps.parse_args(defaults, options);

            wps.semaphore.lock(options.mod_action);

            if (options.use_loading) {
                options.use_loading.addClass("wps-loader");
            }

            jQuery.ajax({
                url: ajaxurl,
                type: "GET",
                dataType: "json",
                global: false,
                cache: false,
                data: {
                    action: 'wps',
                    mod: options.mod,
                    mod_action: options.mod_action,
                    mod_nonce: options.mod_nonce,
                    mod_args: options.mod_args,
                    mod_form: options.mod_form,
                },
                complete: function (jqXHR, status) {

                    if (typeof options.callback === "function") {

                        let res = wps.json.parse(jqXHR.responseText);

                        if (!res) {
                            res = jqXHR.responseText;
                        }

                        setTimeout(options.callback(res.data, res.status), 100);
                    }

                    if (options.use_loading) {
                        options.use_loading.removeClass("wps-loader");
                    }

                    wps.semaphore.release(options.mod_action);
                }
            });
        }
    });

    wps.fn.extend({

        remove_query_arg: function (paramKey) {

            let url = new URL(window.location.href);

            const keysToRemove = Array.from(url.searchParams.keys()).filter(key => key.replace(/\[.*]/g, "") === paramKey);

            keysToRemove.forEach(key => url.searchParams.delete(key));

            if (keysToRemove.length) {
                // Update the browser history without triggering a redirect
                window.history.replaceState({}, document.title, url.href);
            }
        },

        addNotice: function (response, status) {

            let $this = $(this), text = response.text || response;

            if (typeof text !== 'string') {
                text = wps.locale.get(status, 'Request processed.');
            }

            $this.append("<p class='" + status + "'>" + text + "</p>");

            if (response.list) {
                for (let data of response.list) {
                    $this.append("<p class='" + data.status + "'>" + data.text + "</p>");
                }
            }
        },

        TextBoxHighlighter: function (options) {
            return this.each(function () {
                let $this = $(this),
                    plugin = $this.data(wps.ui.textBoxHighLighter.id);

                if (plugin) {
                    plugin.destroy();
                }

                plugin = wps.ui.textBoxHighLighter.init($this, options);

                if (plugin.isGenerated) {
                    $this.data(wps.ui.textBoxHighLighter.id, plugin);
                }

            });
        }
    });

    wps.ui = {

        textBoxHighLighter: {

            id: 'hwt',

            init: function ($el, config) {
                this.$el = $el;

                // backwards compatibility with v1 (deprecated)
                if (this.getType(config) === 'function') {
                    config = {highlight: config};
                }

                if (this.getType(config) === 'custom') {
                    this.highlight = config;
                    this.generate();
                } else {
                    console.error('valid config object not provided');
                }
                return this;
            },

            // returns identifier strings that aren't necessarily "real" JavaScript types
            getType: function (instance) {
                let type = typeof instance;
                if (!instance) {
                    return 'falsey';
                } else if (Array.isArray(instance)) {
                    if (instance.length === 2 && typeof instance[0] === 'number' && typeof instance[1] === 'number') {
                        return 'range';
                    } else {
                        return 'array';
                    }
                } else if (type === 'object') {
                    if (instance instanceof RegExp) {
                        return 'regexp';
                    } else if (instance.hasOwnProperty('highlight')) {
                        return 'custom';
                    }
                } else if (type === 'function' || type === 'string') {
                    return type;
                }

                return 'other';
            },

            generate: function () {
                this.$el
                    .addClass(wps.ui.textBoxHighLighter.id + '-input ' + wps.ui.textBoxHighLighter.id + '-content')
                    .on('input.' + wps.ui.textBoxHighLighter.id, this.handleInput.bind(this))
                    .on('scroll.' + wps.ui.textBoxHighLighter.id, this.handleScroll.bind(this));

                this.$highlights = $('<div>', {class: wps.ui.textBoxHighLighter.id + '-highlights ' + wps.ui.textBoxHighLighter.id + '-content'});

                this.$backdrop = $('<div>', {class: wps.ui.textBoxHighLighter.id + '-backdrop'})
                    .append(this.$highlights);

                this.$container = $('<div>', {class: wps.ui.textBoxHighLighter.id + '-container'})
                    .insertAfter(this.$el)
                    .append(this.$backdrop, this.$el) // moves $el into $container
                    .on('scroll', this.blockContainerScroll.bind(this));

                this.browser = this.detectBrowser();
                switch (this.browser) {
                    case 'firefox':
                        this.fixFirefox();
                        break;
                    case 'ios':
                        this.fixIOS();
                        break;
                }

                // plugin function checks this for success
                this.isGenerated = true;

                // trigger input event to highlight any existing input
                this.handleInput();
            },

            // browser sniffing sucks, but there are browser-specific quirks to handle
            // that are not a matter of feature detection
            detectBrowser: function () {
                let ua = window.navigator.userAgent.toLowerCase();
                if (ua.indexOf('firefox') !== -1) {
                    return 'firefox';
                } else if (!!ua.match(/msie|trident\/7|edge/)) {
                    return 'ie';
                } else if (!!ua.match(/ipad|iphone|ipod/) && ua.indexOf('windows phone') === -1) {
                    // Windows Phone flags itself as "like iPhone", thus the extra check
                    return 'ios';
                } else {
                    return 'other';
                }
            },

            // Firefox doesn't show text that scrolls into the padding of a textarea, so
            // rearrange a couple box models to make highlights behave the same way
            fixFirefox: function () {
                // take padding and border pixels from highlights div
                let padding = this.$highlights.css([
                    'padding-top', 'padding-right', 'padding-bottom', 'padding-left'
                ]);
                let border = this.$highlights.css([
                    'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width'
                ]);
                this.$highlights.css({
                    'padding': '0',
                    'border-width': '0'
                });

                this.$backdrop
                    .css({
                        // give padding pixels to backdrop div
                        'margin-top': '+=' + padding['padding-top'],
                        'margin-right': '+=' + padding['padding-right'],
                        'margin-bottom': '+=' + padding['padding-bottom'],
                        'margin-left': '+=' + padding['padding-left'],
                    })
                    .css({
                        // give border pixels to backdrop div
                        'margin-top': '+=' + border['border-top-width'],
                        'margin-right': '+=' + border['border-right-width'],
                        'margin-bottom': '+=' + border['border-bottom-width'],
                        'margin-left': '+=' + border['border-left-width'],
                    });
            },

            // iOS adds 3px of (unremovable) padding to the left and right of a textarea,
            // so adjust highlights div to match
            fixIOS: function () {
                this.$highlights.css({
                    'padding-left': '+=3px',
                    'padding-right': '+=3px'
                });
            },

            handleInput: function () {
                let input = this.$el.val();
                let ranges = this.getRanges(input, this.highlight);
                let unstaggeredRanges = this.removeStaggeredRanges(ranges);
                let boundaries = this.getBoundaries(unstaggeredRanges);
                this.renderMarks(boundaries);
            },

            getRanges: function (input, highlight) {
                let type = this.getType(highlight);
                switch (type) {
                    case 'array':
                        return this.getArrayRanges(input, highlight);
                    case 'function':
                        return this.getFunctionRanges(input, highlight);
                    case 'regexp':
                        return this.getRegExpRanges(input, highlight);
                    case 'string':
                        return this.getStringRanges(input, highlight);
                    case 'range':
                        return this.getRangeRanges(input, highlight);
                    case 'custom':
                        return this.getCustomRanges(input, highlight);
                    default:
                        if (!highlight) {
                            // do nothing for falsey values
                            return [];
                        } else {
                            console.error('unrecognized highlight type');
                        }
                }
            },

            getArrayRanges: function (input, arr) {
                let ranges = arr.map(this.getRanges.bind(this, input));
                return Array.prototype.concat.apply([], ranges);
            },

            getFunctionRanges: function (input, func) {
                return this.getRanges(input, func(input));
            },

            getRegExpRanges: function (input, regex) {
                let ranges = [];
                let match;
                while (match = regex.exec(input), match !== null) {
                    ranges.push([match.index, match.index + match[0].length]);
                    if (!regex.global) {
                        // non-global regexes do not increase lastIndex, causing an infinite loop,
                        // but we can just break manually after the first match
                        break;
                    }
                }
                return ranges;
            },

            getStringRanges: function (input, str) {
                let ranges = [];
                let inputLower = input.toLowerCase();
                let strLower = str.toLowerCase();
                let index = 0;
                while (index = inputLower.indexOf(strLower, index), index !== -1) {
                    ranges.push([index, index + strLower.length]);
                    index += strLower.length;
                }
                return ranges;
            },

            getRangeRanges: function (input, range) {
                return [range];
            },

            getCustomRanges: function (input, custom) {
                let ranges = this.getRanges(input, custom.highlight);
                if (custom.className) {
                    ranges.forEach(function (range) {
                        // persist class name as a property of the array
                        if (range.className) {
                            range.className = custom.className + ' ' + range.className;
                        } else {
                            range.className = custom.className;
                        }
                    });
                }
                return ranges;
            },

            // prevent staggered overlaps (clean nesting is fine)
            removeStaggeredRanges: function (ranges) {
                let unstaggeredRanges = [];
                ranges.forEach(function (range) {
                    let isStaggered = unstaggeredRanges.some(function (unstaggeredRange) {
                        let isStartInside = range[0] > unstaggeredRange[0] && range[0] < unstaggeredRange[1];
                        let isStopInside = range[1] > unstaggeredRange[0] && range[1] < unstaggeredRange[1];
                        return isStartInside !== isStopInside; // xor
                    });
                    if (!isStaggered) {
                        unstaggeredRanges.push(range);
                    }
                });
                return unstaggeredRanges;
            },

            getBoundaries: function (ranges) {
                let boundaries = [];
                ranges.forEach(function (range) {
                    boundaries.push({
                        type: 'start',
                        index: range[0],
                        className: range.className
                    });
                    boundaries.push({
                        type: 'stop',
                        index: range[1]
                    });
                });

                this.sortBoundaries(boundaries);
                return boundaries;
            },

            sortBoundaries: function (boundaries) {
                // backwards sort (since marks are inserted right to left)
                boundaries.sort(function (a, b) {
                    if (a.index !== b.index) {
                        return b.index - a.index;
                    } else if (a.type === 'stop' && b.type === 'start') {
                        return 1;
                    } else if (a.type === 'start' && b.type === 'stop') {
                        return -1;
                    } else {
                        return 0;
                    }
                });
            },

            renderMarks: function (boundaries) {
                let input = this.$el.val();
                boundaries.forEach(function (boundary, index) {
                    let markup;
                    if (boundary.type === 'start') {
                        markup = '{{hwt-mark-start|' + index + '}}';
                    } else {
                        markup = '{{hwt-mark-stop}}';
                    }
                    input = input.slice(0, boundary.index) + markup + input.slice(boundary.index);
                });

                // this keeps scrolling aligned when input ends with a newline
                input = input.replace(/\n(\{\{hwt-mark-stop}})?$/, '\n\n$1');

                // encode HTML entities
                input = input.replace(/</g, '&lt;').replace(/>/g, '&gt;');

                if (this.browser === 'ie') {
                    // IE/Edge wraps whitespace differently in a div vs textarea, this fixes it
                    input = input.replace(/ /g, ' <wbr>');
                }

                // replace start tokens with opening <mark> tags with class name
                input = input.replace(/\{\{hwt-mark-start\|(\d+)}}/g, function (match, submatch) {
                    let className = boundaries[+submatch].className;
                    if (className) {
                        return '<mark class="' + className + '">';
                    } else {
                        return '<mark>';
                    }
                });

                // replace stop tokens with closing </mark> tags
                input = input.replace(/\{\{hwt-mark-stop}}/g, '</mark>');

                this.$highlights.html(input);
            },

            handleScroll: function () {
                let scrollTop = this.$el.scrollTop();
                this.$backdrop.scrollTop(scrollTop);

                // Chrome and Safari won't break long strings of spaces, which can cause
                // horizontal scrolling, this compensates by shifting highlights by the
                // horizontally scrolled amount to keep things aligned
                let scrollLeft = this.$el.scrollLeft();
                this.$backdrop.css('transform', (scrollLeft > 0) ? 'translateX(' + -scrollLeft + 'px)' : '');
            },

            // in Chrome, page up/down in the textarea will shift stuff within the
            // container (despite the CSS), this immediately reverts the shift
            blockContainerScroll: function () {
                this.$container.scrollLeft(0);
            },

            destroy: function () {
                this.$backdrop.remove();
                this.$el
                    .unwrap()
                    .removeClass(wps.ui.textBoxHighLighter.id + '-text ' + wps.ui.textBoxHighLighter.id + '-input')
                    .off(wps.ui.textBoxHighLighter.id)
                    .removeData(wps.ui.textBoxHighLighter.id);
            },
        },

        popup: {
            close: function (elem, options = {}) {

                options = wps.parse_args({
                    remove: true,
                    restore: false,
                    beforeClose: null
                }, options);

                wps.maybe_exec(options.beforeClose, null, elem);

                let $elem = $(elem);

                $elem.closest('.wps-modalWrapper').fadeOut(400, function () {

                    if (options.restore) {
                        $elem.find('.wps-modal__content').children().detach().appendTo(options.restore);
                    }

                    if (options.remove) {
                        $elem.remove();
                    }

                    $('body').removeClass('sw-notScrollable');
                });
            },

            render: function (options) {

                options = wps.parse_args({
                    title: false,
                    body: false, //callable object args + function
                    restore: true,
                    parseElement: false,
                    beforeAppend: null,
                    afterAppend: null,
                    beforeClose: null,
                    afterClose: null,
                    size: 'small', //medium large
                    message: false,
                    remove: true
                }, options);

                if (options.message) {
                    alert(options.message);
                    return;
                }

                let modal_id = 'swModal' + wps.getUID(), bodyContent, detached = false, restoreContainer = false;

                if (wps.isjQuery(options.body)) {
                    if (options.parseElement) {
                        bodyContent = $.parseHTML(options.body.html(), document, true);
                    } else {
                        bodyContent = '';

                        if (options.restore)
                            restoreContainer = options.body.parent();

                        detached = options.body.detach();
                    }
                } else {
                    bodyContent = wps.maybe_exec(options.body, null, this)
                }

                let modal_form = '<section class="wps-modalWrapper">' +
                    '<section id="' + modal_id + '" class="wps-modal wps-modal--' + options.size + '" style="display: none">' +
                    '<span class="wps-modal__close" role="button">&times;</span>' +
                    (options.title ? '<div class="wps-modal__header">' + '<h4 class="wps-modal__title">' + options.title + '</h4>' + '</div>' : '') +
                    '<div class="wps-modal__content">' + bodyContent + '</div>' +
                    (options.bottom ? '<div class="wps-modal__bottom">' + wps.maybe_exec(options.bottom, null, this) + '</div>' : '') +
                    '</section></section>';

                if (options.beforeAppend) {
                    modal_form = wps.maybe_exec(options.beforeAppend, modal_form, this)
                }

                $('body').append(modal_form).addClass('sw-notScrollable');

                let current_modal = $("#" + modal_id);

                if (detached) {
                    current_modal.find('.wps-modal__content').append(detached);
                }

                current_modal.fadeIn(300);

                if (options.afterAppend) {
                    wps.maybe_exec(options.afterAppend, current_modal, this)
                }

                current_modal.one('click', '.wps-modal__close', function () {

                    wpsUI.popup.close(current_modal, {
                        restore: restoreContainer,
                        remove: options.remove,
                        beforeClose: {
                            callback: options.beforeClose,
                            args: current_modal
                        }
                    });

                    wps.maybe_exec({
                        callback: options.beforeClose,
                        args: current_modal
                    }, null, current_modal);

                    current_modal.off();
                });

                return modal_id;
            }
        },

        circleChart: function (percent, color, size, stroke) {
            return `<svg class="wps-progressbarCircle__chart" viewbox="0 0 36 36" width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg">
        <path class="wps-progressbarCircle__bg" stroke="#eeeeee" stroke-width="${stroke * 0.5}" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
        <path class="wps-progressbarCircle__stroke" stroke="${color}" stroke-width="${stroke}" stroke-dasharray="${percent},100" stroke-linecap="round" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
        <text class="wps-progressbarCircle__info" x="50%" y="50%" alignment-baseline="central" text-anchor="middle" font-size="8">${percent}%</text></svg>`;
        }
    }

    wps.clipboard = {

        write: function (value = window.location.href) {
            let inputDump = document.createElement('input'), hrefText = value;
            document.body.appendChild(inputDump);
            inputDump.value = hrefText;
            inputDump.select();
            document.execCommand('copy');
            document.body.removeChild(inputDump);
        },

        read: async function () {
            return await navigator.clipboard.readText();
        }
    }

    let wpsUtil = wps.utility, wpsUI = wps.ui;

    // Expose wps
    if (typeof noGlobal === "undefined") {
        window.wps = wps;
    }

    return wps;

})(jQuery, typeof window !== "undefined" ? window : this);

(function ($) {

    let $window = $(window),
        $document = $('document');

    function handleDependent($parent, visible = true, deep = true) {

        let parent = $parent.attr('id');

        $('.wps *[data-parent*="' + parent + '"]').each(function () {
            let $this = $(this),
                parents = $this.data('parent'),
                cntx = $this,
                visibleAction = parents.substr(parents.indexOf(parent) - 1, 1) === "!" ? !visible : visible;

            if (!$this.is('row')) {
                cntx = $this.closest('row');
            }

            if ($this.is('input')) {
                $this.prop("readonly", !visibleAction);
            }

            if (visibleAction) {
                cntx.removeClass('wps-disabled-blur');
            } else {
                cntx.addClass('wps-disabled-blur');
            }

            if (deep && $this.is(':checkbox')) {
                handleDependent($this, visible ? $this.prop('checked') : false, deep)
            }
        });
    }

    $document.ready(function () {

        let media_uploader;

        /**
         * handle wp media uploader
         */
        $(".wps-uploader__init").on('click', function (e) {
            e.preventDefault();
            let btn_uploader = $(this);
            if (media_uploader) {
                media_uploader.open();
                return;
            }
            media_uploader = wp.media({
                title: 'Upload media',
                library: {type: btn_uploader.data('type') || 'image'},
                multiple: false
            }).on('select', function (e) {
                // This will return the selected media from the Media Uploader, the result is an object
                let uploaded_media = media_uploader.state().get('selection').first();
                // Convert uploaded_media to a JSON object to make accessing it easier
                let media_url = uploaded_media.toJSON().url;
                // Assign the url value to the input field
                btn_uploader.parent().find('input').val(media_url);
            }).open();
        });

        $('.wps-progressbarCircle').each((i, chart) => {
            let $chart = $(chart);
            let percent = $chart.data("percent") || 0,
                color = $chart.data("color") || "var(--main-dark-color)",
                size = $chart.data("size") || 100,
                stroke = $chart.data("stroke") || 1;

            $chart.html(wps.ui.circleChart(percent, color, size, stroke));
        })

        $(".wps-collapse-handler").on("click", function () {
            let $this = $(this);
            $this.children('.wps-collapse-icon').toggleClass('wps-collapse-icon-close');
            $this.next().toggle(300);
        });

        $(".wps-dropdown__opener").on("click", function (event) {

            event.preventDefault();

            let $dropDown = $(this).closest(".wps-dropdown");

            $dropDown.find(".wps-multiselect__wrapper").slideToggle();
            $dropDown.toggleClass("is-open");

            $dropDown.off("click", "li");
            $dropDown.on("click", "li", function (e) {
                e.stopPropagation();
                let $selectedLI = $(this);

                let input = $dropDown.find("input");

                input.val($selectedLI.data('value'));
                $dropDown.find("[data-input='" + input.attr('id') + "']").text($selectedLI.text())

                $dropDown.find(".wps-multiselect__wrapper").slideToggle();
                $dropDown.toggleClass("is-open");
            });
        });

        $('.wps-ar-tabs').each(function () {

            let has_selected = false, $tab = $(this);

            let $all_tab_links = $tab.find("li[aria-controls]"),
                $all_tab_contents = $tab.find(".wps-ar-tabcontent");

            $tab.find("ul").on("click", "li[aria-controls]:not([aria-disabled='true'])", function (event) {

                let $this = $(this),
                    $hash_to_update = $this.attr("aria-controls"),
                    $tab_content_linked = $("#" + $this.attr("aria-controls"));

                // aria selected false on all links
                $all_tab_links.attr({"aria-selected": "false"});

                // add aria-hidden on all tabs contents
                $all_tab_contents.attr("aria-hidden", "true");

                // add aria selected on $this
                $this.attr({"aria-selected": "true"});

                // remove aria-hidden on tab linked
                $tab_content_linked.attr("aria-hidden", "false");

                history.pushState(null, null, location.pathname + location.search + '#' + $hash_to_update)

                event.preventDefault();
            });

            $tab.find(".wps-ar-tabcontent").each(function () {

                let attrs = {}, $this = $(this), $this_id = $this.attr("id");

                // search if hash is ON not disabled tab
                if (window.location.hash.substring(1) === $this_id && $this.attr('aria-disabled') !== 'true') {

                    has_selected = true;

                    $all_tab_links.filter("[aria-controls='" + $this_id + "']").attr("aria-selected", "true");

                    attrs["aria-hidden"] = "false";
                    attrs["aria-selected"] = "true";

                } else {
                    attrs["aria-hidden"] = "true";
                }

                $this.attr(attrs);
            });

            if (!has_selected) {

                let $first_link = $tab.find('li[aria-controls]:not([aria-disabled="true"]):first');

                $first_link.attr({"aria-selected": "true"});

                $all_tab_contents.filter("#" + $first_link.attr('aria-controls')).attr("aria-hidden", "false");
            }
        });

        $(".wps-apple-switch").each(function () {

            if (!$(this).prop('checked')) {
                handleDependent($(this), false)
            }

            $(this).on('click', function () {

                let $this = $(this);

                handleDependent($this, $this.prop("checked"))
            });
        });

        $('button[data-wps="ajax-action"]').each(function (e) {

            $(this).on('click', function (e) {

                e.preventDefault();

                let $this = $(this), $body = $('body');
                let e_args = {};

                if ($this.data('refer')) {
                    let o = $body.find('[data-referred="' + $this.data('refer') + '"]');
                    e_args[o.attr('name')] = o.val();
                }

                wps.ajaxHandler({
                    use_loader: $body,
                    mod: $this.data('mod') || $this.data('module'),
                    mod_action: $this.data('action'),
                    mod_nonce: $this.data('nonce'),
                    mod_args: wps.parse_args($this.data('args') || {}, e_args),
                    callback: function (res, status) {

                        if (typeof res === "undefined") {
                            res = {
                                title: 'Error',
                                body: 'Parsing response error.'
                            };
                        }

                        wps.ui.popup.render({
                            title: res.title || "Notice",
                            body: typeof res === 'string' ? res : (res.body || "Something went wrong."),
                            size: 'small'
                        });
                    }
                })
            });
        });

        $('icon.wps-option-info-icon').on('click', function () {
            $(this).closest('row').find('label.wps-option-info').slideToggle();
        });

    });

    $window.on('beforeunload', function (e) {
        if ($('body').hasClass('wps-doingAction')) {
            (e || window.event).returnValue = wps.locale.get('text_close_warning');
            return wps.locale.get('text_close_warning');
        }
    });

})(jQuery);