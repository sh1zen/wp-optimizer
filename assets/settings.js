(function ($) {

    "use strict";

    let $window = $(window),
        $document = $('document'),
        $body = $("body");

    // Store current URL hash.
    let hash = window.location.hash.replace("#", "");

    let flex_tabHandler = function ($tabs) {

        if (!$tabs instanceof $) {
            console.log("Error initializing flex_tabHandler");
            return;
        }

        if ($tabs.length === 0)
            return;

        let $tab_list = $tabs.find(".ar-tablist");

        if ($tab_list.length === 0)
            return;

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
            let $this = $(this),
                $this_id = $this.attr("id");
            // label by link
            $this.attr("aria-labelledby", "lbl_" + $this_id);
        });


        // search if hash is ON not disabled tab
        if (hash !== "" && $("#" + hash + ".ar-tabcontent").length !== 0) {

            if ($("#lbl_" + hash + ".ar-tab_link:not([aria-disabled='true'])").length) {

                // display not disabled
                $("#" + hash + ".ar-tabcontent").removeAttr("aria-hidden");
                // selection menu
                $("#lbl_" + hash + ".ar-tab_link").attr({
                    "aria-selected": "true",
                    "tabindex": 0
                });
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
                $all_tab_contents = $parent.find(".ar-tabcontent");

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

            // remove aria-hidden on tab linked
            $tab_content_linked.removeAttr("aria-hidden");

            setTimeout(function () {
                history.pushState(null, null, location.pathname + location.search + '#' + $hash_to_update)
            }, 600);

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
    }

    $(document).ready(function () {
        flex_tabHandler($('.ar-tabs'));
    });

})(jQuery);