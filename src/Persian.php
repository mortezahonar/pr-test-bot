<?php

declare(strict_types=1);

final class Persian
{
    public const RLM = "\u{200F}";
    public const ZWNJ = "\u{200C}";
    public const NBSP = "\u{00A0}";
    public const SEPARATOR_DASH = "\u{2015}";

    private const MONTHS = [
        1  => 'فروردین',
        2  => 'اردیبهشت',
        3  => 'خرداد',
        4  => 'تیر',
        5  => 'مرداد',
        6  => 'شهریور',
        7  => 'مهر',
        8  => 'آبان',
        9  => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    /**
     * Convert an ISO-8601 UTC timestamp to Jalali text with Iran time.
     * Example: 2026-10-10T14:09:42Z -> "۱۸ مهر ۱۴۰۵ — ساعت ۱۷:۳۹"
     */
    public static function date(string $iso): string
    {
        $iso = trim($iso);

        if ($iso === '') {
            return '';
        }

        $time = strtotime($iso);

        if ($time === false) {
            return $iso;
        }

        $time += 3 * 3600 + 30 * 60; // UTC+3:30

        [$jy, $jm, $jd] = self::jalaliFromTimestamp($time);
        $hour = (int) gmdate('H', $time);
        $minute = (int) gmdate('i', $time);

        return self::faDigits(
            $jd . ' ' . self::MONTHS[$jm] . ' ' . $jy
            . ' — ساعت ' . sprintf('%02d:%02d', $hour, $minute)
        );
    }

    public static function jalaliFromTimestamp(int $ts): array
    {
        $gy = (int) gmdate('Y', $ts);
        $gm = (int) gmdate('n', $ts);
        $gd = (int) gmdate('j', $ts);

        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gy - 1600;
        $gm2 = $gm - 1;
        $gd2 = $gd - 1;

        $day = 365 * $gy2 + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400);
        $day += $gDaysInMonth[$gm2] + $gd2;

        if ($gm > 2 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
            $day++;
        }

        $day -= 79;

        $cycles = intdiv($day, 12053);
        $day %= 12053;

        $jy = 979 + 33 * $cycles + 4 * intdiv($day, 1461);
        $day %= 1461;

        if ($day >= 366) {
            $jy += intdiv($day - 1, 365);
            $day = ($day - 1) % 365;
        }

        $jmDays = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        $jm = 1;

        while ($jm <= 12 && $day >= $jmDays[$jm - 1]) {
            $day -= $jmDays[$jm - 1];
            $jm++;
        }

        return [$jy, $jm, $day + 1];
    }

    /**
     * Prefix every line with RLM so the bubble stays right-to-left.
     * Lines that are a bare URL are left alone (they must stay LTR).
     */
    public static function rtl(string $text): string
    {
        $lines = explode("\n", $text);
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // An empty line, a line that already carries the mark, and a line
            // whose first strong character is Latin (a URL, a command, a hash)
            // all read left-to-right on their own. Adding the mark there is
            // pointless, and in front of a command it can stop Telegram from
            // offering the command for a tap. Lines that open with an HTML tag
            // are left alone too: inside a rich message a mark in front of a
            // link drags the link to the right, where it no longer reads.
            if (
                $trimmed === ''
                || str_starts_with($line, self::RLM)
                || preg_match('#^[A-Za-z0-9/<]#', $trimmed) === 1
            ) {
                $out[] = $line;
                continue;
            }

            $out[] = self::RLM . $line;
        }

        return implode("\n", $out);
    }

    /**
     * The standard separator that replaces <hr /> (17 long dashes).
     */
    public static function separator(): string
    {
        return self::RLM . str_repeat(self::SEPARATOR_DASH, 17);
    }

    /**
     * Convert Latin digits to Persian (Jalali dates and times use Persian digits).
     */
    public static function faDigits(string $text): string
    {
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        return str_replace($latin, $fa, $text);
    }

    /**
     * Convert Persian/Arabic digits to Latin (used for API-safe comparisons).
     */
    public static function latinDigits(string $text): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($fa, $latin, str_replace($ar, $latin, $text));
    }
}
