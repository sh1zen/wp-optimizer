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

    private function extract_post_ids($posts): ?array
    {
        if (!is_array($posts)) {
            return null;
        }

        $post_ids = [];

        foreach ($posts as $post) {
            if (is_numeric($post)) {
                $post_id = absint($post);
            }
            elseif (is_array($post)) {
                $post_id = absint($post['ID'] ?? 0);
            }
            elseif (is_object($post)) {
                $post_id = absint($post->ID ?? 0);
            }
            else {
                return null;
            }

            if (!$post_id) {
                return null;
            }

            $post_ids[] = $post_id;
        }

        return $post_ids;
    }

    private function restore_cached_posts($cached_posts): ?array
    {
        $post_ids = $this->extract_post_ids($cached_posts);

        if (is_null($post_ids)) {
            return null;
        }

        if (empty($post_ids)) {
            return [];
        }

        _prime_post_caches($post_ids);

        $posts = array_map('get_post', $post_ids);

        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                return null;
            }
        }

        return $posts;
    }

    private function cache_delete(string $key): void
    {
        if ($key === '') {
            return;
        }

        wps('wpopt')->storage->delete(static::get_cache_group(), $key);
    }

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

        if (is_array($cached_data) and array_key_exists('posts', $cached_data)) {

            $posts = $this->restore_cached_posts($cached_data['posts']);

            if (is_array($posts)) {
                if (function_exists('wpopt_record_cache_metric')) {
                    wpopt_record_cache_metric('query', 'hit');
                }

                $found_posts = absint($cached_data['found_posts'] ?? 0);
                $posts_per_page = (int)$wp_query->get('posts_per_page');

                $wp_query->found_posts = $found_posts;
                $wp_query->max_num_pages = $posts_per_page > 0 ? (int)ceil($found_posts / $posts_per_page) : 0;
            }
            else {
                $this->cache_delete($wp_query->query_vars_hash);
            }
        }

        if (is_null($posts)) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('query', 'miss');
            }
            $this->data[$wp_query->query_vars_hash] = [];
        }

        return $posts;
    }

    public function action_posts_results($posts, \WP_Query $wp_query)
    {
        if (isset($this->data[$wp_query->query_vars_hash])) {
            $post_ids = $this->extract_post_ids($posts);

            if (is_array($post_ids)) {
                $this->data[$wp_query->query_vars_hash]['posts'] = $post_ids;
            }
            else {
                unset($this->data[$wp_query->query_vars_hash]);
            }
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
                [
                    'posts'       => $this->data[$wp_query->query_vars_hash]['posts'] ?? [],
                    'found_posts' => absint($this->data[$wp_query->query_vars_hash]['found_posts'] ?? 0),
                ]
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
