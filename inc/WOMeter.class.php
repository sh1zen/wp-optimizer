<?php
/**
 * Timer that collects timing and memory usage.
 */

class WOMeter
{
    private $start;
    private $laps = array();

    public function __construct($data = null)
    {
        $this->start = $this->collect($data);
    }

    private function collect($data = null)
    {
        return array(
            'time'   => microtime(true),
            'memory' => memory_get_usage(),
            'data'   => $data,
        );
    }

    public function reset($data = null)
    {
        $this->start = $this->collect($data);
        $this->laps = array();
    }

    public function lap($data = null, $name = null)
    {
        $lap = $this->collect($data);

        if (isset($name)) {
            $this->laps[$name] = $lap;
        }

        $this->laps[] = $lap;
    }

    public function get_laps()
    {
        $laps = array();

        $prev = $this->start;

        foreach ($this->laps as $lap_id => $lap) {

            $lap['time_used'] = $lap['time'] - $prev['time'];
            $lap['memory_used'] = $lap['memory'] - $prev['memory'];

            $laps[$lap_id] = $lap;
            $prev = $lap;
        }

        return $laps;
    }

    public function get_time($now = false)
    {
        if ($now)
            return microtime(true) - $this->start['time'];
        else
            return end($this->laps)['time'] - $this->start['time'];
    }

    public function get_memory($convert = true, $peak = false)
    {
        $size = end($this->laps)['memory'] - $this->start['memory'];

        if ($peak)
            $size = memory_get_peak_usage();

        if ($convert) {
            $unit = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
            return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
        }

        return $size;
    }


    public function get_start_time()
    {
        return $this->start['time'];
    }

    public function get_start_memory()
    {
        return $this->start['memory'];
    }
}
