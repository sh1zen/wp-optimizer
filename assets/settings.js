function WPOPTSemaphore() {

    let list = [];

    this.release = function (context = 'def') {
        list[context] = false;
    }

    this.lock = function (context = 'def') {
        list[context] = true;
    }

    this.is_locked = function (context = 'def') {
        return list[context] === true;

    }
}

let wpopt_semaphore = new WPOPTSemaphore();

function wpopt_is_json(str) {
    try {
        return JSON.parse(str);
    } catch (e) {
        return false;
    }
}

function wpopt_ajaxHandler(options) {

    let defaults = {
        action: 'wpopt',
        womod: 'none',
        wpopt_action: 'none',
        wpopt_nonce: '',
        wpopt_args: '',
        wpopt_form: '',
        use_loading: false,
        callback: null
    };

    options = Object.assign(defaults, options);

    wpopt_semaphore.lock(options.wpopt_action);

    if (options.use_loading)
        options.use_loading.addClass("wpopt-loader");

    jQuery.ajax({
        url: ajaxurl,
        type: "GET",
        dataType: "json",
        global: false,
        cache: false,
        data: {
            action: options.action,
            womod: options.womod,
            wpopt_action: options.wpopt_action,
            wpopt_nonce: options.wpopt_nonce,
            wpopt_args: options.wpopt_args,
            wpopt_form: options.wpopt_form,
        },
        complete: function (jqXHR, status) {

            if (typeof options.callback === "function") {

                let res = wpopt_is_json(jqXHR.responseText);

                if(!res)
                    res = jqXHR.responseText;

                if(typeof res.data !== 'undefined')
                    setTimeout(options.callback(res.data, res.success), 100);
                else
                    setTimeout(options.callback(res, res.success), 100);
            }

            if (options.use_loading)
                options.use_loading.removeClass("wpopt-loader");

            wpopt_semaphore.release(options.wpopt_action);
        }
    });
}

