<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\UtilEnv;

class Minify_CSS extends Minify
{
    private const PLACEHOLDER_PREFIX = "\x1A";
    private const PLACEHOLDER_SUFFIX = "\x1B";

    private array $preserved = [];
    private int $preservedIndex = 0;

    public function run(): void
    {
        $css = $this->content;

        if (strlen($css) < 50) {
            $this->content = trim($css);
            return;
        }

        $this->preserved = [];
        $this->preservedIndex = 0;

        // 1. Preserve important comments /*! ... */
        $css = preg_replace_callback(
            '#/\*![\s\S]*?\*/#',
            fn($m) => $this->preserve($m[0]),
            $css
        );

        // 2. Preserve calc(), min(), max(), clamp() with balanced parentheses
        $css = $this->preserveMathFunctions($css);

        // 3. Preserve url() content
        $css = preg_replace_callback(
            '#url\(\s*(["\']?)(.+?)\1\s*\)#i',
            fn($m) => 'url(' . $this->preserve($m[1] . $m[2] . $m[1]) . ')',
            $css
        );

        // 4. Preserve quoted content values
        $css = preg_replace_callback(
            '#(content\s*:\s*)(["\'])(.+?)\2#i',
            fn($m) => $m[1] . $this->preserve($m[2] . $m[3] . $m[2]),
            $css
        );

        // 5. Remove regular comments
        $css = preg_replace('#/\*[^*]*\*+([^/*][^*]*\*+)*/#', '', $css);

        // 6. Normalize whitespace
        $css = preg_replace('#[\r\n\t]+#', ' ', $css);
        $css = preg_replace('#  +#', ' ', $css);

        // 7. Remove spaces carefully
        $css = preg_replace('#\s*([{};:,>~+\])])\s*#', '$1', $css);
        $css = preg_replace('#([\[(])\s*#', '$1', $css);

        // 8. Remove last semicolon before }
        $css = str_replace(';}', '}', $css);

        // 9. Optimize standalone zero units (not in shorthand middle positions)
        // Match zero with unit only when followed by ; } , or !
        $css = preg_replace('#:0(\.0*)?(px|em|rem|%|ex|ch|vw|vh|vmin|vmax|cm|mm|in|pt|pc)(?=[;}!,])#i', ':0', $css);

        // 10. Strip leading zero from decimals
        $css = preg_replace('#(:|\s)0+\.(\d)#', '$1.$2', $css);

        // 11. Shorten hex colors #aabbcc -> #abc
        $css = preg_replace('/#([a-f0-9])\1([a-f0-9])\2([a-f0-9])\3(?=[;\s},!)]|$)/i', '#$1$2$3', $css);

        // 12. border/outline: none -> 0
        $css = preg_replace('#(border|outline):none(?=[;}!])#i', '$1:0', $css);

        // 13. font-weight: normal/bold -> 400/700
        $css = preg_replace_callback(
            '#font-weight:(normal|bold)(?=[;}!])#i',
            fn($m) => 'font-weight:' . (strtolower($m[1]) === 'normal' ? '400' : '700'),
            $css
        );

        // 14. Restore preserved content
        $css = $this->restorePreserved($css);

        // 15. Fix relative URLs if needed
        if (!empty($this->options['file_path'])) {
            $css = $this->fixUrls($css);
        }

        $this->content = trim($css);
    }

    private function preserve(string $content): string
    {
        $placeholder = self::PLACEHOLDER_PREFIX . $this->preservedIndex . self::PLACEHOLDER_SUFFIX;
        $this->preserved[$placeholder] = $content;
        $this->preservedIndex++;
        return $placeholder;
    }

    private function preserveMathFunctions(string $css): string
    {
        $pattern = '#\b(calc|min|max|clamp)\s*\(#i';
        $offset = 0;

        while (preg_match($pattern, $css, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $funcStart = (int) $match[0][1];
            $parenStart = $funcStart + strlen($match[0][0]) - 1;

            $depth = 1;
            $i = $parenStart + 1;
            $len = strlen($css);

            while ($i < $len && $depth > 0) {
                $char = $css[$i];
                if ($char === '(') {
                    $depth++;
                }
                elseif ($char === ')') {
                    $depth--;
                }
                $i++;
            }

            if ($depth === 0) {
                $fullMatch = substr($css, $funcStart, $i - $funcStart);
                $placeholder = $this->preserve($fullMatch);
                $css = substr($css, 0, $funcStart) . $placeholder . substr($css, $i);
                $offset = $funcStart + strlen($placeholder);
            }
            else {
                $offset = $parenStart + 1;
            }
        }

        return $css;
    }

    private function restorePreserved(string $css): string
    {
        if (empty($this->preserved)) {
            return $css;
        }

        return str_replace(
            array_keys($this->preserved),
            array_values($this->preserved),
            $css
        );
    }

    private function fixUrls(string $css): string
    {
        if (stripos($css, 'url(') === false) {
            return $css;
        }

        $basePath = dirname($this->options['file_path']);
        $targetDir = UtilEnv::normalize_path(WPOPT_STORAGE . 'minify/css/');

        return preg_replace_callback(
            '#url\(\s*(["\']?)([^)]+?)\1\s*\)#i',
            function ($matches) use ($basePath, $targetDir) {
                $quote = $matches[1];
                $url = trim($matches[2]);

                // Skip data URIs, absolute URLs, protocol-relative, absolute paths
                if (preg_match('#^(data:|https?://|//|/)#i', $url)) {
                    return $matches[0];
                }

                $realPath = UtilEnv::realpath($basePath . '/' . dirname($url), false, true);

                if ($realPath === false) {
                    return $matches[0];
                }

                $relativePath = UtilEnv::relativePath($targetDir, $realPath);
                $basename = basename($url);

                return "url({$quote}{$relativePath}{$basename}{$quote})";
            },
            $css
        );
    }
}