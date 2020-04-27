<?php
/**
 * Timer that collects timing and memory usage.
 */

class WOTimer
{
    private $start = null;
    private $end = null;
    private $laps = array();

    public function __construct()
    {

    }

    public function start($data = null)
    {
        $this->start = $this->collect($data);
    }

    private function collect(array $data = null)
    {
        return array(
            'time'   => microtime(true),
            'memory' => memory_get_usage(),
            'data'   => $data,
        );
    }

    public function lap(array $data = null, $name = null)
    {
        $lap = $this->collect($data);

        if (!isset($name)) {
            $name = sprintf(__('Lap %d', 'wpopt'), count($this->laps) + 1);
        }

        $this->laps[$name] = $lap;
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

    public function get_time()
    {
        return $this->end['time'] - $this->start['time'];
    }

    public function get_memory($convert = true)
    {
        $size = $this->end['memory'] - $this->start['memory'];

        if($convert)
        {
            $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
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

    public function get_end_time()
    {
        return $this->end['time'];
    }

    public function get_end_memory()
    {
        return $this->end['memory'];
    }

    public function stop(array $data = null)
    {
        $this->end = $this->collect($data);
    }

}
