<?php
namespace WPOptimizer\modules\supporters;

/** Own the query-type catalog and the settings/runtime normalization contracts. */
final class QueryCacheTypes
{
    public const DEFAULT_TYPES = array(
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

    /** Normalize settings in input order, or in catalog order for runtime query selection. */
    public static function normalize($types, bool $catalog_order = false): array
    {
        if (is_string($types)) {
            $types = $types === '' ? array() : preg_split("#[\s,]+#", $types);
        }

        if (!is_array($types)) {
            return self::DEFAULT_TYPES;
        }

        $normalized = array();
        $has_input = false;
        foreach ($types as $type) {
            $has_input = $has_input || (bool)$type;
            $type = sanitize_key($catalog_order ? $type : (string)$type);
            if (in_array($type, self::DEFAULT_TYPES, true)) {
                $normalized[$type] = true;
            }
        }

        if (!$normalized && $has_input) {
            return self::DEFAULT_TYPES;
        }

        return $catalog_order
            ? array_keys(array_intersect_key(array_flip(self::DEFAULT_TYPES), $normalized))
            : array_keys($normalized);
    }
}
