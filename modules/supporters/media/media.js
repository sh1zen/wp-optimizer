/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

'use strict';

jQuery(document).ready(function ($) {

    let $dirExplorer = $(".wpopt-dir-explorer");
    let $input = $dirExplorer.find('input');

    $dirExplorer.append('<ul class="wps-autocomplete" id="wpopt-dir-list" style="display: none;"></ul>');

    let suggestions = $('#wpopt-dir-list');

    suggestions.on("click", "li", function (e) {
        e.preventDefault();
        $input.val($(this).data('path'));
        suggestions.slideToggle();
    });

    $input.on("input", function (e) {

        if (wps.semaphore.is_locked('wpopt-dir-explorer')) {
            return;
        }

        wps.semaphore.lock('wpopt-dir-explorer');

        wps.ajaxHandler({
            mod: 'media',
            mod_action: 'autoCompleteDirs',
            mod_args: $(this).val(),
            callback: function (res, status) {

                wps.semaphore.release('wpopt-dir-explorer');

                let response = res.response;

                if (suggestions.is(":hidden")) {
                    suggestions.slideToggle();
                }

                suggestions.empty();

                if (status) {
                    response['predictions'].forEach(function (k, index) {
                        suggestions.append('<li data-path="' + k + '">' + wpopt_abspath + k + '</li>');
                    });
                }
            }
        });
    });


});