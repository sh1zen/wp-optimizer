<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

class Minify_HTML extends Minify
{
    private bool $isXhtml;
    protected bool $keepComments;
    protected bool $multiProcess;

    private string $_replacementHash;
    private array $_placeholders = [];
    private int $_placeholderCount = 0;

    // Pre-compiled regex patterns (compiled once, reused)
    private const PATTERN_SCRIPT = '~(\s*)(<script\b[^>]*>)([\s\S]*?)</script>(\s*)~iu';
    private const PATTERN_STYLE = '~\s*(<style\b[^>]*>)([\s\S]*?)</style>\s*~iu';
    private const PATTERN_COMMENT = '~<!--([\s\S]*?)-->~u';
    private const PATTERN_PRE = '~\s*(<pre\b[^>]*>[\s\S]*?</pre>)\s*~iu';
    private const PATTERN_TEXTAREA = '~\s*(<textarea\b[^>]*>[\s\S]*?</textarea>)\s*~iu';
    private const PATTERN_DATA_URI = '~(=(["\'])data:.*\2)~Ui';
    private const PATTERN_TRIM_LINES = '~^\s+|\s+$~mu';
    private const PATTERN_OUTSIDE_TAG = '~>([^<]+)<~';
    private const PATTERN_OPEN_TAG = '~(<[a-z-]+)\s+([^>]+>)~iu';
    private const PATTERN_CDATA_CHECK = '~[<&]|--|]]>~';
    private const PATTERN_HTML_COMMENT_CSS = '~^\s*<!--|-->\s*$~';
    private const PATTERN_HTML_COMMENT_JS = '~^\s*<!--\s*|\s*(?://)?\s*-->\s*$~u';

    // Block elements pattern (built once)
    private const BLOCK_ELEMENTS = 'area|article|aside|basefont|base|blockquote|body|canvas|caption|center|colgroup|col|dd|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frameset|frame|h[1-6]|head|header|hgroup|hr|html|legend|li|link|main|map|menu|meta|nav|ol|optgroup|option|output|p|param|section|table|tbody|thead|td|th|tr|tfoot|title|ul|video|block|svg';
    private ?string $blockElementsPattern = null;

    public function __construct(string $html, array $options = [])
    {
        $options += [
            'minify_css'    => false,
            'minify_js'     => false,
            'comments'      => false,
            'multi_process' => false
        ];

        parent::__construct($html, $options);

        $this->isXhtml = strpos($this->content, '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML') !== false;
        $this->keepComments = $options['comments'];
        $this->multiProcess = $options['multi_process'];
        $this->_replacementHash = '%MINIFYHTML' . hash('xxh3', $this->content) . '_';
    }

    public function run(): void
    {
        $this->_placeholders = [];
        $this->_placeholderCount = 0;

        // Noptimize
        $this->content = $this->hide_noptimize($this->content);

        $this->process();

        // Restore noptimize
        $this->content = $this->restore_noptimize($this->content);

        // Fixes for Revslider data attribs
        if (apply_filters('wpopt_minify_filter_dataattrib', false)) {
            $this->content = preg_replace(
                ['~\n(data-.*$)\n~Um', '~<[^>]*(="[^"\'<>\s]*)(\w)~'],
                [' $1 ', '$1 $2'],
                $this->content
            );
        }
    }

