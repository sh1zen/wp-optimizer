<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

global $wpdb;

$wpdb->query("ALTER TABLE " . wps('wpopt')->options->table_name() . " ADD container VARCHAR(255) NULL DEFAULT NULL AFTER value;");

$wpdb->query("ALTER TABLE " . wps('wpopt')->options->table_name() . " ADD UNIQUE speeder_container (container, item, obj_id) USING BTREE;");