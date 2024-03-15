<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

class QueryCache extends Cache_Dispatcher
{
    // fix child invoking
    protected static ?Cache_Dispatcher $_Instance;

    private array $data = [];

    public function action_found_posts($found_posts, \WP_Query $wp_query)
    {
        if (isset($this->data[$wp_query->query_vars_hash])) {
            $this->data[$wp_query->query_vars_hash]['found_posts'] = $found_posts;
        }

        return $found_posts;
    }

    public function action_posts_pre_query($posts, \WP_Query $wp_query)
    {
        if (!$wp_query->query_vars['cache_results'] or $wp_query->query_vars['suppress_filters']) {
            return $posts;
        }

        $cached_data = $this->cache_get($wp_query->query_vars_hash);

        if ($cached_data) {

            $posts = $this->restore_cached_object($cached_data['posts']);

            if (!is_null($posts)) {

                $wp_query->found_posts = $cached_data['found_posts'] ?? 0;
                $wp_query->max_num_pages = ceil($cached_data['found_posts'] / $wp_query->get('posts_per_page'));
            }
        }

        if (is_null($posts)) {
            $this->data[$wp_query->query_vars_hash] = [];
        }

        return $posts;
    }

    public function action_posts_results($posts, \WP_Query $wp_query)
    {
        if (isset($this->data[$wp_query->query_vars_hash])) {
            $this->data[$wp_query->query_vars_hash]['posts'] = $posts;
        }

        return $posts;
    }

    /**
     * Filter the posts array to contain cached search results.
     */
    public function commit($posts, \WP_Query $wp_query)
    {
        if (isset($this->data[$wp_query->query_vars_hash])) {

            $this->cache_set(
                $wp_query->query_vars_hash,
                array_merge(
                    [
                        'posts'       => [],
                        'found_posts' => 0,
                    ],
                    $this->data[$wp_query->query_vars_hash]
                )
            );
            unset($this->data[$wp_query->query_vars_hash]);
        }

        return $posts;
    }

    protected function launcher()
    {
        //check if this can enable cache for this wp_query
        add_filter('posts_pre_query', array($this, 'action_posts_pre_query'), 10, 2);

        // to keep queried posts
        add_filter('posts_results', array($this, 'action_posts_results'), 10, 2);

        // to keep queried found_posts
        add_filter('found_posts', array($this, 'action_found_posts'), 10, 2);

        // maybe save cached
        add_filter('the_posts', array($this, 'commit'), 10, 2);
    }
}