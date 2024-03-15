<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */


if (!function_exists('is_closure')) {
    function is_closure($t): bool
    {
        return $t instanceof \Closure;
    }
}

if (\PHP_VERSION_ID < 70200) {
    require __DIR__ . '/72.php';
}

if (\PHP_VERSION_ID < 70300) {
    require __DIR__ . '/73.php';
}

if (\PHP_VERSION_ID < 70400) {
    require __DIR__ . '/74.php';
}

if (\PHP_VERSION_ID < 80000) {
    require __DIR__ . '/80.php';
}

if (\PHP_VERSION_ID < 80100) {
    require __DIR__ . '/81.php';
}
