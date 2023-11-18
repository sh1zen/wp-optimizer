<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use WPS\core\UtilEnv;

class Minify_CSS extends Minify
{
    public function run()
    {
        $css = $this->content;

        // strip new lines
        $css = preg_replace('#\r\n|\n#', '', $css);

        // Normalize whitespace
        $css = preg_replace('#\s+#', ' ', $css);

        // Remove comment blocks, everything between /* and */, unless preserved with /*! ... */
        $css = preg_replace('#/\*[^*]*\*+([^/][^*]*\*+)*/#', '', $css);

        // Converts all zeros value into shorthand
        $css = preg_replace('#0 0 0 0#', '0', $css);

        // Remove space after or before , : ; { } */ >
        $css = preg_replace('#\s?(,|:|;|\{|}|\*/|>)\s?#', '$1', $css);

        // Strips leading 0 on decimal values (converts 0.5px into .5px)
        $css = preg_replace('#(:| )0\.([0-9]+)(%|em|ex|px|in|cm|mm|pt|pc)#i', '${1}.${2}${3}', $css);

        // Strips units if value is 0 (converts 0px to 0)
        $css = preg_replace('#(:| )(\.?)0(%|em|ex|px|in|cm|mm|pt|pc)#i', '${1}0', $css);

        // Shorter 6-character hex color codes to 3-character where possible
        $css = preg_replace('/#([a-f0-9])\\1([a-f0-9])\\2([a-f0-9])\\3/i', '#\1\2\3', $css);

        if (!empty($this->options['file_path'])) {
            $css = preg_replace_callback("#url\((([\s'\"])*(\.?\.?/).*)\)#Ui", [$this, 'fix_url'], $css);
        }

        $this->content = trim($css);
    }

    public function fix_url($matches)
    {
        list($r, $url) = $matches;

        $realPath = UtilEnv::realpath($this->options['file_path'] . dirname($url), false, true);

        $relative_path = UtilEnv::relativePath(UtilEnv::normalize_path(WPOPT_STORAGE . "minify/css/"), $realPath);

        $basename = basename($url);

        return "url({$relative_path}{$basename})";
    }
}