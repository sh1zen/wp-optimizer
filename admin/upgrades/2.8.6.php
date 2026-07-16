<?php
/**
 * Refresh direct-cache runtime files so compatibility exclusions are applied
 * before WordPress is bootstrapped on existing installations.
 *
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

require_once WPOPT_SUPPORTERS . 'cache/staticcache_direct.class.php';

\WPOptimizer\modules\supporters\StaticCacheDirectAccess::refresh_installed_runtime();
