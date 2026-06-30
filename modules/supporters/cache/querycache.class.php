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

    private const DEFAULT_QUERY_TYPES = array(
        'post',
        'page',
        'attachment',
        'custom_post_type',
        'taxonomy_query',
        'meta_query',
        'search_query',
        'author_query',
        'date_query',
        'id_query',
        'feed_query',
    );

    private array $data = [];
    private string $query_request_path = '';
    private ?CacheRequestPolicy $request_policy = null;

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

    private function request_is_cacheable(): bool
    {
        if ($this->runtime_cache_is_suspended()) {
            return false;
        }

        $this->query_request_path = CacheRequestPolicy::normalize_request_path();

        if ($this->request_policy()->has_no_cache_cookie() || $this->request_policy()->user_agent_is_excluded()) {
            return false;
        }

        return true;
    }

    private function runtime_cache_is_suspended(): bool
    {
        return function_exists('wpopt_cache_runtime_is_suspended') && wpopt_cache_runtime_is_suspended('wp_query');
    }

    private function request_policy(): CacheRequestPolicy
    {
        if (!$this->request_policy) {
            $this->request_policy = new CacheRequestPolicy(array_merge($this->options, array(
                'plain_text_patterns_only' => true,
            )));
        }

        return $this->request_policy;
    }

    private function record_query_rule_write(string $cache_key, array $post_ids = array()): void
    {
        StaticCacheRules::record_write(
            '',
            $cache_key,
            $this->query_request_path,
            count($post_ids),
            'wp_query',
            $this->get_query_dependencies($post_ids)
        );
    }

    private function query_type_is_cacheable(\WP_Query $wp_query): bool
    {
        $allowed_types = $this->get_allowed_query_types();

        if (empty($allowed_types)) {
            return false;
        }

        return !empty(array_intersect($allowed_types, $this->get_query_types($wp_query)));
    }

    private function get_allowed_query_types(): array
    {
        $types = $this->options['query_types'] ?? self::DEFAULT_QUERY_TYPES;

        if (is_string($types)) {
            $types = $types === '' ? array() : preg_split("#[\s,]+#", $types);
        }

        if (!is_array($types)) {
            return self::DEFAULT_QUERY_TYPES;
        }

        $normalized = array_values(array_intersect(self::DEFAULT_QUERY_TYPES, array_map('sanitize_key', $types)));

        if (empty($normalized) && !empty(array_filter($types))) {
            return self::DEFAULT_QUERY_TYPES;
        }

        return $normalized;
    }

    private function get_query_types(\WP_Query $wp_query): array
    {
        $types = array();
        $query_vars = is_array($wp_query->query_vars) ? $wp_query->query_vars : array();
        $post_types = $this->get_query_post_types($query_vars);

        foreach ($post_types as $post_type) {
            if ($post_type === 'post') {
                $types[] = 'post';
            }
            elseif ($post_type === 'page') {
                $types[] = 'page';
            }
            elseif ($post_type === 'attachment') {
                $types[] = 'attachment';
            }
            else {
                $types[] = 'custom_post_type';
            }
        }

        if ($this->query_has_taxonomy_constraints($query_vars)) {
            $types[] = 'taxonomy_query';
        }

        if ($this->query_has_meta_constraints($query_vars)) {
            $types[] = 'meta_query';
        }

        if ($this->query_has_search_constraints($wp_query, $query_vars)) {
            $types[] = 'search_query';
        }

        if ($this->query_has_author_constraints($wp_query, $query_vars)) {
            $types[] = 'author_query';
        }

        if ($this->query_has_date_constraints($wp_query, $query_vars)) {
            $types[] = 'date_query';
        }

        if ($this->query_has_id_constraints($query_vars)) {
            $types[] = 'id_query';
        }

        if ($wp_query->is_feed()) {
            $types[] = 'feed_query';
        }

        if (empty($types)) {
            $types[] = 'post';
        }

        return array_values(array_unique($types));
    }

    private function get_query_post_types(array $query_vars): array
    {
        $post_type = $query_vars['post_type'] ?? '';

        if ($post_type === '' || $post_type === null) {
            if (!empty($query_vars['page_id']) || !empty($query_vars['pagename'])) {
                return array('page');
            }

            if (!empty($query_vars['attachment']) || !empty($query_vars['attachment_id'])) {
                return array('attachment');
            }

            return array('post');
        }

        if ($post_type === 'any') {
            return array('post', 'page', 'attachment', 'custom_post_type');
        }

        return array_values(array_filter(array_map('sanitize_key', (array)$post_type)));
    }

    private function query_has_taxonomy_constraints(array $query_vars): bool
    {
        foreach (array('cat', 'category_name', 'category__and', 'category__in', 'category__not_in', 'tag', 'tag_id', 'tag__and', 'tag__in', 'tag__not_in', 'tag_slug__and', 'tag_slug__in', 'tax_query') as $key) {
            if (!empty($query_vars[$key])) {
                return true;
            }
        }

        return false;
    }

    private function query_has_meta_constraints(array $query_vars): bool
    {
        return !empty($query_vars['meta_query']) || !empty($query_vars['meta_key']);
    }

    private function query_has_search_constraints(\WP_Query $wp_query, array $query_vars): bool
    {
        return $wp_query->is_search() || isset($query_vars['s']) && trim((string)$query_vars['s']) !== '';
    }

    private function query_has_author_constraints(\WP_Query $wp_query, array $query_vars): bool
    {
        foreach (array('author', 'author_name', 'author__in', 'author__not_in') as $key) {
            if (!empty($query_vars[$key])) {
                return true;
            }
        }

        return $wp_query->is_author();
    }

    private function query_has_date_constraints(\WP_Query $wp_query, array $query_vars): bool
    {
        foreach (array('year', 'monthnum', 'w', 'day', 'hour', 'minute', 'second', 'm', 'date_query') as $key) {
            if (!empty($query_vars[$key])) {
                return true;
            }
        }

        return $wp_query->is_date();
    }

    private function query_has_id_constraints(array $query_vars): bool
    {
        foreach (array('p', 'page_id', 'attachment_id', 'name', 'pagename', 'post__in', 'post__not_in', 'post_name__in') as $key) {
            if (!empty($query_vars[$key])) {
                return true;
            }
        }

        return false;
    }

    private function get_query_dependencies(array $post_ids): array
    {
        $dependencies = array(
            'post_ids'   => array(),
            'post_types' => array(),
            'authors'    => array(),
            'terms'      => array(),
            'taxonomies' => array(),
        );

        foreach (array_unique(array_map('absint', $post_ids)) as $post_id) {
            if (!$post_id) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $dependencies['post_ids'][] = (string)$post_id;
            $dependencies['post_types'][] = (string)$post->post_type;
            $dependencies['authors'][] = (string)$post->post_author;

            foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
                $term_ids = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));

                if (is_wp_error($term_ids) || empty($term_ids)) {
                    continue;
                }

                $dependencies['taxonomies'][] = (string)$taxonomy;

                foreach ($term_ids as $term_id) {
                    $dependencies['terms'][] = (string)absint($term_id);
                }
            }
        }

        return array_map(static function (array $values): array {
            return array_values(array_unique(array_filter($values)));
        }, $dependencies);
    }

    public static function clear_by_dependencies(array $criteria): int
    {
        return StaticCacheRules::clear_by_dependencies($criteria, static::get_cache_group(), 'wp_query');
    }

    public function action_found_posts($found_posts, \WP_Query $wp_query)
    {
        if ($this->runtime_cache_is_suspended()) {
            return $found_posts;
        }

        $cache_key = $this->request_scoped_cache_key((string)$wp_query->query_vars_hash);

        if (isset($this->data[$cache_key])) {
            $this->data[$cache_key]['found_posts'] = $found_posts;
        }

        return $found_posts;
    }

    public function action_posts_pre_query($posts, \WP_Query $wp_query)
    {
        if ($this->runtime_cache_is_suspended()) {
            return $posts;
        }

        if (!$this->is_cacheable) {
            return $posts;
        }

        if (!$wp_query->query_vars['cache_results'] or $wp_query->query_vars['suppress_filters']) {
            return $posts;
        }

        if (!$this->query_type_is_cacheable($wp_query)) {
            return $posts;
        }

        $cache_key = $this->request_scoped_cache_key((string)$wp_query->query_vars_hash);
        $cached_data = $this->cache_get($cache_key);

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
                $this->cache_delete($cache_key);
            }
        }

        if (is_null($posts)) {
            if (function_exists('wpopt_record_cache_metric')) {
                wpopt_record_cache_metric('query', 'miss');
            }
            $this->data[$cache_key] = [];
        }

        return $posts;
    }

    public function action_posts_results($posts, \WP_Query $wp_query)
    {
        if ($this->runtime_cache_is_suspended()) {
            return $posts;
        }

        if (!$this->is_cacheable) {
            return $posts;
        }

        $cache_key = $this->request_scoped_cache_key((string)$wp_query->query_vars_hash);

        if (isset($this->data[$cache_key])) {
            $post_ids = $this->extract_post_ids($posts);

            if (is_array($post_ids)) {
                $this->data[$cache_key]['posts'] = $post_ids;
            }
            else {
                unset($this->data[$cache_key]);
            }
        }

        return $posts;
    }

    /**
     * Filter the posts array to contain cached search results.
     */
    public function commit($posts, \WP_Query $wp_query)
    {
        if ($this->runtime_cache_is_suspended()) {
            return $posts;
        }

        if (!$this->is_cacheable) {
            return $posts;
        }

        $cache_key = $this->request_scoped_cache_key((string)$wp_query->query_vars_hash);

        if (isset($this->data[$cache_key])) {

            $this->cache_set(
                $cache_key,
                [
                    'posts'       => $this->data[$cache_key]['posts'] ?? [],
                    'found_posts' => absint($this->data[$cache_key]['found_posts'] ?? 0),
                ]
            );
            $this->record_query_rule_write($cache_key, (array)($this->data[$cache_key]['posts'] ?? array()));
            unset($this->data[$cache_key]);
        }

        return $posts;
    }

    protected function launcher()
    {
        if (!$this->request_is_cacheable()) {
            $this->is_cacheable = false;
            return;
        }

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
