<?php
/**
 * Replace the legacy shared direct-cache runtime with tenant-specific runtime
 * files and rebuild the current site's configuration from its saved settings.
 *
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

require_once WPOPT_SUPPORTERS . 'cache/staticcache_direct.class.php';

use WPOptimizer\modules\supporters\StaticCacheDirectAccess;

StaticCacheDirectAccess::remove_legacy_runtime();

$static_settings = (array)wps('wpopt')->settings->get('cache.static_pages', array());
$direct_access_enabled = !empty($static_settings['active']) && !empty($static_settings['direct_access_enabled']);

if ($direct_access_enabled) {
    StaticCacheDirectAccess::activate($static_settings);
}
else {
    StaticCacheDirectAccess::deactivate();
}
