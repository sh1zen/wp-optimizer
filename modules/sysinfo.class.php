<?php

/**
 * Count the WordPress items
 *
 * @param string $name name.
 * @return int
 * @since 1.1.0
 *
 * @access public
 */
class wpoptSysinfo
{

    public function __construct()
    {
    }

    public function render()
    {
        $output = '<section class="wpopt-wrap">';

        $information = $this->get_info();

        $output .= "  <section class='wpopt'><h1>System Info </h1></section>";

        $output .= "<block>";

        foreach ($information as $name => $value) {

            if ($value == '---') {
                $output .= "</block><block>";
                continue;
            }

            $output .= "<line><b>" . $name . ":</b><value>" . $value . "</value></line>";
        }

        $output .= "</block>";

        $output .= '</section>';

        echo $output;
    }

    public function get_info()
    {
        global $wpdb;

        $settings = array(
            'SITE_URL'                 => site_url(),
            'HOME_URL'                 => home_url(),
            '---',
            'WordPress Version'        => get_bloginfo('version'),
            'Permalink Structure'      => get_option('permalink_structure'),
            '---',
            'PHP Version'              => PHP_VERSION,
            'MySQL Version'            => $wpdb->db_version(),
            'Web Server Info'          => $_SERVER['SERVER_SOFTWARE'],
            'User Agent'               => $_SERVER['HTTP_USER_AGENT'],
            'Multi-site'               => is_multisite() ? 'Yes' : 'No',
            '---',
            'PHP Memory Limit'         => ini_get('memory_limit'),
            'PHP Post Max Size'        => ini_get('post_max_size'),
            'PHP Upload Max File size' => ini_get('upload_max_filesize'),
            'PHP Time Limit'           => ini_get('max_execution_time') . ' sec',
            '---',
            'WP_DEBUG'                 => defined('WP_DEBUG') ? (WP_DEBUG ? __('Enabled', 'wpopt') : __('Disabled', 'wpopt')) : __('Not set', 'wpopt'),
            'DISPLAY ERRORS'           => (ini_get('display_errors')) ? 'On (' . ini_get('display_errors') . ')' : 'N/A',
            '---',
            'WP Table Prefix'          => 'Length: ' . strlen($wpdb->prefix) . ' Status:' . (strlen($wpdb->prefix) > 16 ? ' ERROR: Too Long' : ' Acceptable'),
            'WP DB Charset/Collate'    => $wpdb->get_charset_collate(),
            '---',
            'Session'                  => isset($_SESSION) ? 'Enabled' : 'Disabled',
            'Session Name'             => esc_html(ini_get('session.name')),
            'Cookie Path'              => esc_html(ini_get('session.cookie_path')),
            'Save Path'                => esc_html(ini_get('session.save_path')),
            'Use Cookies'              => ini_get('session.use_cookies') ? 'On' : 'Off',
            'Use Only Cookies'         => ini_get('session.use_only_cookies') ? 'On' : 'Off',
            '---',
            'WordPress Memory Limit'   => (size_format((int)WP_MEMORY_LIMIT * 1048576)),
            'WordPress Upload Size'    => (size_format(wp_max_upload_size())),
            'Filesystem Method'        => get_filesystem_method(),
            'SSL SUPPORT'              => extension_loaded('openssl') ? 'SSL extension loaded' : 'SSL extension NOT loaded',
            'MB String'                => extension_loaded('mbstring') ? 'MB String extensions loaded' : 'MB String extensions NOT loaded',
            '---',
            'ACTIVE PLUGINS'           => "<br />",
            'INACTIVE PLUGINS'         => '<br />',
            '---',
            'CURRENT THEME'            => '',
        );

        $plugins = $this->get_plugins();

        $settings['ACTIVE PLUGINS'] .= $plugins['ACTIVE PLUGINS'];
        $settings['INACTIVE PLUGINS'] .= $plugins['INACTIVE PLUGINS'];
        $settings['CURRENT THEME'] .= $this->get_current_theme();


        return apply_filters('wtlwp_system_info', $settings);

    }

