(function () {
    'use strict';

    function initializeToolsSubmenu() {
        var pluginMenu = document.getElementById('toplevel_page_wp-optimizer');

        if (!pluginMenu) {
            return;
        }

        var pluginSubmenu = pluginMenu.querySelector('.wp-submenu');
        var triggerMarker = pluginSubmenu ? pluginSubmenu.querySelector('.wpopt-admin-tools-heading') : null;
        var triggerItem = triggerMarker ? triggerMarker.closest('li') : null;
        var triggerLink = triggerMarker ? triggerMarker.closest('a') : null;
        var toolMarkers = pluginSubmenu ? pluginSubmenu.querySelectorAll('.wpopt-admin-tool-item') : [];

        if (!pluginSubmenu || !triggerItem || !triggerLink || !toolMarkers.length) {
            return;
        }

        var toolsSubmenu = document.createElement('ul');
        var submenuId = 'wpopt-admin-tools-submenu';

        toolsSubmenu.id = submenuId;
        toolsSubmenu.className = 'wp-submenu wpopt-admin-tools-submenu';
        toolsSubmenu.setAttribute('aria-label', triggerMarker.textContent.trim());

        Array.prototype.forEach.call(toolMarkers, function (marker) {
            var item = marker.closest('li');

            if (item && item.parentElement === pluginSubmenu) {
                toolsSubmenu.appendChild(item);
            }
        });

        if (!toolsSubmenu.children.length) {
            return;
        }

        triggerItem.classList.add('wpopt-admin-tools-menu');
        triggerItem.appendChild(toolsSubmenu);
        triggerLink.setAttribute('aria-controls', submenuId);
        triggerLink.setAttribute('aria-expanded', 'false');
        triggerLink.setAttribute('aria-haspopup', 'true');

        if (toolsSubmenu.querySelector('li.current')) {
            triggerItem.classList.add('current');
        }

        function setOpen(open) {
            triggerItem.classList.toggle('is-open', open);
            triggerLink.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function focusFirstTool() {
            var firstToolLink = toolsSubmenu.querySelector('a');

            if (firstToolLink) {
                firstToolLink.focus();
            }
        }

        triggerLink.addEventListener('click', function (event) {
            event.preventDefault();
            setOpen(!triggerItem.classList.contains('is-open'));
        });

        triggerItem.addEventListener('mouseenter', function () {
            setOpen(true);
        });

        triggerItem.addEventListener('mouseleave', function () {
            setOpen(false);
        });

        triggerItem.addEventListener('focusin', function () {
            setOpen(true);
        });

        triggerItem.addEventListener('focusout', function (event) {
            if (!triggerItem.contains(event.relatedTarget)) {
                setOpen(false);
            }
        });

        triggerLink.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                event.preventDefault();
                setOpen(true);
                focusFirstTool();
            }
            else if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);
            }
        });

        toolsSubmenu.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            event.preventDefault();
            setOpen(false);
            triggerLink.focus();
        });

        document.addEventListener('click', function (event) {
            if (!triggerItem.contains(event.target)) {
                setOpen(false);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeToolsSubmenu);
    }
    else {
        initializeToolsSubmenu();
    }
}());
