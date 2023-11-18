<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class TextReplacer
{
    /**
     * @param string[] $string $string
     * @param null $object
     * @param string $type "search|post_archive|home|post|term|user|date|404|none"
     * @return array
     */
    public static function replace_array(array $string = array(), $object = null, string $type = 'post'): array
    {
        $return_array = [];
        foreach ($string as $key => $value) {
            $return_array[$key] = self::replace($value, $object, $type);
        }

        return $return_array;
    }

    /**
     * @param string $string
     * @param \WP_Term|\WP_Post_Type|\WP_Post|\WP_User|null $object
     * @param string $type "search|post_archive|home|post|term|user|date|404|none"
     * @return string
     */
    public static function replace(string $string, $object = null, string $type = 'post'): string
    {
        global $wp_query;

        if (empty($string)) {
            return '';
        }

        $rules = array();

        // Don't bother if there are no % - saves some processing.
        if (!str_contains($string, '%')) {
            return $string;
        }

        preg_match_all("#%%([^%]+)%%#Us", $string, $rules);

        $rules = array_filter(array_map('trim', $rules[1]));

        if (empty($rules)) {
            return $string;
        }

        $rules = apply_filters('wpfs_replacer_rules', $rules, $object, $type);

        foreach ($rules as $rule) {

            $replacement = '';

            if ($replace = self::replace_custom($rule, $object, $type)) {
                $replacement = $replace;
            }
            elseif ($replace = self::replace_property($rule, $object, $type)) {
                $replacement = $replace;
            }
            elseif ($replace = self::replace_meta($rule, $object, $type)) {
                $replacement = $replace;
            }
            elseif ($wp_query and $replace = get_query_var($rule, false)) {
                $replacement = esc_html($replace);
            }
            elseif ($replace = self::replace_static($rule, $object, $type)) {
                $replacement = $replace;
            }

            /**
             * fallback for custom replace also
             */
            $replacement = apply_filters("wpfs_replacement_{$rule}_$type", $replacement, $string, $object);

            $string = str_replace("%%{$rule}%%", $replacement, $string);
        }

        return StringHelper::clear($string, true, ' ');
    }

    /**
     * @param $rule
     * @param \WP_Term|\WP_Post_Type|\WP_Post|\WP_User|null $object
     * @param string $type
     * @return mixed
     */
    public static function replace_property($rule, $object = null, string $type = 'post')
    {
        if (!$object) {
            return false;
        }

        if (isset($object->$rule)) {
            return $object->$rule;
        }

        return false;
    }

    /**
     * @param $rule
     * @param \WP_Term|\WP_Post_Type|\WP_Post|\WP_User|null $object
     * @param string $type
     * @return mixed
     */
    public static function replace_meta($rule, $object = null, string $type = 'post')
    {
        if (!$object or !str_starts_with($rule, "meta")) {
            return false;
        }

        $meta = str_replace("meta_", "", $rule);

        return get_metadata_raw($type, $object->ID, $meta, true);
    }

    /**
     * @param $rule
     * @param \WP_Term|\WP_Post_Type|\WP_Post|\WP_User|null $object
     * @param string $type
     * @return mixed
     */
    public static function replace_custom($rule, $object, string $type = 'post')
    {
        $res = false;

        $replacer = wps('wpfs')->cache->get("$rule-$type", "Replacer");

        if ($replacer) {
            if (is_callable($replacer)) {
                $res = call_user_func($replacer, $object);

                // update the callable with its result to get more efficiency
                wps('wpfs')->cache->set("$rule-$type", $res, "Replacer", true);
            }
            else {
                $res = $replacer;
            }
        }

        return $res;
    }

    /**
     * @param $rule
     * @param \WP_Term|\WP_Post_Type|\WP_Post|\WP_User|null $object
     * @param string $type
     * @return mixed
     */
    public static function replace_static($rule, $object = null, string $type = 'post')
    {
        $res = false;

        $wp_query = $GLOBALS['wp_the_query'];

        switch ($rule) {
            case 'sep':
                $res = wps('wpfs')->settings->get('seo.title.separator', '-');
                break;

            case 'sitename':
                $res = get_bloginfo('name');
                break;

            case 'search':
                $res = get_search_query();
                break;

            case 'resume':
            case 'description':
                if ($object instanceof \WP_Post) {
                    $res = wpfs_get_post_excerpt($object, 150, '...');
                }
                elseif ($object instanceof \WP_Term) {
                    $res = StringHelper::truncate($object->description ?? '', 150, '...');
                }
                break;

            case 'excerpt':
                if ($object instanceof \WP_Post) {
                    $res = $object->post_excerpt;
                }
                break;

            case 'title':
                $res = wpfs_document_title(wps('wpfs')->settings->get('seo.title.separator', '-'));
                break;

            case 'userdisplayname':
                if ($object instanceof \WP_User) {
                    $res = $object->display_name;
                }
                break;

            case 'sitedesc':
                $res = get_bloginfo('description');
                break;

            case 'language':
                $res = get_bloginfo('language');
                break;

            case 'date':
                $res = wp_date('Y-m-d');
                break;

            case 'time':
                $res = wp_date('H:i:s');
                break;

            case 'modified':
                if ($object instanceof \WP_Post) {
                    $res = $object->post_modified;
                }
                break;

            case 'created':
                if ($object instanceof \WP_Post) {
                    $res = $object->post_date;
                }
                break;

            case 'found_post':
                $res = $wp_query->found_posts;
                break;

            case 'pagenumber':
                if ($object instanceof \WP_Post) {
                    $res = $wp_query->get('paged', 0);
                }
                break;

            case 'pagetotal':
                $res = $wp_query->max_num_pages;
                break;
        }

        return $res;
    }

    /**
     * Add a custom replacement rule with query type support
     *
     * @param string $rule The rule ex. `%%custom_replace%%`
     * @param String|callable $replacement
     * @param string|string[] $type
     */
    public static function add_replacer(string $rule, $replacement, $type = ''): void
    {
        if (is_array($type)) {
            foreach ($type as $_type) {
                self::add_replacer($rule, $replacement, $_type);
            }
            return;
        }

        wps('wpfs')->cache->set("$rule-$type", $replacement, "Replacer");
    }
}