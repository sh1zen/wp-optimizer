<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

/**
 * Timer that collects timing and memory usage.
 */

namespace SHZN\core;

class PerformanceMeter
{
    private $laps = array();
    private $lap_n = 0;

    public function __construct($data = null)
    {
        $this->reset($data);
    }

    public function reset($data = null)
    {
        $this->lap_n = 0;
        $this->laps = array();
        $this->laps['start'] = $this->collect($data);
    }

    private function collect($data = null)
    {
        if (is_null($data)) {
            $data = shzn_debug_backtrace(3);
        }

        $this->lap_n++;
        return array(
            'time'   => microtime(true),
            'memory' => memory_get_usage(),
            'data'   => $data
        );
    }

    public function lap($name = null, $data = null)
    {
        $lap = $this->collect($data);

        if (empty($name)) {
            $name = $this->lap_n;
        }

        $this->laps[$name] = $lap;
    }

    public function get_laps()
    {
        $laps = array();
        $_lap = [];

        $prev = $this->laps['start'];

        foreach ($this->laps as $lap_id => $lap) {

            $_lap['time_used'] = round($lap['time'] - $prev['time'], 5);
            $_lap['memory_used'] = size_format($lap['memory'] - $prev['memory'], 2);
            $_lap['memory'] = size_format($lap['memory'], 2);
            $_lap['data'] = $lap['data'];

            $laps[$lap_id] = $_lap;
            $prev = $lap;
        }

        return $laps;
    }

    public function get_time($first = 'start', $last = 'last', $format = false)
    {
        $start_lap = $first === 'wp_start' ? WP_START_TIMESTAMP : $this->get_lap($first, 'time');
        $end_lap = $last === 'now' ? microtime(true) : $this->get_lap($last, 'time');

        $time = $end_lap - $start_lap;

        if ($format) {
            $time = number_format($time, absint($format));
        }

        return $time;
    }

    public function get_lap($name = 'start', $property = false)
    {
        if ($name === 'last')
            $lap = end($this->laps);
        else
            $lap = isset($this->laps[$name]) ? $this->laps[$name] : $this->laps['start'];

        if ($property)
            return $lap[$property];

        return $lap;
    }

    public function get_memory($convert = true, $peak = false)
    {
        $size = $this->get_lap('last', 'memory') - $this->get_lap('start', 'memory');

        if ($peak) {
            $size = memory_get_peak_usage();
        }

        if ($convert) {
            return size_format($size, 2);
        }

        return $size;
    }
}
