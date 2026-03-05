<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules\supporters;

/**
 * JSMin.php - High-performance PHP implementation of Douglas Crockford's JSMin.
 *
 * Compatible with PHP 7.4 - 8.5
 * Optimized for speed with minimal memory allocations.
 *
 * @package JSMin
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class Minify_JS extends Minify
{
    /** @var string|null Current character A */
    protected ?string $a = "\n";

    /** @var string|null Current character B */
    protected ?string $b = '';

    /** @var int Current position in input */
    protected int $idx = 0;

    /** @var int Length of input */
    protected int $len = 0;

    /** @var string|null Lookahead character */
    protected ?string $look = null;

    /** @var string Output buffer */
    protected string $out = '';

    /** @var string Last byte written to output */
    protected string $lastOut = '';

    /** @var string Kept comment buffer */
    protected string $kept = '';

    /** @var array<int, bool> Lookup table for alphanumeric chars */
    protected static array $alphaNum = [];

    /** @var array<string, bool> Lookup table for regexp prefix chars */
    protected static array $regexpPre = [];

    /** @var array<string, bool> Lookup table for newline context chars */
    protected static array $nlContext = [];

    /** @var array<string, bool> Lookup table for space context chars */
    protected static array $spContext = [];

    /**
     * Initialize lookup tables (called once)
     */
    protected static function initLookups(): void
    {
        if (!empty(self::$alphaNum)) {
            return;
        }

        // Alphanumeric: a-z, A-Z, 0-9, _, $, \, and > 126
        for ($i = 0; $i < 256; $i++) {
            self::$alphaNum[$i] = (
                ($i >= 97 && $i <= 122) ||  // a-z
                ($i >= 65 && $i <= 90) ||   // A-Z
                ($i >= 48 && $i <= 57) ||   // 0-9
                $i === 95 || $i === 36 || $i === 92 || // _ $ \
                $i > 126
            );
        }

        // Chars that indicate a regexp follows
        foreach (str_split('(,=:[!&|?+-~*{;') as $c) {
            self::$regexpPre[$c] = true;
        }

        // Chars relevant for newline context
        foreach (str_split('{[(+-!~') as $c) {
            self::$nlContext[$c] = true;
        }

        // Chars relevant for space context
        foreach (str_split('}])+-"\'') as $c) {
            self::$spContext[$c] = true;
        }
    }

    /**
     * Main minification process
     */
    public function run(): void
    {
        self::initLookups();

        // Handle mbstring overload (PHP < 8.0 only)
        $mbEnc = null;
        if (PHP_VERSION_ID < 80000 && function_exists('mb_strlen')) {
            $overload = (int)@ini_get('mbstring.func_overload');
            if ($overload & 2) {
                $mbEnc = mb_internal_encoding();
                mb_internal_encoding('8bit');
            }
        }

        $content = $this->content;

        // Remove UTF-8 BOM
        if (isset($content[0]) && $content[0] === "\xef" && isset($content[1], $content[2])) {
            $content = substr($content, 3);
        }

        // Initialize state
        $this->len = strlen($content);
        $this->content = $content;
        $this->idx = 0;
        $this->out = '';
        $this->a = "\n";
        $this->b = '';
        $this->look = null;
        $this->lastOut = '';
        $this->kept = '';

        // Pre-size output buffer (estimate ~70% of input)
        if ($this->len > 1000) {
            $this->out = str_repeat(' ', (int)($this->len * 0.7));
            $this->out = '';
        }

        $this->actionDeleteAB();

        while ($this->a !== null) {
            $a = $this->a;
            $b = $this->b;

            if ($a === ' ') {
                if (($this->lastOut === '+' || $this->lastOut === '-') && $b === $this->lastOut) {
                    $this->actionKeepA();
                }
                elseif ($b !== null && isset(self::$alphaNum[ord($b)]) && self::$alphaNum[ord($b)]) {
                    $this->actionKeepA();
                }
                else {
                    $this->actionDeleteA();
                }
            }
            elseif ($a === "\n") {
                if ($b === ' ') {
                    $this->actionDeleteAB();
                }
                elseif ($b === null || (!isset(self::$nlContext[$b]) && ($b === null || !isset(self::$alphaNum[ord($b)]) || !self::$alphaNum[ord($b)]))) {
                    $this->actionDeleteA();
                }
                else {
                    $this->actionKeepA();
                }
            }
            elseif ($a === null || !isset(self::$alphaNum[ord($a)]) || !self::$alphaNum[ord($a)]) {
                if ($b === ' ' || ($b === "\n" && !isset(self::$spContext[$a]))) {
                    $this->actionDeleteAB();
                }
                else {
                    $this->actionKeepA();
                }
            }
            else {
                $this->actionKeepA();
            }
        }

        if ($mbEnc !== null) {
            mb_internal_encoding($mbEnc);
        }

        $this->content = trim($this->out);
    }

    /**
     * Output A, copy B to A, get next B
     */
    protected function actionKeepA(): void
    {
        if ($this->a !== null) {
            $this->out .= $this->a;
            $this->lastOut = $this->a;
        }

        if ($this->kept !== '') {
            $this->out = rtrim($this->out, "\n") . $this->kept;
            $this->kept = '';
        }

        $this->a = $this->b;

        if ($this->a === "'" || $this->a === '"' || $this->a === '`') {
            $this->processString();
        }

        $this->b = $this->next();

        if ($this->b === '/' && $this->isRegexp()) {
            $this->processRegexp();
        }
    }

    /**
     * Copy B to A, get next B
     */
    protected function actionDeleteA(): void
    {
        $this->a = $this->b;

        if ($this->a === "'" || $this->a === '"' || $this->a === '`') {
            $this->processString();
        }

        $this->b = $this->next();

        if ($this->b === '/' && $this->isRegexp()) {
            $this->processRegexp();
        }
    }

    /**
     * Get next B (handles "a + ++b" case)
     */
    protected function actionDeleteAB(): void
    {
        // Check for "+ +" or "- -" case
        if ($this->b === ' ' && ($this->a === '+' || $this->a === '-')) {
            if (isset($this->content[$this->idx]) && $this->content[$this->idx] === $this->a) {
                $this->actionKeepA();
                return;
            }
        }

        $this->b = $this->next();

        if ($this->b === '/' && $this->isRegexp()) {
            $this->processRegexp();
        }
    }

    /**
     * Process string or template literal
     */
    protected function processString(): void
    {
        $delim = $this->a;
        $content = &$this->content;
        $idx = &$this->idx;
        $len = $this->len;
        $out = &$this->out;

        while (true) {
            $out .= $this->a;
            $this->lastOut = $this->a;

            // Inline get() for performance
            if ($this->look !== null) {
                $c = $this->look;
                $this->look = null;
            }
            elseif ($idx < $len) {
                $c = $content[$idx++];
                $ord = ord($c);
                if ($ord < 32 && $c !== "\n") {
                    $c = ($c === "\r") ? "\n" : ' ';
                }
            }
            else {
                $c = null;
            }

            $this->a = $c;

            if ($c === $delim) {
                return;
            }

            if ($delim !== '`' && ($c === null || ord($c) <= 10)) {
                throw new \Exception("JSMin: Unterminated string at byte " . ($idx - 1));
            }

            if ($c === '\\') {
                $out .= $c;
                $this->lastOut = $c;

                // Get escaped char
                if ($this->look !== null) {
                    $this->a = $this->look;
                    $this->look = null;
                }
                elseif ($idx < $len) {
                    $c = $content[$idx++];
                    $ord = ord($c);
                    $this->a = ($ord < 32 && $c !== "\n") ? (($c === "\r") ? "\n" : ' ') : $c;
                }
                else {
                    $this->a = null;
                }
            }
        }
    }

    /**
     * Process regexp literal
     */
    protected function processRegexp(): void
    {
        $this->out .= ($this->a ?? '') . '/';
        $content = &$this->content;
        $idx = &$this->idx;
        $len = $this->len;
        $out = &$this->out;

        while (true) {
            // Inline get()
            if ($this->look !== null) {
                $c = $this->look;
                $this->look = null;
            }
            elseif ($idx < $len) {
                $c = $content[$idx++];
                $ord = ord($c);
                if ($ord < 32 && $c !== "\n") {
                    $c = ($c === "\r") ? "\n" : ' ';
                }
            }
            else {
                $c = null;
            }

            $this->a = $c;

            if ($c === '[') {
                // Character class
                while (true) {
                    $out .= $this->a;

                    if ($this->look !== null) {
                        $c = $this->look;
                        $this->look = null;
                    }
                    elseif ($idx < $len) {
                        $c = $content[$idx++];
                        $ord = ord($c);
                        if ($ord < 32 && $c !== "\n") {
                            $c = ($c === "\r") ? "\n" : ' ';
                        }
                    }
                    else {
                        $c = null;
                    }

                    $this->a = $c;

                    if ($c === ']') break;
                    if ($c === null || ord($c) <= 10) {
                        throw new \Exception("JSMin: Unterminated RegExp class at byte $idx");
                    }
                    if ($c === '\\') {
                        $out .= $c;
                        if ($this->look !== null) {
                            $this->a = $this->look;
                            $this->look = null;
                        }
                        elseif ($idx < $len) {
                            $c = $content[$idx++];
                            $ord = ord($c);
                            $this->a = ($ord < 32 && $c !== "\n") ? (($c === "\r") ? "\n" : ' ') : $c;
                        }
                        else {
                            $this->a = null;
                        }
                    }
                }
            }

            if ($c === '/') {
                $this->b = $this->next();
                return;
            }

            if ($c === null || ord($c) <= 10) {
                throw new \Exception("JSMin: Unterminated RegExp at byte $idx");
            }

            if ($c === '\\') {
                $out .= $c;
                if ($this->look !== null) {
                    $c = $this->look;
                    $this->look = null;
                }
                elseif ($idx < $len) {
                    $c = $content[$idx++];
                    $ord = ord($c);
                    if ($ord < 32 && $c !== "\n") {
                        $c = ($c === "\r") ? "\n" : ' ';
                    }
                }
                else {
                    $c = null;
                }
                $this->a = $c;
            }

            $out .= $this->a ?? '';
            $this->lastOut = $this->a ?? '';
        }
    }

    /**
     * Get next character (inline-optimized)
     */
    protected function get(): ?string
    {
        if ($this->look !== null) {
            $c = $this->look;
            $this->look = null;
            return $c;
        }

        if ($this->idx >= $this->len) {
            return null;
        }

        $c = $this->content[$this->idx++];
        $ord = ord($c);

        if ($ord >= 32 || $c === "\n") {
            return $c;
        }

        return ($c === "\r") ? "\n" : ' ';
    }

    /**
     * Get next character, skipping comments
     */
    protected function next(): ?string
    {
        $c = $this->get();

        if ($c !== '/') {
            return $c;
        }

        // Peek next char
        if ($this->idx < $this->len) {
            $peek = $this->content[$this->idx];
        }
        else {
            return $c;
        }

        if ($peek === '/') {
            $this->idx++;
            $this->consumeSingleComment();
            return "\n";
        }

        if ($peek === '*') {
            $this->idx++;
            $this->consumeMultiComment();
            return ' ';
        }

        return $c;
    }

    /**
     * Consume single-line comment
     */
    protected function consumeSingleComment(): void
    {
        $content = &$this->content;
        $idx = &$this->idx;
        $len = $this->len;
        $start = $idx;

        while ($idx < $len) {
            $c = $content[$idx++];
            if ($c === "\n" || $c === "\r") {
                // Check for IE conditional
                $comment = substr($content, $start, $idx - $start - 1);
                if (isset($comment[0]) && $comment[0] === '@' && preg_match('/^@(?:cc_on|if|elif|else|end)\b/', $comment)) {
                    $this->kept .= "//{$comment}\n";
                }
                return;
            }
        }
    }

    /**
     * Consume multi-line comment
     */
    protected function consumeMultiComment(): void
    {
        $content = &$this->content;
        $idx = &$this->idx;
        $len = $this->len;
        $start = $idx;

        while ($idx < $len) {
            $c = $content[$idx++];

            if ($c === '*' && $idx < $len && $content[$idx] === '/') {
                $idx++;
                $comment = substr($content, $start, $idx - $start - 2);

                if (isset($comment[0])) {
                    if ($comment[0] === '!') {
                        $this->kept .= ($this->kept === '' ? "\n" : '') . "/*{$comment}*/\n";
                    }
                    elseif ($comment[0] === '@' && preg_match('/^@(?:cc_on|if|elif|else|end)\b/', $comment)) {
                        $this->kept .= "/*{$comment}*/";
                    }
                }
                return;
            }
        }

        throw new \Exception("JSMin: Unterminated comment at byte $idx");
    }

    /**
     * Check if current position indicates a regexp literal
     */
    protected function isRegexp(): bool
    {
        $a = $this->a;

        if ($a !== null && isset(self::$regexpPre[$a])) {
            return true;
        }

        if ($a === ' ' || $a === "\n") {
            if (strlen($this->out) < 2) {
                return true;
            }
        }

        // Check for keywords
        $out = $this->out;
        $outLen = strlen($out);

        // Quick check: must end with a keyword char
        if ($outLen === 0) {
            return false;
        }

        $lastChar = $out[$outLen - 1];

        // Keywords end with: e(case,else,typeof), n(in,return)
        if ($lastChar !== 'e' && $lastChar !== 'n') {
            return false;
        }

        // Check each keyword
        $keywords = ['case' => 4, 'else' => 4, 'in' => 2, 'return' => 6, 'typeof' => 6];

        foreach ($keywords as $kw => $kwLen) {
            if ($outLen >= $kwLen) {
                $pos = $outLen - $kwLen;
                if (substr($out, $pos, $kwLen) === $kw) {
                    // Check char before keyword
                    if ($pos === 0) {
                        if ($a === ' ' || $a === "\n") $this->a = '';
                        return true;
                    }
                    $before = $out[$pos - 1];
                    if (!isset(self::$alphaNum[ord($before)]) || !self::$alphaNum[ord($before)]) {
                        if ($a === ' ' || $a === "\n") $this->a = '';
                        return true;
                    }
                }
            }
        }

        return false;
    }
}