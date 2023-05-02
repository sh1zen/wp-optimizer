<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

use SHZN\core\StringHelper;
use SHZN\core\UtilEnv;

class Minify
{
    protected string $content;

    protected bool $keepComments;

    protected bool $multiProcess = false;

    protected array $options;

    public function __construct($content, $options = [])
    {
        $this->content = str_replace("\r\n", "\n", trim($content));
        $this->options = $options;

        $this->keepComments = isset($options['comments']) && UtilEnv::to_boolean($options['comments']);
    }

    public static function minify($content, $options = [])
    {
        $minifier = new static($content, $options);

        $minifier->run();

        return $minifier->export();
    }

    public function run()
    {
    }

    public function export()
    {
        return $this->content;
    }

    /**
     * Hides everything between noptimize-comment tags.
     *
     * @param string $markup Markup to process.
     *
     * @return string
     */
    protected final function hide_noptimize($markup)
    {
        return StringHelper::replace_contents_with_marker_if_exists(
            'NOPTIMIZE',
            '/<!--\s?noptimize\s?-->/',
            '#<!--\s?noptimize\s?-->.*?<!--\s?/\s?noptimize\s?-->#is',
            $markup
        );
    }

    /**
     * Unhide noptimize-tags.
     *
     * @param string $markup Markup to process.
     *
     * @return string
     */
    protected final function restore_noptimize($markup)
    {
        return StringHelper::restore_marked_content('NOPTIMIZE', $markup);
    }

    /**
     * Hides "iehacks" content.
     *
     * @param string $markup Markup to process.
     *
     * @return string
     */
    protected final function hide_iehacks($markup)
    {
        return StringHelper::replace_contents_with_marker_if_exists(
            'IEHACK', // Marker name...
            '<!--[if', // Invalid regex, will fallback to search using strpos()...
            '#<!--\[if.*?\[endif\]-->#is', // Replacement regex...
            $markup
        );
    }

    /**
     * Restores "hidden" iehacks content.
     *
     * @param string $markup Markup to process.
     *
     * @return string
     */
    protected final function restore_iehacks($markup)
    {
        return StringHelper::restore_marked_content('IEHACK', $markup);
    }

    /**
     * "Hides" content within HTML comments using a regex-based replacement
     * if HTML comment markers are found.
     * `<!--example-->` becomes `%%COMMENTS%%ZXhhbXBsZQ==%%COMMENTS%%`
     *
     * @param string $markup Markup to process.
     *
     * @return string
     */
    protected final function hide_comments($markup)
    {
        return StringHelper::replace_contents_with_marker_if_exists(
            'COMMENTS',
            '<!--',
            '#<!--.*?-->#is',
            $markup
        );
    }

    /**
     * Restores original HTML comment markers inside a string whose HTML
     * comments have been "hidden" by using `hide_comments()`.
     *
     * @param string $markup Markup to process.
     *
     * @return string
     */
    protected final function restore_comments($markup)
    {
        return StringHelper::restore_marked_content('COMMENTS', $markup);
    }

}