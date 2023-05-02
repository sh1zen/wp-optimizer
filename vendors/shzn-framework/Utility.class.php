<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Utility
{
    public bool $online;

    public string $home_url;

    public int $cu_id;

    public function __construct()
    {
        $this->home_url = trailingslashit(\home_url());;

        $this->cu_id = \get_current_user_id();

        $this->online = $_SERVER["SERVER_ADDR"] !== '127.0.0.1';
    }
}