    public function get_plugins()
    {
        $plugins = array(
            'INACTIVE PLUGINS' => '',
            'ACTIVE PLUGINS'   => ''
        );

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', array());

        foreach ($all_plugins as $plugin_path => $plugin) {
            // If the plugin isn't active, don't show it.
            if (!in_array($plugin_path, $active_plugins)) {
                $plugins['INACTIVE PLUGINS'] .= $plugin['Name'] . ': ' . $plugin['Version'] . "<br />";
            }
            else {
                $plugins['ACTIVE PLUGINS'] .= $plugin['Name'] . ': ' . $plugin['Version'] . "<br />";
            }
        }

        return $plugins;
    }

    public function get_current_theme()
    {
        $current_theme = '';
        if (function_exists('wp_get_theme')) {
            $theme_data = wp_get_theme();
            $current_theme = $theme_data->get('Name') . ': ' . $theme_data->get('Version') . "<br />" . $theme_data->get('Author') . ' (' . $theme_data->get('AuthorURI') . ')';
        }
        else if (function_exists('get_theme_data')) {
            $theme_data = wp_get_theme(get_stylesheet_directory() . '/style.css');
            $current_theme = $theme_data['Name'] . ': ' . $theme_data['Version'] . "<br />" . $theme_data['Author'] . ' (' . $theme_data['AuthorURI'] . ')';
        }

        return $current_theme;

    }


    public function total_count($name)
    {
        global $wpdb;

        $count = 0;

        switch ($name) {
            case 'posts':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts");
                break;
            case 'postmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta");
                break;
            case 'comments':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->comments");
                break;
            case 'commentmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->commentmeta");
                break;
            case 'users':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");
                break;
            case 'usermeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->usermeta");
                break;
            case 'term_relationships':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_relationships");
                break;
            case 'term_taxonomy':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy");
                break;
            case 'terms':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->terms");
                break;
            case 'termmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->termmeta");
                break;
            case 'options':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options");
                break;
            case 'tables':
                $count = count($wpdb->get_col('SHOW TABLES'));
                break;

            /**
             * Specific data type
             */

            case 'revisions':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) FROM $wpdb->posts WHERE post_type = %s", 'revision'));
                break;
            case 'auto_drafts':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) FROM $wpdb->posts WHERE post_status = %s", 'auto-draft'));
                break;
            case 'deleted_posts':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(ID) FROM $wpdb->posts WHERE post_status = %s", 'trash'));
                break;
            case 'unapproved_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_approved = %s", '0'));
                break;
            case 'spam_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_approved = %s", 'spam'));
                break;
            case 'deleted_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE (comment_approved = %s OR comment_approved = %s)", 'trash', 'post-trashed'));
                break;
            case 'transient_options':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(option_id) FROM $wpdb->options WHERE option_name LIKE(%s)", '%_transient_%'));
                break;
            case 'orphan_postmeta':
                $count = $wpdb->get_var("SELECT COUNT(meta_id) FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts)");
                break;
            case 'orphan_commentmeta':
                $count = $wpdb->get_var("SELECT COUNT(meta_id) FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments)");
                break;
            case 'orphan_usermeta':
                $count = $wpdb->get_var("SELECT COUNT(umeta_id) FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users)");
                break;
            case 'orphan_termmeta':
                $count = $wpdb->get_var("SELECT COUNT(meta_id) FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms)");
                break;
            case 'orphan_term_relationships':
                $orphan_term_relationships_sql = implode("','", array_map('esc_sql', $this->get_excluded_taxonomies()));
                $count = $wpdb->get_var("SELECT COUNT(object_id) FROM $wpdb->term_relationships AS tr INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy NOT IN ('$orphan_term_relationships_sql') AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts)"); // phpcs:ignore
                break;
            case 'unused_terms':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(t.term_id) FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.count = %d AND t.term_id NOT IN (" . implode(',', $this->get_excluded_termids()) . ')', 0)); // phpcs:ignore
                break;
            case 'duplicated_postmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(meta_id) AS count FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_commentmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(meta_id) AS count FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_usermeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(umeta_id) AS count FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_termmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(meta_id) AS count FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'oembed_postmeta':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(meta_id) FROM $wpdb->postmeta WHERE meta_key LIKE(%s)", '%_oembed_%'));
                break;
        }

        return $count;
    }

}