(function ($) {

    "use strict";

    let $window = $(window),
        $document = $('document'),
        $body = $("body");

    $.fn.wpoptNotice = function (response, status) {

        let $this = $(this);

        if (status) {

            if (response.length > 0) {
                $this.append(response)
            } else
                $this.append("<p class='success'>" + WPOPT.strings.success + "</p>");

        } else {

            if (response.length > 0)
                $this.append("<p class='error'>" + response + "</p>")
            else
                $this.append("<p class='error'>" + WPOPT.strings.error + "</p>");
        }
    }

    let flex_tabHandler = function ($tabs) {

        // Store current URL hash.
        let hash = window.location.hash.substring(1);

        if (!$tabs instanceof $) {
            console.log("Error initializing flex_tabHandler");
            return;
        }

        if ($tabs.length === 0)
            return;

        let $tab_list = $tabs.find(".ar-tablist");

        if ($tab_list.length === 0)
            return;

        let form_action = 'options.php';

        /**
         * Initialize aria attr
         */
        $tab_list.each(function () {

            let $this_tab_list = $(this),
                $this_tab_list_items = $this_tab_list.children(".ar-tab"),
                $this_tab_list_links = $this_tab_list.find(".ar-tab_link");

            // roles init
            $this_tab_list.attr("role", "tablist"); // ul
            $this_tab_list_items.attr("role", "presentation"); // li
            $this_tab_list_links.attr("role", "tab"); // a

            // controls/tabindex attributes
            $this_tab_list_links.each(function () {

                let $this = $(this),
                    $href = $this.attr("href");

                if (typeof $href !== "undefined" && $href !== "" && $href !== "#") {
                    $this.attr({
                        "aria-controls": $href.replace("#", ""),
                        "tabindex": -1,
                        "aria-selected": "false"
                    });
                }

                $this.removeAttr("href");
            });
        });

        /**
         * handle tab content
         */
        $(".ar-tabcontent").attr({
            "role": "tabpanel", // contents
            "aria-hidden": "true", // all hidden
            //"tabindex": -1
        }).each(function () {
            let $this = $(this), $this_id = $this.attr("id");
            // label by link
            $this.attr("aria-labelledby", "lbl_" + $this_id);
        });


        // search if hash is ON not disabled tab
        if (hash !== "") {

            let $tab_content = $("#" + hash + ".ar-tabcontent");

            if ($tab_content.length !== 0) {

                if ($("#lbl_" + hash + ".ar-tab_link:not([aria-disabled='true'])").length) {

                    // display not disabled
                    $tab_content.removeAttr("aria-hidden");

                    // selection menu
                    $("#lbl_" + hash + ".ar-tab_link").attr({
                        "aria-selected": "true",
                        "tabindex": 0
                    });

                    $tab_content.find('#wpopt-uoptions').attr('action', form_action + '#' + hash);

                }
            }
        }

        // if no selected => select first not disabled
        $tabs.each(function () {
            let $this = $(this),
                $tab_selected = $this.find('.ar-tab_link[aria-selected="true"]'),
                $first_link = $this.find('.ar-tab_link:not([aria-disabled="true"]):first'),
                $first_content = $('#' + $first_link.attr('aria-controls'));

            if ($tab_selected.length === 0) {
                $first_link.attr({
                    "aria-selected": "true",
                    "tabindex": 0
                });
                $first_content.removeAttr("aria-hidden");
            }
        });

        /* Events ---------------------------------------------------------------------------------------------------------- */
        /* click on a tab link disabled */
        $body.on("click", ".ar-tab_link[aria-disabled='true']", function (e) {
            e.preventDefault();
        });

        $body.on("click", ".ar-tab_link:not([aria-disabled='true'])", function (event) {

            let $this = $(this),
                $hash_to_update = $this.attr("aria-controls"),
                $tab_content_linked = $("#" + $this.attr("aria-controls")),
                $parent = $this.closest(".ar-tabs"),

                $all_tab_links = $parent.find(".ar-tab_link"),
                $all_tab_contents = $parent.find(".ar-tabcontent"),

                $form = $tab_content_linked.find('#wpopt-uoptions');

            // aria selected false on all links
            $all_tab_links.attr({
                "tabindex": -1,
                "aria-selected": "false"
            });

            // add aria selected on $this
            $this.attr({
                "aria-selected": "true",
                "tabindex": 0
            });

            // add aria-hidden on all tabs contents
            $all_tab_contents.attr("aria-hidden", "true");

            if (typeof $form !== 'undefined') {
                $form.attr('action', form_action + '#' + $hash_to_update);
            }

            // remove aria-hidden on tab linked
            $tab_content_linked.removeAttr("aria-hidden");

            setTimeout(function () {
                history.pushState(null, null, location.pathname + location.search + '#' + $hash_to_update)
            }, 300);

            event.preventDefault();
        });

        /* Key down in tabs */
        $body.on("keydown", ".ar-tablist", function (event) {

            let $parent = $(this).closest('.ar-tabs');

            // some event should be activated only if the focus is on tabs (not on tabpanel)
            if (!$(document.activeElement).is($parent.find('.ar-tab_link'))) {
                return;
            }

            // catch keyboard event only if focus is on tab
            if (!event.ctrlKey) {

                let $activated = $parent.find('.ar-tab_link[aria-selected="true"]').parent();

                // strike left in the tab
                if (event.keyCode === 37) {

                    let $last_link = $parent.find('.ar-tab:last-child .ar-tab_link'),
                        $prev = $activated;

                    // search valid previous
                    do {
                        // if we are on first => activate last
                        if ($prev.is(".ar-tab:first-child")) {
                            $prev = $last_link.parent();
                        }
                        // else previous
                        else {
                            $prev = $prev.prev();
                        }
                    }
                    while ($prev.children('.ar-tab_link').attr('aria-disabled') === 'true' && $prev !== $activated);

                    $prev.children(".ar-tab_link").click().focus();

                    event.preventDefault();
                }
                // strike  right in the tab
                else if (event.keyCode === 39) {

                    let $first_link = $parent.find('.ar-tab:first-child .ar-tab_link'),
                        $next = $activated;

                    // search valid next
                    do {
                        // if we are on last => activate first
                        if ($next.is(".ar-tab:last-child")) {
                            $next = $first_link.parent();
                        }
                        // else previous
                        else {
                            $next = $next.next();
                        }
                    }
                    while ($next.children('.ar-tab_link').attr('aria-disabled') === 'true' && $next !== $activated);

                    $next.children(".ar-tab_link").click().focus();

                    event.preventDefault();

                }
            }

        });
    };

    let flex_formHandler = function ($selector) {

        $selector.each(function (e) {

            $(this).on('submit', function (e) {

                let $this = $(this);
                let $submitter = $(e.originalEvent.submitter);

                if ($submitter.data('explicit')) return;

                e.preventDefault();

                let callback_fn = function (res) {

                    let $mex_viewer = $("#wpopt-ajax-message");

                    $mex_viewer.empty();
                    $this.removeClass("wpopt-loader");

                    if (res.success) {
                        if (res.data.response.length > 0) {
                            $mex_viewer.append(res.data.response)
                        } else
                            $mex_viewer.append("<p class='success'>" + WPOPT.strings.success + "</p>");
                    } else {
                        if (res.data.response.length > 0)
                            $mex_viewer.append("<p class='error'>" + res.data.response + "</p>")
                        else
                            $mex_viewer.append("<p class='error'>" + WPOPT.strings.error + "</p>");
                    }
                };

                wpopt_ajaxHandler({
                    womod: 'database',
                    wpopt_action: $submitter.data('action'),
                    wpopt_nonce: $this.data('nonce'),
                    wpopt_args: $submitter.data('args'),
                    wpopt_form: $this.serialize(),
                    callback: callback_fn,
                    use_loader: $this,
                })
            });
        });
    }

    $document.ready(function () {

        $(".wpopt-collapse-handler").on("click", function () {
            let $this = $(this);
            $this.children('.wpopt-collapse-icon').toggleClass('wpopt-collapse-icon-close');
            $this.next().toggle(300);
        });

        flex_tabHandler($('.ar-tabs'));

        $(".wpopt-apple-switch").each(function () {

            if (!$(this).prop('checked')) {
                $('input[data-parent="' + this.id + '"]').each(function () {
                    let $this = $(this);

                    $this.closest('tr').addClass('wpopt-disabled-blur');
                    $this.prop("readonly", true);
                });
            }

            $(this).on('click', function () {

                let $this = $(this);

                if ($this.prop("checked")) {

                    $('input[data-parent="' + this.id + '"]').each(function () {
                        $(this).closest('tr').removeClass('wpopt-disabled-blur');
                        $(this).prop("readonly", false);
                    });

                    let parent = $this.data('parent');

                    if (typeof parent !== 'undefined' && parent !== '') {

                        let $parent = $('#' + parent);

                        if (!$parent.prop("checked"))
                            $parent.prop("checked", true);
                    }
                } else {
                    $('input[data-parent="' + this.id + '"]').each(function () {
                        let $this = $(this);

                        $this.closest('tr').addClass('wpopt-disabled-blur');
                        $this.prop("readonly", true);
                    });
                }
            });
        });
    });

    $window.on('beforeunload', function (e) {
        if ($body.hasClass('wpopt-doingAction')) {
            (e || window.event).returnValue = WPOPT.strings.text_close_warning;
            return WPOPT.strings.text_close_warning;
        }
    });

})(jQuery);