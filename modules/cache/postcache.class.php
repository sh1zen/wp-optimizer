<?php

class WPOPT_PostCache
{
    private static $_Instance;
    private static $_args;

    /**
     * @var array Allowed post types to cache
     */
    private $post_types;

    /**
     * @var null|string Cache key
     */
    private $cache_key;

    private $cache_active;

    /**
     * @var int|null Found posts
     */
    private $found_posts;

    private $posts;

    /**
     * Placeholder method
     */
    private function __construct()
    {
        $this->check_cached_hooks();
    }

    /**
     * Hook into filters
     */
    private function check_cached_hooks()
    {
        //check if can enable cache for this wp_query
        add_filter('posts_request', array($this, 'action_enable_cache'), 10, 2);

        // to load
        add_filter('posts_pre_query', array($this, 'action_posts_pre_query'), 10, 2);
    }

    /**
     * Return a singleton instance of the current class
     *
     * @param array $args
     * @return WPOPT_PostCache
     */
    public static function Initialize($args = array())
    {
        if (!self::$_Instance) {

            self::$_args = array_merge(array(
                'lifespan' => MINUTE_IN_SECONDS * 15
            ), $args);

            self::$_Instance = new self();
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

    /**
     * Reset query info
     */
    private function reset()
    {
        $this->cache_key = null;
        $this->found_posts = 0;
        $this->posts = array();
        $this->cache_active = true;
    }

    public function action_found_posts($found_posts, $wp_query)
    {
        return $this->found_posts = $found_posts;
    }

    public function action_posts_pre_query($null, $wp_query)
    {
        if (!$this->cache_active)
            return null;

        $posts = null;

        $key = $this->generate_key($wp_query->query_vars);

        $cached_data = $this->cache_get($key, "WpQuery_postcache");

        if ($cached_data) {

            $posts = $this->get_posts($cached_data['posts']);

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

    private function generate_key($query, $context = '')
    {
        if (!$this->cache_key) {
            $this->cache_key = md5(serialize($query)) . $context;
        }

        return $this->cache_key;
    }

    /**
     * Get value from cache
     *
     * @param string $key Cache key
     * @param string $group Cache group
     *
     * @return mixed
     */
    private function cache_get($key, $group)
    {
        if (function_exists('pods_cache_get')) {
            $value = pods_cache_get($key, $group);
        }
        else {
            $value = WOStorage::getInstance()->load($key, $group);
        }

        return $value;
    }

    /**
     * Get posts from cache
     *
     * @param $posts
     * @return object
     */
    public function get_posts($posts)
    {
        if (is_array($posts) and !empty($posts)) {
            $posts = json_decode(json_encode($posts)); //(object)$posts;
        }
        else {
            $posts = null;
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
            $this->posts = $posts;
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
            'posts'       => $this->posts,
            'found_posts' => $this->found_posts,
        );

        $this->cache_set($key, $data, "WpQuery_postcache", self::$_args['lifespan']);

        $this->reset();

        return $posts;
    }

    /**
     * Set cache value
     *
     * @param string $key Cache key
     * @param mixed $value Cache value
     * @param string $group Cache group
     * @param int|null $expires Cache expiration
     */
    private function cache_set($key, $value, $group, $expires)
    {
        if (function_exists('pods_cache_set')) {
            pods_cache_set($key, $value, $group, $expires);
        }
        else {
            WOStorage::getInstance()->save(array(
                'expire'    => $expires,
                'file_name' => $key,
                'context'   => $group,
                'force'     => true,
                'data'      => $value
            ));
        }

    }

    /**
     * Get post types that support Cache WP_Query
     */
    private function get_supported_post_types()
    {
        $post_types = get_post_types(array(), 'names');

        foreach ($post_types as $post_type) {
            if (true or post_type_supports($post_type, 'Flex_PostCache')) {
                $this->post_types[] = $post_type;
            }
        }
    }

    /**
     * Clear cache
     *
     * @param string $key Cache key
     * @param string $group Cache group
     */
    private function cache_clear($key, $group)
    {
        if (function_exists('pods_cache_clear')) {
            pods_cache_clear($key, $group);
        }
        else {
            WOStorage::getInstance()->remove($group, $key);
        }
    }
}

