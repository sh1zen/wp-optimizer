<?php

class WPQuery_Cache extends Cache_Dispatcher
{
    protected static $_Instance;

    public static function check_status()
    {
        add_action('clean_post_cache', 'WPQuery_Cache::clear_cache', 10, 0);
        add_action('clean_page_cache', 'WPQuery_Cache::clear_cache', 10, 0);
        add_action('clean_attachment_cache', 'WPQuery_Cache::clear_cache', 10, 0);
        add_action('clean_comment_cache', 'WPQuery_Cache::clear_cache', 10, 0);

        add_action('clean_term_cache', 'WPQuery_Cache::clear_cache', 10, 0);
        add_action('clean_object_term_cache', 'WPQuery_Cache::clear_cache', 10, 0);
        add_action('clean_taxonomy_cache', 'WPQuery_Cache::clear_cache', 10, 0);

        add_action('clean_user_cache', 'WPQuery_Cache::clear_cache', 10, 0);
    }

    public static function clear_cache()
    {
        WOStorage::getInstance()->delete(self::get_cache_group());
    }

    /**
     * Return a singleton instance of the current class
     *
     * @param array $args
     * @return Cache_Dispatcher
     */
    public static function Initialize($args = array())
    {
        if (!self::$_Instance) {
            self::$_Instance = new self($args);
        }

        return self::$_Instance;
    }

    public function action_enable_cache($request, $wp_query)
    {
        $this->reset();

        if ($wp_query->query_vars['cache_results'])
            $this->cache_active = true;

        return $request;
    }

    public function action_found_posts($found_posts, $wp_query)
    {
        return $this->total_found = $found_posts;
    }

    public function action_posts_pre_query($posts, $wp_query)
    {
        if (!$this->cache_active)
            return $posts;

        $cached_data = $this->cache_get($this->generate_key($wp_query->query_vars));

        if ($cached_data) {

            $posts = $this->objectify_cached($cached_data['posts']);

            if (!is_null($posts)) {

                $wp_query->found_posts = $cached_data['found_posts'];
                $wp_query->max_num_pages = ceil($cached_data['found_posts'] / $wp_query->get('posts_per_page'));
            }

            $this->reset();
        }

        if (is_null($posts)) {
            $this->try_to_cache_hooks();
        }

        return $posts;
    }

    private function try_to_cache_hooks()
    {
        // to keep queried posts
        add_filter('posts_results', array($this, 'action_posts_results'), 10, 2);

        // to keep queried found_posts
        add_filter('found_posts', array($this, 'action_found_posts'), 10, 2);

        add_filter('the_posts', array($this, 'commit'), 10, 2);
    }

    public function action_posts_results($posts, $wp_query)
    {
        if ($this->cache_active) {
            $this->query_result = $posts;
        }

        return $posts;
    }

    /**
     * Filter the posts array to contain cached search results.
     *
     * @param array $posts
     * @param $wp_query
     * @return array
     */
    public function commit($posts, $wp_query)
    {
        if (!$this->cache_active)
            return $posts;

        $key = $this->generate_key($wp_query->query_vars);

        $data = array(
            'posts'       => $this->query_result,
            'found_posts' => $this->total_found,
        );

        $this->cache_set($key, $data, $this->args['lifespan']);

        $this->reset();

        return $posts;
    }

    /**
     * Hook into filters
     */
    protected function check_cached_hooks()
    {
        //check if can enable cache for this wp_query
        add_filter('posts_request', array($this, 'action_enable_cache'), 10, 2);

        // to load
        add_filter('posts_pre_query', array($this, 'action_posts_pre_query'), 10, 2);
    }
}

