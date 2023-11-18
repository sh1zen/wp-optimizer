<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */


namespace WPS\core;

/**
 * Timer that collects timing and memory usage.
 */
class PerformanceMeter
{
    private array $laps = array();
    private int $lap_n = 0;

    private $previuos = 0;

    public function __construct($data = null)
    {
        $this->reset($data);
    }

    public function reset($data = null)
    {
        $this->lap_n = 0;
        $this->laps = array();
        $this->previuos = 0;
        $this->laps['start'] = $this->collect($data);
    }

    private function collect($data = null): array
    {
        if (is_null($data)) {
            $data = $this->get_calling_function(3);
        }

        $this->lap_n++;
        return array(
            'time'   => microtime(true),
            'memory' => memory_get_usage(),
            'data'   => $data
        );
    }

    private function get_calling_function($level = 2)
    {
        $caller = debug_backtrace();
        $caller = $caller[$level];
        $r = $caller['function'] . '()';
        if (isset($caller['class'])) {
            $r .= ' in ' . $caller['class'];
        }
        if (isset($caller['object'])) {
            $r .= ' (' . get_class($caller['object']) . ')';
        }
        return $r;
    }

    public function lap($name = null, $data = null)
    {
        $lap = $this->collect($data);

        if (empty($name)) {
            $name = $this->lap_n;
        }

        $this->previuos = $name;

        $this->laps[$name] = $lap;
    }

    public function get_laps($incremental = false): array
    {
        $laps = array();

        $prev = $this->laps['start'];

        foreach ($this->laps as $lap_id => $lap) {

            $laps[$lap_id]['time_used'] = round($lap['time'] - $prev['time'], 5);
            $laps[$lap_id]['memory_used'] = size_format($lap['memory'] - $prev['memory'], 2);
            $laps[$lap_id]['memory'] = size_format($lap['memory'], 2);
            $laps[$lap_id]['data'] = $lap['data'];

            if (!$incremental) {
                $prev = $lap;
            }
        }

        return $laps;
    }

    public function dump($object = 'time', $start = 'last', $to = 'now')
    {
        switch ($object) {
            case 'time':
                echo $this->get_time($start, $to);
                break;
            case 'memory':
                echo $this->get_memory(true, true);
                break;
            case 'laps':
                echo $this->get_lap($start, true);
                break;
        }

        die();
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
        elseif ($name === 'previous')
            $lap = $this->laps[$this->previuos] ?? end($this->laps);
        else
            $lap = $this->laps[$name] ?? $this->laps['start'];

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

    public function print_time($first = 'start', $last = 'last', $format = false): void
    {
        print_r($this->get_time($first, $last, $format));
    }
}
