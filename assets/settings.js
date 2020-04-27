(function ($) {

    "use strict";

    let $window = $(window),
        $document = $('document');

    let flex_tabHandler = function (container) {

        if (!container instanceof $) {
            console.log("Error initializing flex_tabHandler");
            return;
        }

        let $_tabs = container.find("li[role='tab']");
        let $_panels = $("div[role='tabpanel']");

        let switch_tab = function ($_elem) {

            //deselect all the tabs
            $_tabs.attr("aria-selected", "false");

            // select this tab
            $_elem.attr("aria-selected", "true");

            //hide all the panels
            $_panels.attr("aria-hidden", "true");

            // find out what tab panel this tab controls
            let tabpanid = $_elem.attr("aria-controls");

            // show our panel
            $("#" + tabpanid).attr("aria-hidden", "false");

        }

        $_tabs.on('click', function () {

            switch_tab($(this));

        });

        //This adds keyboard function that pressing an arrow left or arrow right from the tabs toggle the tabs.
        $_tabs.on('keydown', function (ev) {

            let element;

            if ((ev.which === 39)) {

                element = $(this).next('li');

                switch_tab(element);

            } else if (ev.which === 37) {

                element = $(this).prev('li');

                switch_tab(element);

            }
        });
    }

    $(document).ready(function () {
        flex_tabHandler($('#ar-tabs'));
    });

})(jQuery);