<?php
/**
 * Regenerate the managed security-rule block so existing installations no
 * longer retain the invalid `Options All -Indexes` directive.
 *
 * @author    sh1zen
 * @copyright Copyright (C) 2026.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

require_once WPOPT_SUPPORTERS . 'optisec/localConf.php';

use WPS\core\Settings;
use WPOptimizer\modules\supporters\WP_Htaccess;

$security_settings = (array)wps('wpopt')->settings->get('wp_security', array());
$writer = new WP_Htaccess($security_settings);
$writer->toggle_rule('srv_security', Settings::get_option($security_settings, 'srv_security.active'));

if ($writer->edited()) {
    $writer->write();
}
