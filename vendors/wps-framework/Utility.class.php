<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Utility
{
    private static Utility $_Instance;
    private static int $_UID = 0;

    public bool $online;

    public string $home_url;

    public ?\WP_User $cu = null;

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

        $this->rewriter = Rewriter::getInstance();

        $this->home_url = $this->rewriter->home_url('', true);
    }

    public static function getInstance(): Utility
    {
        if (!isset(self::$_Instance)) {
            self::$_Instance = new self();
        }

        return self::$_Instance;
    }

    public function get_cuID(): int
    {
        return $this->get_current_user()->ID ?? 0;
    }

    public function get_current_user(): ?\WP_User
    {
        if (is_null($this->cu)) {
            $this->cu = \wp_get_current_user() ?: null;
        }

        return $this->cu;
    }

    public function is_upgrading($value = null): bool
    {
        if (!is_null($value)) {
            $this->upgrading = (bool)$value;
        }
        return $this->upgrading;
    }

    public function uid(): int
    {
        return self::$_UID++;
    }
}