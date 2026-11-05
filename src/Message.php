<?php

/**
 * -------------------------------------------------------------------------
 * Threema plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

namespace GlpiPlugin\Threema;

/**
 * Builds the text sent through Threema from a GLPI notification
 * (subject in bold + plain text body), cut to a maximum number of UTF-8 bytes.
 */
class Message
{
    public static function build(string $subject, string $body, int $max_bytes): string
    {
        // Threema uses *bold*: asterisks inside the subject would break the formatting
        $subject = trim(str_replace('*', '', preg_replace('/\s+/', ' ', $subject)));
        $body    = trim(preg_replace("/\r\n?/", "\n", $body));
        $body    = preg_replace("/\n{3,}/", "\n\n", $body);

        $text = $subject !== '' ? '*' . $subject . '*' : '';
        if ($body !== '') {
            $text .= ($text !== '' ? "\n\n" : '') . $body;
        }
        return self::truncate($text, $max_bytes);
    }

    public static function truncate(string $text, int $max_bytes): string
    {
        if (strlen($text) <= $max_bytes) {
            return $text;
        }
        $ellipsis = "\u{2026}"; // 3 bytes in UTF-8
        // mb_strcut never cuts in the middle of a multibyte character
        return rtrim(mb_strcut($text, 0, $max_bytes - strlen($ellipsis), 'UTF-8')) . $ellipsis;
    }
}
