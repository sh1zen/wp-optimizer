/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

'use strict';

(function ($) {

    $(function () {

        let filterField = '<input type="search" class="fc-search-field" placeholder="' + fc_plugin.placeholder + '" style="width: 100%" />',
            replacedField, $categoryWrapper, $categoryDiv, $inlineChecklists;

        // Position filter box depending on admin screen
        if (fc_plugin.screenName === 'edit') {

            $categoryWrapper = $('.inline-edit-categories');
            $inlineChecklists = $categoryWrapper.find('.cat-checklist');

            $inlineChecklists.each(function (index, categoryDiv) {

                $categoryDiv = $(categoryDiv);

                replacedField = filterField.replace('%s', $categoryDiv.parent().find('.title').eq(index).text());

                $categoryDiv.before(replacedField);
            });

        } else {

            $categoryWrapper = $('.categorydiv');

            $categoryWrapper.each(function (index, categoryDiv) {

                $categoryDiv = $(categoryDiv);

                replacedField = filterField.replace('%s', $categoryDiv.closest('.postbox').find('.hndle').text());

                $categoryDiv.prepend(replacedField);
            });
        }

        $categoryWrapper.on('keyup search', '.fc-search-field', function (event) {

            let searchTerm = event.target.value, $listItems;

            // Find category list items depending on admin screen
            if (fc_plugin.screenName === 'edit') {

                $listItems = $(this).next('.cat-checklist').find('li');
            } else {
                $listItems = $(this).parent().find('.categorychecklist li');
            }

            if ($.trim(searchTerm)) {

                $listItems.hide().filter(function () {
                    return $(this).text().toLowerCase().indexOf(searchTerm.toLowerCase()) !== -1;
                }).show();

            } else {
                $listItems.show();
            }
        });
    });
}(jQuery));