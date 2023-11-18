<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPOptimizer\core\Report;
use WPS\core\UtilEnv;

class DBSupport
{
    private static DBSupport $_Instance;

    private function __construct()
    {
    }

    /**
     * Count the number of total items
     *
     * @param string $name
     * @return int
     */
    public static function total_count($name)
    {
        global $wpdb;

        $count = 0;

        switch ($name) {
            case 'posts':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
                break;
            case 'postmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
                break;
            case 'comments':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}");
                break;
            case 'commentmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta}");
                break;
            case 'users':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
                break;
            case 'usermeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta}");
                break;
            case 'term_relationships':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships}");
                break;
            case 'term_taxonomy':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy}");
                break;
            case 'terms':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}");
                break;
            case 'termmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta}");
                break;
            case 'options':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
                break;
        }

        return $count;
    }

    /**
     * Count the number of items for each sweep
     *
     * @param string $name
     * @param array $args
     * @return int
     */
    public static function count($name, $args = array())
    {
        global $wpdb;

        $count = 0;

        switch ($name) {
            case 'revisions':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s", 'revision'));
                break;
            case 'auto_drafts':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = %s", 'auto-draft'));
                break;
            case 'deleted_posts':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = %s", 'trash'));
                break;
            case 'unapproved_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = %s", '0'));
                break;
            case 'spam_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = %s", 'spam'));
                break;
            case 'deleted_comments':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->comments WHERE (comment_approved = %s OR comment_approved = %s)", 'trash', 'post-trashed'));
                break;
            case 'transient_options':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE (%s)", '%_transient_%'));
                break;
            case 'orphan_postmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts)");
                break;
            case 'orphan_commentmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments)");
                break;
            case 'orphan_usermeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users)");
                break;
            case 'orphan_termmeta':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms)");
                break;
            case 'orphan_term_relationships':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_relationships AS tr INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id NOT IN (SELECT ID FROM $wpdb->posts)"); // WPCS: unprepared SQL ok.
                break;
            case 'unused_terms':
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy AS tt WHERE tt.count = 0 AND tt.parent <> 0 AND tt.parent NOT IN (SELECT term_id FROM $wpdb->term_taxonomy WHERE count = 0 )");
                break;
            case 'duplicated_postmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(*) AS count FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_commentmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(*) AS count FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_usermeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(*) AS count FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'duplicated_termmeta':
                $query = $wpdb->get_col($wpdb->prepare("SELECT COUNT(*) AS count FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING count > %d", 1));
                if (is_array($query)) {
                    $count = array_sum(array_map('intval', $query));
                }
                break;
            case 'oembed_postmeta':
                $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key LIKE(%s)", '%_oembed_%'));
                break;
        }

        return $count;
    }

    /**
     * Return details about a sweep
     *
     * @param string $name
     * @param array $excluded_taxonomies
     * @return array
     */
    public static function details($name, $excluded_taxonomies = array())
    {
        global $wpdb;

        $details = array();

        $ajax_limit = 100;

        switch ($name) {
            case 'revisions':
                $details = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE post_type = %s LIMIT %d", 'revision', $ajax_limit));
                break;
            case 'auto_drafts':
                $details = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE post_status = %s LIMIT %d", 'auto-draft', $ajax_limit));
                break;
            case 'deleted_posts':
                $details = $wpdb->get_col($wpdb->prepare("SELECT post_title FROM $wpdb->posts WHERE post_status = %s LIMIT %d", 'trash', $ajax_limit));
                break;
            case 'unapproved_comments':
                $details = $wpdb->get_col($wpdb->prepare("SELECT comment_author FROM $wpdb->comments WHERE comment_approved = %s LIMIT %d", '0', $ajax_limit));
                break;
            case 'spam_comments':
                $details = $wpdb->get_col($wpdb->prepare("SELECT comment_author FROM $wpdb->comments WHERE comment_approved = %s LIMIT %d", 'spam', $ajax_limit));
                break;
            case 'deleted_comments':
                $details = $wpdb->get_col($wpdb->prepare("SELECT comment_author FROM $wpdb->comments WHERE (comment_approved = %s OR comment_approved = %s) LIMIT %d", 'trash', 'post-trashed', $ajax_limit));
                break;
            case 'transient_options':
                $details = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name LIKE(%s) LIMIT %d", '%_transient_%', $ajax_limit));
                break;
            case 'orphan_postmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts) LIMIT %d", $ajax_limit));
                break;
            case 'orphan_commentmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments) LIMIT %d", $ajax_limit));
                break;
            case 'orphan_usermeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users) LIMIT %d", $ajax_limit));
                break;
            case 'orphan_termmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms) LIMIT %d", $ajax_limit));
                break;
            case 'orphan_term_relationships':
                $details = $wpdb->get_col($wpdb->prepare("SELECT tt.taxonomy FROM {$wpdb->term_relationships} AS tr, {$wpdb->term_taxonomy} AS tt WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND tr.object_id NOT IN (SELECT ID FROM $wpdb->posts) LIMIT %d", $ajax_limit));
                break;
            case 'unused_terms':
                $ids = $wpdb->get_col($wpdb->prepare("SELECT tt.term_id FROM $wpdb->term_taxonomy AS tt WHERE tt.count = 0 AND tt.parent <> 0 AND tt.parent NOT IN (SELECT term_id FROM $wpdb->term_taxonomy WHERE count = 0 ) LIMIT %d", $ajax_limit));
                foreach ($ids as $term_id) {
                    $details[] = "<a target='_blank' href='" . get_edit_term_link($term_id) . "'>{$term_id}</a>";
                }
                break;
            case 'duplicated_postmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'duplicated_commentmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'duplicated_usermeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'duplicated_termmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT COUNT(*) AS count, meta_key FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING count > %d LIMIT %d", 1, $ajax_limit));
                if ($query) {
                    foreach ($query as $meta) {
                        $details[] = $meta->meta_key;
                    }
                }
                break;
            case 'oembed_postmeta':
                $details = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM $wpdb->postmeta WHERE meta_key LIKE(%s) LIMIT %d", '%_oembed_%', $ajax_limit));
                break;
        }

        return $details;
    }

    public static function execute_sql($sql_query)
    {
        global $wpdb;

        if (preg_match("/LOAD_FILE/i", $sql_query))
            return false;

        if (preg_match("/^\\s*(select|drop|show|grant) /i", $sql_query))
            return false;

        if (preg_match("/^\\s*(insert|update|replace|delete|create|alter) /i", $sql_query)) {
            return $wpdb->query($sql_query);
        }

        return false;
    }

    /**
     * Does the sweeping/cleaning up
     *
     * @param string $name Sweep name.
     * @param array $args
     * @return string Processed message
     */
    public static function sweep(string $name, array $args = array())
    {
        global $wpdb;

        set_time_limit(60);

        $query = array();

        switch ($name) {

            case 'revisions':
            case 'auto_drafts':
            case 'deleted_posts':

                if ($name == "revisions")
                    $query = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type = 'revision'"));
                else
                    $query = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_status = '{$name}'"));

                if ($query) {
                    foreach ($query as $id) {
                        wp_delete_post((int)$id, true);
                    }
                }
                break;

            case 'unapproved_comments':
            case 'spam_comments':
            case 'deleted_comments':

                if ($name == 'unapproved_comments')
                    $query = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = '0'"));
                elseif ($name == 'deleted_comments')
                    $query = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE (comment_approved = 'trash' OR comment_approved = 'post-trashed')"));
                else
                    $query = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM $wpdb->comments WHERE comment_approved = 'spam'"));


                if ($query) {
                    foreach ($query as $id) {
                        wp_delete_comment((int)$id, true);
                    }
                }
                break;

            case 'transient_options':
                $query = $wpdb->get_col($wpdb->prepare("SELECT option_id FROM $wpdb->options WHERE option_name LIKE(%s)", '%_transient_%'));
                if (!empty($query)) {
                    $wpdb->query("DELETE FROM $wpdb->options WHERE option_id IN (" . implode(', ', $query) . ") ");
                }
                break;

            case 'orphan_postmeta':
            case 'oembed_postmeta':

                if ($name === 'orphan_postmeta')
                    $query = $wpdb->get_col("SELECT meta_id FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts)");
                else
                    $query = $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM $wpdb->postmeta WHERE meta_key LIKE (%s)", '%_oembed_%'));

                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_id IN (" . implode(', ', $query) . ")");

                }
                break;

            case 'orphan_commentmeta':
                $query = $wpdb->get_col("SELECT meta_id FROM $wpdb->commentmeta WHERE comment_id NOT IN (SELECT comment_ID FROM $wpdb->comments)");

                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->commentmeta WHERE meta_id IN (" . implode(', ', $query) . ") ");
                }
                break;

            case 'orphan_usermeta':
                $query = $wpdb->get_col("SELECT umeta_id FROM $wpdb->usermeta WHERE user_id NOT IN (SELECT ID FROM $wpdb->users)");
                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->usermeta WHERE umeta_id IN (" . implode(', ', $query) . ")");
                }
                break;

            case 'orphan_termmeta':
                $query = $wpdb->get_col("SELECT meta_id FROM $wpdb->termmeta WHERE term_id NOT IN (SELECT term_id FROM $wpdb->terms)");
                if (!empty($query)) {

                    $wpdb->query("DELETE FROM $wpdb->termmeta WHERE meta_id IN (" . implode(', ', $query) . ") ");
                }
                break;

            case 'orphan_term_relationships':
                $query = $wpdb->get_results("SELECT tr.object_id, tr.term_taxonomy_id, tt.term_id, tt.taxonomy FROM $wpdb->term_relationships AS tr INNER JOIN $wpdb->term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id NOT IN (SELECT ID FROM $wpdb->posts)"); // WPCS: unprepared SQL ok.
                if ($query) {
                    foreach ($query as $tax) {
                        $wp_remove_object_terms = wp_remove_object_terms((int)$tax->object_id, (int)$tax->term_id, $tax->taxonomy);
                        if (true !== $wp_remove_object_terms) {
                            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id = %d", $tax->object_id, $tax->term_taxonomy_id));
                        }
                    }
                }
                break;

            case 'unused_terms':
                //SELECT tt.term_taxonomy_id, t.term_id, tt.taxonomy FROM $wpdb->terms AS t INNER JOIN $wpdb->term_taxonomy AS tt ON t.term_id = tt.term_id WHERE tt.count = %d AND t.term_id
                $query = $wpdb->get_results("SELECT tt.term_id, tt.taxonomy, tt.term_taxonomy_id FROM $wpdb->term_taxonomy  AS tt WHERE tt.count = 0 AND tt.parent <> 0 AND tt.parent NOT IN (SELECT term_id FROM $wpdb->term_taxonomy WHERE count = 0 )"); // WPCS: unprepared SQL ok.
                if ($query) {
                    $check_wp_terms = false;
                    foreach ($query as $tax) {
                        if (taxonomy_exists($tax->taxonomy)) {
                            wp_delete_term((int)$tax->term_id, $tax->taxonomy);
                        }
                        else {
                            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d", (int)$tax->term_taxonomy_id));
                            $check_wp_terms = true;
                        }
                    }
                    // We need this for invalid taxonomies.
                    if ($check_wp_terms) {
                        $wpdb->get_results("DELETE FROM $wpdb->terms WHERE term_id NOT IN (SELECT term_id FROM $wpdb->term_taxonomy)");
                    }
                }
                break;

            case 'duplicated_postmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(meta_id) AS ids, post_id, COUNT(*) AS count FROM $wpdb->postmeta GROUP BY post_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->postmeta WHERE meta_id IN (" . implode(',', $ids) . ') AND post_id = %d', (int)$meta->post_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;

            case 'duplicated_commentmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(meta_id) AS ids, comment_id, COUNT(*) AS count FROM $wpdb->commentmeta GROUP BY comment_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->commentmeta WHERE meta_id IN (" . implode(',', $ids) . ') AND comment_id = %d', (int)$meta->comment_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;

            case 'duplicated_usermeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(umeta_id) AS ids, user_id, COUNT(*) AS count FROM $wpdb->usermeta GROUP BY user_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->usermeta WHERE umeta_id IN (" . implode(',', $ids) . ') AND user_id = %d', (int)$meta->user_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;

            case 'duplicated_termmeta':
                $query = $wpdb->get_results($wpdb->prepare("SELECT GROUP_CONCAT(meta_id) AS ids, term_id, COUNT(*) AS count FROM $wpdb->termmeta GROUP BY term_id, meta_key, meta_value HAVING count > %d", 1));
                if ($query) {
                    foreach ($query as $meta) {
                        $ids = array_map('intval', explode(',', $meta->ids));
                        array_pop($ids);
                        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->termmeta WHERE meta_id IN (" . implode(',', $ids) . ') AND term_id = %d', (int)$meta->term_id)); // WPCS: unprepared SQL ok.
                    }
                }
                break;
        }

        return empty($query) ? 0 : number_format_i18n(count($query));
    }

    public static function cron_job()
    {
        global $wpdb;

        $results = 0;

        /*
         * Clear wp transients
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->options}options WHERE option_name LIKE '%_transient_%';");

        /*
         * Delete posts and pages auto-draft
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'revision';");

        /*
        * Delete orphaned post attachments
        */
        $results += $wpdb->query("DELETE p1 FROM wp_posts p1 LEFT JOIN wp_posts p2 ON p1.post_parent = p2.ID WHERE p1.post_parent > 0 AND p1.post_type = 'attachment' AND p2.ID IS NULL");

        /*
         * Delete orphaned comments
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_id FROM {$wpdb->comments});");

        /*
         * Delete duplicated post meta
         */
        $results += $wpdb->query("DELETE t1 FROM {$wpdb->postmeta} t1 INNER JOIN {$wpdb->postmeta} t2 WHERE t1.meta_id < t2.meta_id AND t1.meta_key = t2.meta_key AND t1.meta_value = t2.meta_value AND t1.post_id = t2.post_id");

        /*
         * Delete useless post meta
         */
        $results += $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_edit_lock';");
        $results += $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_edit_last';");
        $results += $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_gform-entry-id';");
        $results += $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_gform-form-id';");
        $results += $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_encloseme';");

        /*
         * Delete orphaned post meta
         */
        $results += $wpdb->query("DELETE pm FROM {$wpdb->postmeta} AS pm LEFT JOIN {$wpdb->posts} wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL;");

        /*
        * Delete empty postmeta
        */
        $results += $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_value = '';");

        /*
        * Clear terms relationships
        */
        $results += $wpdb->query("DELETE tr FROM {$wpdb->term_taxonomy} AS tr LEFT JOIN {$wpdb->terms} ON tr.term_id = {$wpdb->terms}.term_id WHERE {$wpdb->terms}.term_id is NULL;");
        $results += $wpdb->query("DELETE tr FROM {$wpdb->term_relationships} AS tr LEFT JOIN {$wpdb->posts} ON tr.object_id = {$wpdb->posts}.ID LEFT JOIN {$wpdb->term_taxonomy} ON tr.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id WHERE {$wpdb->posts}.ID is NULL AND ({$wpdb->term_taxonomy}.taxonomy = 'category' OR {$wpdb->term_taxonomy}.taxonomy is NULL);");

        return $results;
    }

    public static function getInstance(): DBSupport
    {
        if (!isset(self::$_Instance)) {
            self::$_Instance = new self();
        }

        return self::$_Instance;
    }

    /**
     * Manage database tables
     *
     * @param $action
     * @param array $tables
     * @return bool|int
     */
    public static function manage_tables($action, array $tables = array())
    {
        global $wpdb;

        if (empty($tables)) {
            return false;
            //$tables = $wpdb->get_col('SHOW TABLES');
        }

        $succeeded = 0;

        switch ($action) {

            case 'myisam':

                foreach ($tables as $table) {
                    if ($wpdb->query("ALTER TABLE {$table} ENGINE = MyISAM"))
                        $succeeded++;
                }
                break;

            case 'innodb':

                foreach ($tables as $table) {
                    if ($wpdb->query("ALTER TABLE {$table} ENGINE = InnoDB"))
                        $succeeded++;
                }
                break;

            case 'delete':

                foreach ($tables as $table) {
                    if ($wpdb->query("DROP TABLE {$table}"))
                        $succeeded++;
                }
                break;

            case 'optimize':

                foreach ($tables as $table) {
                    if ($wpdb->query("OPTIMIZE TABLE {$table}"))
                        $succeeded++;
                }
                break;

            case 'empty':

                foreach ($tables as $table) {
                    if ($wpdb->query("TRUNCATE TABLE {$table}"))
                        $succeeded++;
                }
                break;

            case 'repair':

                foreach ($tables as $table) {
                    $query_result = $wpdb->get_results("REPAIR TABLE " . $table);
                    $succeeded++;
                    if (isset($query_result[0]->Msg_type)) {
                        $succeeded--;
                    }
                }
                break;
        }

        return $succeeded;
    }

    public static function restore_db($backup)
    {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE {$table}");
        }

        $query = '';

        $lines = file($backup);

        foreach ($lines as $line) {

            if (str_starts_with($line, '--') || $line == '') {
                continue;
            }

            $query .= $line;

            if (str_ends_with(trim($line), ';')) {

                $wpdb->query($query);

                $query = '';
            }

            set_time_limit(30);
        }
        return true;
    }

    public static function queryDump_db($SQLfilename, $_excluded_tables = array(), $row_number = 500)
    {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');
        $output = '';

        if (file_exists($SQLfilename))
            unlink($SQLfilename);

        $file_descriptor = fopen($SQLfilename, 'w');

        $available_memory = UtilEnv::size2bytes(@ini_get('memory_limit'));

        foreach ($tables as $table) {

            set_time_limit(60);

            if (empty($_excluded_tables) or !(in_array($table, $_excluded_tables))) {

                /**
                 * Rules for CREATE TABLE
                 */
                $result = $wpdb->get_row('SHOW CREATE TABLE ' . $table, ARRAY_N);
                $output .= $result[1] . ";" . PHP_EOL . PHP_EOL;

                $iter = 0;

                while ($result = $wpdb->get_results("SELECT * FROM {$table} LIMIT {$row_number} OFFSET " . ($iter++ * $row_number), ARRAY_N)) {

                    $nres = count($result) - 1;

                    $output .= 'INSERT INTO ' . $table . ' VALUES';
                    foreach ($result as $n => $row) {

                        $row = array_map(array($wpdb, '_real_escape'), $row);

                        $output .= '( "' . implode('", "', $row) . '" )';

                        if ($n < $nres) $output .= ', ';
                    }

                    $output .= ';' . PHP_EOL;

                    if ($available_memory - memory_get_usage() < 10485760) {
                        fwrite($file_descriptor, $output);
                        $output = '';
                        $wpdb->flush();
                    }

                }
                fwrite($file_descriptor, $output);
                $output = '';
                $wpdb->flush();
            }
        }

        $wpdb->flush();

        fclose($file_descriptor);

        return true;
    }

    public static function mysqlDump_db($SQLfilename, $_excluded_tables = array(), $mysqldump_locations = '')
    {
        set_time_limit(120);

        $host = explode(':', DB_HOST, 2);

        $host = $host[0];
        $port = str_contains(DB_HOST, ':') ? $host[1] : '';

        // Path to the mysqldump executable
        $cmd = escapeshellarg(self::get_mysqlDump_cmd_path($mysqldump_locations));

        // We don't want to create a new DB
        $cmd .= ' --single-transaction --no-create-db --hex-blob';

        // Username
        $cmd .= ' -u ' . escapeshellarg(DB_USER);

        // Don't pass the password if it's blank
        if (DB_PASSWORD) {
            $cmd .= ' -p' . escapeshellarg(DB_PASSWORD);
        }

        // Set the host
        $cmd .= ' -h ' . escapeshellarg($host);

        // Set the port if it was set
        if (!empty($port) and is_numeric($port)) {
            $cmd .= ' -P ' . $port;
        }

        // The file we're saving too
        $cmd .= ' -r ' . escapeshellarg($SQLfilename);

        if (!empty($_excluded_tables)) {
            $cmd .= implode(' --ignore-table=' . DB_NAME . '.', $_excluded_tables);
        }

        // The database we're dumping
        $cmd .= ' ' . escapeshellarg(DB_NAME);

        // Pipe STDERR to STDOUT
        $cmd .= ' 2>&1';

        $gone_ok = @shell_exec($cmd);

        if (!$gone_ok) {

            if (file_exists($SQLfilename))
                unlink($SQLfilename);

            return false;
        }

        // If we have an empty file delete it
        if (@filesize($SQLfilename) === 0) {
            unlink($SQLfilename);
            return false;
        }

        return true;
    }

    public static function get_mysqlDump_cmd_path($mysqldump_path = '')
    {
        // Check shell_exec is available
        if (!UtilEnv::is_shell_exec_available())
            return false;

        if (!empty($mysqldump_locations)) {

            return @is_executable(UtilEnv::normalize_path($mysqldump_locations));
        }

        // check mysqldump command
        if (is_null(shell_exec('hash mysqldump 2>&1'))) {
            return 'mysqldump';
        }

        $mysqldump_locations = array(
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/usr/mysql/bin/mysqldump',
            '/usr/bin/mysqldump',
            '/opt/local/lib/mysql6/bin/mysqldump',
            '/opt/local/lib/mysql5/bin/mysqldump',
            '/opt/local/lib/mysql4/bin/mysqldump',
            '/xampp/mysql/bin/mysqldump',
            '/Program Files/xampp/mysql/bin/mysqldump',
            '/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump',
            '/Program Files/MySQL/MySQL Server 6.0/bin/mysqldump',
            '/Program Files/MySQL/MySQL Server 5.5/bin/mysqldump',
            '/Program Files/MySQL/MySQL Server 5.4/bin/mysqldump',
            '/Program Files/MySQL/MySQL Server 5.1/bin/mysqldump',
            '/Program Files/MySQL/MySQL Server 5.0/bin/mysqldump',
            '/Program Files/MySQL/MySQL Server 4.1/bin/mysqldump'
        );

        $mysqldump_command_path = '';

        // Find the one which works
        foreach ((array)$mysqldump_locations as $location) {
            if (@is_executable(UtilEnv::normalize_path($location)))
                $mysqldump_command_path = $location;
        }

        return empty($mysqldump_command_path) ? false : $mysqldump_command_path;
    }

}