    protected function process(): void
    {
        $content = $this->content;

        // Replace SCRIPTs with placeholders
        $content = preg_replace_callback(self::PATTERN_SCRIPT, [$this, '_removeScriptCB'], $content);

        // Replace STYLEs with placeholders
        $content = preg_replace_callback(self::PATTERN_STYLE, [$this, '_removeStyleCB'], $content);

        // Remove HTML comments (not containing IE conditional comments)
        if (!$this->keepComments) {
            $content = preg_replace_callback(self::PATTERN_COMMENT, [$this, '_commentCB'], $content);
        }

        // Replace PREs and TEXTAREAs with placeholders
        $content = preg_replace_callback(self::PATTERN_PRE, [$this, '_removeCB'], $content);
        $content = preg_replace_callback(self::PATTERN_TEXTAREA, [$this, '_removeCB'], $content);

        // Replace data: URIs with placeholders
        $content = preg_replace_callback(self::PATTERN_DATA_URI, [$this, '_removeDataURICB'], $content);

        // Trim each line
        $content = preg_replace(self::PATTERN_TRIM_LINES, ' ', $content);

        // Remove ws around block elements
        $content = preg_replace($this->getBlockElementsPattern(), '$1', $content);

        // Remove ws outside all elements
        $content = preg_replace_callback(self::PATTERN_OUTSIDE_TAG, [$this, '_outsideTagCB'], $content);

        // Normalize whitespace in open tags
        $content = preg_replace(self::PATTERN_OPEN_TAG, '$1 $2', $content);

        // Restore placeholders (reverse order)
        if ($this->_placeholders) {
            $keys = array_keys($this->_placeholders);
            $values = array_values($this->_placeholders);

            // Reverse for correct nesting order
            $keys = array_reverse($keys);
            $values = array_reverse($values);

            $content = str_replace($keys, $values, $content);

            if ($this->multiProcess) {
                $content = str_replace($keys, $values, $content);
            }
        }

        $this->content = $content;
    }

    private function getBlockElementsPattern(): string
    {
        return $this->blockElementsPattern ??= '~\s+(</?(?:' . self::BLOCK_ELEMENTS . ')\b[^>]*>)~iu';
    }

    protected function _commentCB(array $m): string
    {
        // Preserve IE conditional comments
        $comment = $m[1];
        return ($comment[0] === '[' || strpos($comment, '<![') !== false) ? $m[0] : '';
    }

    protected function _outsideTagCB(array $m): string
    {
        return '>' . trim($m[1], " \t\n\r\0\x0B") . '<';
    }

    protected function _reservePlace(string $content): string
    {
        $placeholder = $this->_replacementHash . $this->_placeholderCount++ . '%';
        $this->_placeholders[$placeholder] = $content;
        return $placeholder;
    }

    protected function _removeCB(array $m): string
    {
        return $this->_reservePlace($m[1]);
    }

    protected function _removeDataURICB(array $m): string
    {
        return $this->_reservePlace($m[1]);
    }

    protected function _removeStyleCB(array $m): string
    {
        $openStyle = $m[1];
        $css = $m[2];

        // Remove HTML comments
        $css = preg_replace(self::PATTERN_HTML_COMMENT_CSS, '', $css);

        // Remove CDATA section markers
        if (strpos($css, '<![CDATA[') !== false) {
            $css = str_replace(['<![CDATA[', ']]>'], '', $css);
        }

        $css = $this->options['minify_css'] ? Minify_CSS::minify($css) : trim($css);

        if ($this->isXhtml && preg_match(self::PATTERN_CDATA_CHECK, $css)) {
            return $this->_reservePlace("{$openStyle}/*<![CDATA[*/{$css}/*]]>*/</style>");
        }

        return $this->_reservePlace("{$openStyle}{$css}</style>");
    }

    protected function _removeScriptCB(array $m): string
    {
        $ws1 = $m[1] === '' ? '' : ' ';
        $ws2 = $m[4] === '' ? '' : ' ';
        $openScript = $m[2];
        $js = $m[3];

        if (!$this->keepComments) {
            // Remove HTML comments
            $js = preg_replace(self::PATTERN_HTML_COMMENT_JS, '', $js);

            // Remove CDATA section markers
            if (strpos($js, '<![CDATA[') !== false) {
                $js = str_replace(['<![CDATA[', ']]>'], '', $js);
            }
        }

        $js = $this->options['minify_js'] ? Minify_JS::minify($js) : trim($js);

        if ($this->isXhtml && preg_match(self::PATTERN_CDATA_CHECK, $js)) {
            return $this->_reservePlace("{$ws1}{$openScript}/*<![CDATA[*/{$js}/*]]>*/</script>{$ws2}");
        }

        return $this->_reservePlace("{$ws1}{$openScript}{$js}</script>{$ws2}");
    }
}