<?php
/**
 * Public cache integration API for external code.
 *
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

function wpopt_get_cache_module_instance()
{
    if (!function_exists('wps')) {
        return null;
    }

    try {
        $wpopt = wps('wpopt');
    }
    catch (\Throwable $exception) {
        return null;
    }

    if (!$wpopt || empty($wpopt->moduleHandler)) {
        return null;
    }

    if (!$wpopt->moduleHandler->module_is_active('cache')) {
        return null;
    }

    return $wpopt->moduleHandler->get_module_instance('cache');
}

function wpopt_cache_auto_purge_is_suspended(string $layer = ''): bool
{
    $suspensions = isset($GLOBALS['wpopt_cache_auto_purge_suspensions'])
        ? absint($GLOBALS['wpopt_cache_auto_purge_suspensions'])
        : 0;

    if ($suspensions <= 0) {
        return false;
    }

    $suspended_layers = isset($GLOBALS['wpopt_cache_auto_purge_suspended_layers']) && is_array($GLOBALS['wpopt_cache_auto_purge_suspended_layers'])
        ? array_map('sanitize_key', $GLOBALS['wpopt_cache_auto_purge_suspended_layers'])
        : array();

    if ($layer !== '' && !in_array(sanitize_key($layer), $suspended_layers, true)) {
        return false;
    }

    if ($layer !== '') {
        if (!isset($GLOBALS['wpopt_cache_auto_purge_dirty_layers']) || !is_array($GLOBALS['wpopt_cache_auto_purge_dirty_layers'])) {
            $GLOBALS['wpopt_cache_auto_purge_dirty_layers'] = array();
        }

        $GLOBALS['wpopt_cache_auto_purge_dirty_layers'][] = sanitize_key($layer);
        $GLOBALS['wpopt_cache_auto_purge_dirty_layers'] = array_values(array_unique(array_filter($GLOBALS['wpopt_cache_auto_purge_dirty_layers'])));
    }

    return true;
}

function wpopt_cache_runtime_is_suspended(string $layer = ''): bool
{
    $suspensions = isset($GLOBALS['wpopt_cache_runtime_suspensions'])
        ? absint($GLOBALS['wpopt_cache_runtime_suspensions'])
        : 0;

    if ($suspensions <= 0) {
        return false;
    }

    $suspended_layers = isset($GLOBALS['wpopt_cache_runtime_suspended_layers']) && is_array($GLOBALS['wpopt_cache_runtime_suspended_layers'])
        ? array_map('sanitize_key', $GLOBALS['wpopt_cache_runtime_suspended_layers'])
        : array();

    return $layer === '' || in_array(sanitize_key($layer), $suspended_layers, true);
}

function wpopt_is_cache_active(): bool
{
    $cache_module = wpopt_get_cache_module_instance();

    return is_object($cache_module)
        && method_exists($cache_module, 'cache_is_active')
        && $cache_module->cache_is_active();
}

function wpopt_suspend_cache_auto_purge(string $source = 'external'): bool
{
    $cache_module = wpopt_get_cache_module_instance();

    return is_object($cache_module)
        && method_exists($cache_module, 'suspend_cache_auto_purge')
        && $cache_module->suspend_cache_auto_purge($source);
}

function wpopt_resume_cache_auto_purge(bool $flush_if_dirty = true, string $source = 'external'): bool
{
    $cache_module = wpopt_get_cache_module_instance();

    return is_object($cache_module)
        && method_exists($cache_module, 'resume_cache_auto_purge')
        && $cache_module->resume_cache_auto_purge($flush_if_dirty, $source);
}

function wpopt_flush_cache(string $source = 'external'): bool
{
    $cache_module = wpopt_get_cache_module_instance();

    return is_object($cache_module)
        && method_exists($cache_module, 'flush_cache_layers_active')
        && $cache_module->flush_cache_layers_active();
}
