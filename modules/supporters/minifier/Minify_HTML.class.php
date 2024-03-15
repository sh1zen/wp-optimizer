<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

class Minify_HTML extends Minify
{
    private $isXhtml;

    private $_replacementHash = null;
    private $_placeholders = array();

    public function __construct($html, $options = [])
    {
        $options = array_merge([
            'minify_css' => false,
            'minify_js'  => false,
            'comments'   => false
        ], $options);

        parent::__construct(
            $html,
            $options
        );

        $this->isXhtml = str_contains($this->content, '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML');
    }

    public function run()
    {
        $this->_replacementHash = 'MINIFYHTML' . md5($_SERVER['REQUEST_TIME']);
        $this->_placeholders = array();

        // Noptimize.
        $this->content = $this->hide_noptimize($this->content);

        $this->process();

        // Restore noptimize.
        $this->content = $this->restore_noptimize($this->content);

        // fixes for Revslider data attribs
        if (apply_filters('wpopt_minify_filter_dataattrib', false)) {
            $this->content = preg_replace('#\n(data-.*$)\n#Um', ' $1 ', $this->content);
            $this->content = preg_replace('#<[^>]*(=\"[^"\'<>\s]*\")(\w)#', '$1 $2', $this->content);
        }
    }

    protected function process()
    {
        // replace SCRIPTs (and minify) with placeholders
        $this->content = preg_replace_callback('/(\\s*)(<script\\b[^>]*?>)([\\s\\S]*?)<\\/script>(\\s*)/iu', array($this, '_removeScriptCB'), $this->content);

        // replace STYLEs (and minify) with placeholders
        $this->content = preg_replace_callback('/\\s*(<style\\b[^>]*?>)([\\s\\S]*?)<\\/style>\\s*/iu', array($this, '_removeStyleCB'), $this->content);

        // remove HTML comments (not containing IE conditional comments).
        if (!$this->keepComments) {
            $this->content = preg_replace_callback('/<!--([\\s\\S]*?)-->/u', array($this, '_commentCB'), $this->content);
        }

        // replace PREs with placeholders
        $this->content = preg_replace_callback('/\\s*(<pre\\b[^>]*?>[\\s\\S]*?<\\/pre>)\\s*/iu', array($this, '_removeCB'), $this->content);

        // replace TEXTAREAs with placeholders
        $this->content = preg_replace_callback('/\\s*(<textarea\\b[^>]*?>[\\s\\S]*?<\\/textarea>)\\s*/i', array($this, '_removeCB'), $this->content);

        // replace data: URIs with placeholders
        $this->content = preg_replace_callback('/(=("|\')data:.*\\2)/Ui', array($this, '_removeDataURICB'), $this->content);

        // trim each line.
        // replace by space instead of '' to avoid newline after opening tag getting zapped
        $this->content = preg_replace('/^\s+|\s+$/mu', ' ', $this->content);

        // remove ws around block/undisplayed elements
        $this->content = preg_replace('/\\s+(<\\/?(?:area|article|aside|base(?:font)?|blockquote|body'
            . '|canvas|caption|center|col(?:group)?|dd|dir|div|dl|dt|fieldset|figcaption|figure|footer|form'
            . '|frame(?:set)?|h[1-6]|head|header|hgroup|hr|html|legend|li|link|main|map|menu|meta|nav'
            . '|ol|opt(?:group|ion)|output|p|param|section|t(?:able|body|head|d|h|r|foot|itle)'
            . '|ul|video|block|svg)\\b[^>]*>)/iu', '$1', $this->content);

        // remove ws outside all elements
        $this->content = preg_replace_callback('/>([^<]+)</', array($this, '_outsideTagCB'), $this->content);

        // use newlines before 1st attribute in open tags (to limit line lengths)
        $this->content = preg_replace('/(<[a-z\\-]+)\\s+([^>]+>)/iu', "$1 $2", $this->content);

        // reverse order while preserving keys to ensure the last replacement is done first, etc ...
        $this->_placeholders = array_reverse($this->_placeholders, true);

        $this->content = str_replace(array_keys($this->_placeholders), array_values($this->_placeholders), $this->content);

        if ($this->multiProcess) {
            // issue 229: multi-pass to catch scripts that didn't get replaced in text-areas
            $this->content = str_replace(array_keys($this->_placeholders), array_values($this->_placeholders), $this->content);
        }
    }

    protected function _commentCB($m)
    {
        return (str_starts_with($m[1], '[') or str_contains($m[1], '<![')) ? $m[0] : '';
    }

    protected function _outsideTagCB($m)
    {
        return '>' . preg_replace('/^\\s+|\\s+$/', ' ', $m[1]) . '<';
    }

    protected function _reservePlace($content)
    {
        $placeholder = '%' . $this->_replacementHash . count($this->_placeholders) . '%';
        $this->_placeholders[$placeholder] = $content;

        return $placeholder;
    }

    protected function _removeCB($m)
    {
        return $this->_reservePlace($m[1]);
    }

    protected function _removeDataURICB($m)
    {
        return $this->_reservePlace($m[1]);
    }

    protected function _removeStyleCB($m)
    {
        $openStyle = $m[1];

        $css = $m[2];
        // remove HTML comments
        $css = preg_replace('/(?:^\\s*<!--|-->\\s*$)/', '', $css);

        // remove CDATA section markers
        $css = $this->_removeCdata($css);

        if ($this->options['minify_css']) {
            $css = Minify_CSS::minify($css);
        }
        else {
            $css = trim($css);
        }

        return $this->_reservePlace($this->_needsCdata($css) ? "{$openStyle}/*<![CDATA[*/{$css}/*]]>*/</style>" : "{$openStyle}{$css}</style>");
    }

    protected function _removeCdata($str)
    {
        return (str_contains($str, '<![CDATA[')) ? str_replace(array('<![CDATA[', ']]>'), '', $str) : $str;
    }

    protected function _needsCdata($str)
    {
        return ($this->isXhtml and preg_match('/(?:[<&]|--|]]>)/', $str));
    }

    protected function _removeScriptCB($m)
    {
        $openScript = $m[2];
        $js = $m[3];

        // whitespace surrounding? preserve at least one space
        $ws1 = ($m[1] === '') ? '' : ' ';
        $ws2 = ($m[4] === '') ? '' : ' ';

        if (!$this->keepComments) {

            // remove HTML comments (and ending "//" if present)
            $js = preg_replace('/(?:^\\s*<!--\\s*|\\s*(?:\\/\\/)?\\s*-->\\s*$)/u', '', $js);

            // remove CDATA section markers
            $js = $this->_removeCdata($js);
        }

        if ($this->options['minify_js']) {
            $js = Minify_JS::minify($js);
        }
        else {
            $js = trim($js);
        }

        return $this->_reservePlace(
            $this->_needsCdata($js)
                ? "{$ws1}{$openScript}/*<![CDATA[*/{$js}/*]]>*/</script>{$ws2}"
                : "{$ws1}{$openScript}{$js}</script>{$ws2}"
        );
    }
}