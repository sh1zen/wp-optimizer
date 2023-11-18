<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Utility
{
    private static Utility $_Instance;

    public bool $online;

    public string $home_url;

    public int $cu_id;

    public ?\WP_User $cu;

    public Rewriter $rewriter;

    public bool $debug;

    public PerformanceMeter $meter;

    private bool $upgrading = false;

    private function __construct()
    {
        $this->online = $_SERVER["SERVER_ADDR"] !== '127.0.0.1';

        $this->debug = (!$this->online or WPS_DEBUG);

        $this->meter = new PerformanceMeter();

        if (!did_action('init')) {
            require_once ABSPATH . '/wp-includes/user.php';
            require_once ABSPATH . '/wp-includes/pluggable.php';
        }

        $this->cu = \wp_get_current_user() ?: null;

        $this->cu_id = $this->cu->ID ?? 0;

        $this->rewriter = Rewriter::getInstance();

        $this->home_url = $this->rewriter->home_url('', true);
    }

    public function is_upgrading($value = null): bool
    {
        if (!is_null($value)) {
            $this->upgrading = (bool)$value;
        }
        return $this->upgrading;
    }

    public static function getInstance(): Utility
    {
        if (!isset(self::$_Instance)) {
            self::$_Instance = new self();
        }

        return self::$_Instance;
    }
}