<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

if (!function_exists('php_os_family')) {
    function php_os_family()
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return 'Windows';
        }

        $map = [
            'Darwin'    => 'Darwin',
            'DragonFly' => 'BSD',
            'FreeBSD'   => 'BSD',
            'NetBSD'    => 'BSD',
            'OpenBSD'   => 'BSD',
            'Linux'     => 'Linux',
            'SunOS'     => 'Solaris',
        ];

        return isset($map[\PHP_OS]) ? $map[\PHP_OS] : 'Unknown';
    }
}

if (!function_exists('utf8_encode')) {
    function utf8_encode($s)
    {
        $s .= $s;
        $len = \strlen($s);

        for ($i = $len >> 1, $j = 0; $i < $len; ++$i, ++$j) {
            switch (true) {
                case $s[$i] < "\x80":
                    $s[$j] = $s[$i];
                    break;
                case $s[$i] < "\xC0":
                    $s[$j] = "\xC2";
                    $s[++$j] = $s[$i];
                    break;
                default:
                    $s[$j] = "\xC3";
                    $s[++$j] = \chr(\ord($s[$i]) - 64);
                    break;
            }
        }

        return substr($s, 0, $j);
    }
}

if (!function_exists('utf8_decode')) {
    function utf8_decode($s)
    {
        $s = (string)$s;
        $len = \strlen($s);

        for ($i = 0, $j = 0; $i < $len; ++$i, ++$j) {
            switch ($s[$i] & "\xF0") {
                case "\xC0":
                case "\xD0":
                    $c = (\ord($s[$i] & "\x1F") << 6) | \ord($s[++$i] & "\x3F");
                    $s[$j] = $c < 256 ? \chr($c) : '?';
                    break;

                case "\xF0":
                    ++$i;
                // no break

                case "\xE0":
                    $s[$j] = '?';
                    $i += 2;
                    break;

                default:
                    $s[$j] = $s[$i];
            }
        }

        return substr($s, 0, $j);
    }
}

if (!function_exists('stream_isatty')) {
    function stream_isatty($stream)
    {
        if (!\is_resource($stream)) {
            trigger_error('stream_isatty() expects parameter 1 to be resource, ' . \gettype($stream) . ' given', \E_USER_WARNING);

            return false;
        }

        if ('\\' === \DIRECTORY_SEPARATOR) {
            $stat = @fstat($stream);
            // Check if formatted mode is S_IFCHR
            return $stat ? 0020000 === ($stat['mode'] & 0170000) : false;
        }

        return \function_exists('posix_isatty') && @posix_isatty($stream);
    }
}

if (!function_exists('mb_chr')) {
    function mb_chr($code, $encoding = null)
    {
        if (0x80 > $code %= 0x200000) {
            $s = \chr($code);
        }
        elseif (0x800 > $code) {
            $s = \chr(0xC0 | $code >> 6) . \chr(0x80 | $code & 0x3F);
        }
        elseif (0x10000 > $code) {
            $s = \chr(0xE0 | $code >> 12) . \chr(0x80 | $code >> 6 & 0x3F) . \chr(0x80 | $code & 0x3F);
        }
        else {
            $s = \chr(0xF0 | $code >> 18) . \chr(0x80 | $code >> 12 & 0x3F) . \chr(0x80 | $code >> 6 & 0x3F) . \chr(0x80 | $code & 0x3F);
        }

        if ('UTF-8' !== $encoding = $encoding ?? mb_internal_encoding()) {
            $s = mb_convert_encoding($s, $encoding, 'UTF-8');
        }

        return $s;
    }
}

if (!function_exists('mb_ord')) {
    function mb_ord($s, $encoding = null)
    {
        if (null === $encoding) {
            $s = mb_convert_encoding($s, 'UTF-8');
        }
        elseif ('UTF-8' !== $encoding) {
            $s = mb_convert_encoding($s, 'UTF-8', $encoding);
        }

        if (1 === \strlen($s)) {
            return \ord($s);
        }

        $code = ($s = unpack('C*', substr($s, 0, 4))) ? $s[1] : 0;
        if (0xF0 <= $code) {
            return (($code - 0xF0) << 18) + (($s[2] - 0x80) << 12) + (($s[3] - 0x80) << 6) + $s[4] - 0x80;
        }
        if (0xE0 <= $code) {
            return (($code - 0xE0) << 12) + (($s[2] - 0x80) << 6) + $s[3] - 0x80;
        }
        if (0xC0 <= $code) {
            return (($code - 0xC0) << 6) + $s[2] - 0x80;
        }

        return $code;
    }
}

