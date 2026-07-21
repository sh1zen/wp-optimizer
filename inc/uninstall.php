<?php
/**
 * Multisite-aware uninstall helpers.
 */

function wpopt_uninstall_site_ids(): iterable
{
    if (!is_multisite()) {
        yield (int)get_current_blog_id();

        return;
    }

    $batch_size = 100;
    $offset = 0;

    do {
        $sites = get_sites(array(
            'fields'  => 'ids',
            'number'  => $batch_size,
            'offset'  => $offset,
            'orderby' => 'id',
            'order'   => 'ASC',
        ));

        foreach ($sites as $site) {
            $site_id = is_object($site) ? (int)$site->blog_id : (int)$site;

            if ($site_id > 0) {
                yield $site_id;
            }
        }

        $site_count = count($sites);
        $offset += $site_count;
    } while ($site_count === $batch_size);
}

function wpopt_uninstall_prefixed_table_name(string $table_name): string
{
    global $wpdb;

    if (0 === strpos($table_name, $wpdb->prefix)) {
        return $table_name;
    }

    return $wpdb->prefix . $table_name;
}

function wpopt_uninstall_drop_table(string $table_name): void
{
    global $wpdb;

    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table_name) . '`');
}

function wpopt_uninstall_current_site_data(): void
{
    global $wpdb;

    wpopt_cleanup_media_cron_hooks();

    foreach (array('wpopt', 'wpopt.media.todo', 'wpopt_activated_at', 'wpopt_welcome_seen') as $option_name) {
        delete_option($option_name);
    }

    $tables = array(
        wpopt_uninstall_prefixed_table_name('wp_wpopt'),
        $wpdb->prefix . 'wpopt_activity_log',
        $wpdb->prefix . 'wpopt_mails',
        $wpdb->prefix . 'wpopt_performance_monitor',
        $wpdb->prefix . 'wpopt_performance_slow_queries',
        $wpdb->prefix . 'wpopt_cache_entries',
    );

    foreach ($tables as $table_name) {
        wpopt_uninstall_drop_table($table_name);
    }
}

function wpopt_uninstall_network_data(): void
{
    foreach (wpopt_uninstall_site_ids() as $site_id) {
        $switched = is_multisite() && $site_id !== (int)get_current_blog_id();

        if ($switched) {
            switch_to_blog($site_id);
        }

        try {
            wpopt_uninstall_current_site_data();
        }
        finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    // Preserve the framework's existing shared-component ownership check.
    wps_uninstall();
}
