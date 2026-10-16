<?php

declare(strict_types=1);

/** UTF-8 helpers with safe fallbacks for hosts where mbstring is disabled. */
function akh_invoice_text_substr(string $text, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, $start, $length, 'UTF-8');
    }
    if (function_exists('iconv_substr')) {
        $result = iconv_substr($text, $start, $length, 'UTF-8');
        if ($result !== false) {
            return $result;
        }
    }

    return substr($text, $start, $length);
}

function akh_invoice_text_strlen(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }
    if (function_exists('iconv_strlen')) {
        $length = iconv_strlen($text, 'UTF-8');
        if ($length !== false) {
            return $length;
        }
    }

    return strlen($text);
}

function akh_invoice_text_strrpos(string $text, string $needle): int|false
{
    if (function_exists('mb_strrpos')) {
        return mb_strrpos($text, $needle, 0, 'UTF-8');
    }
    if (function_exists('iconv_strrpos')) {
        $position = iconv_strrpos($text, $needle, 'UTF-8');
        if ($position !== false) {
            return $position;
        }
    }

    return strrpos($text, $needle);
}
