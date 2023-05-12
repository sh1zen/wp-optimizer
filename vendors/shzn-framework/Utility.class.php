<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Utility
{
    private static Utility $_Instance;

    public bool $online;

    public string $home_url;

    public int $cu_id;

    public Rewriter $rewriter;

    public function __construct()
    {
        $this->online = $_SERVER["SERVER_ADDR"] !== '127.0.0.1';

        if (!did_action('init')) {
            require_once ABSPATH . '/wp-includes/user.php';
        }

        $this->cu_id = \get_current_user_id();

        $this->rewriter = Rewriter::getInstance();

        $this->home_url = $this->rewriter->home_url('', true);
    }

    public static function getInstance()
    {
        if (!isset(self::$_Instance)) {
            self::$_Instance = new self();
        }

        return self::$_Instance;
    }
}