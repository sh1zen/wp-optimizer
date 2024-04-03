<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;

use WPS\core\Disk;

class Report
{
    private static Report $_instance;

    private array $report;

    private function __construct()
    {
        $this->report = array();
    }

    public static function export($write_out = false)
    {
        $report_data = self::getInstance()->report;

        if (!$write_out) {
            return $report_data;
        }

        $report = sprintf(__("Report %s : [ %s s : %s ]\n\n", 'wpopt'), wp_date('Y-m-d H:i:s'), round(wps_core()->meter->get_time('start', 'now'), 3), wps_core()->meter->get_memory());

        foreach ($report_data as $scope => $table_report) {

            $report .= ' ------------------------------------------- ' . PHP_EOL;

            $errors_count = isset($table_report['errors']) ? count($table_report['errors']) : 0;
            $success_count = isset($table_report['success']) ? count($table_report['success']) : 0;

            $report .= sprintf(__(' %s [ successes %s / errors %s]', 'wpopt'), $scope, $success_count, $errors_count) . PHP_EOL;

            $report .= ' ------------------------------------------- ' . PHP_EOL;

            if (isset($table_report['success'])) {
                $report .= '  ' . __('Success:', 'wpopt') . PHP_EOL;

                foreach ($table_report['success'] as $success) {
                    $report .= '   - ' . $success['message'];

                    if (!empty($success['args']))
                        $report .= ' : ' . http_build_query($success['args'], '', ', ');

                    $report .= PHP_EOL;
                }
            }

            if (isset($table_report['errors'])) {
                $report .= '  ' . __('Errors:', 'wpopt') . PHP_EOL;
                foreach ($table_report['errors'] as $error) {
                    $report .= '   - ' . $error['message'];

                    if (!empty($error['args']))
                        $report .= ' : ' . http_build_query($error['args'], '', ', ');

                    $report .= PHP_EOL;
                }
            }
        }

        $report .= PHP_EOL . PHP_EOL;

        return Disk::write(WPOPT_STORAGE . 'report-' . time() . '.txt', $report, FILE_APPEND);
    }

    public static function getInstance(): Report
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new static();
        }

        return self::$_instance;
    }

    public function add($place, $message, $status, $args = array())
    {
        $this->report[$place][$status][] = array('message' => $message, 'args' => $args);
    }

    public function get($place = '')
    {
        if (!$place)
            return $this->report;

        return $this->report[$place];
    }
}