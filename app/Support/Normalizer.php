<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalised contact identifiers for duplicate detection and search (decision A-11).
 *
 * email_normalized: trimmed, lower-cased.
 * phone_normalized: E.164 with a leading "+", assuming Saudi Arabia (+966) for
 * local formats: 05XXXXXXXX, 5XXXXXXXX, 9665…, 009665…, +9665…. Any other
 * international number written with "+" or "00" is kept as entered (digits only).
 * Numbers that cannot be interpreted are returned as their digits so an exact
 * match still works; empty input yields null.
 *
 * Every digit is kept: a 30-character input yields at most 31 characters (a
 * local Saudi number grows to 13), which the VARCHAR(32) phone_normalized
 * columns hold.
 */
final class Normalizer
{
    public static function email(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : Str::lower($email);
    }

    public static function phone(?string $phone, string $defaultCountryCode = '966'): ?string
    {
        $raw = trim((string) $phone);

        if ($raw === '') {
            return null;
        }

        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($international) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, $defaultCountryCode) && strlen($digits) === strlen($defaultCountryCode) + 9) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+'.$defaultCountryCode.substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '5')) {
            return '+'.$defaultCountryCode.$digits;
        }

        return '+'.$digits;
    }
}
