<?php

declare(strict_types=1);

/** Formats Telegram text while preserving standalone code-block messages. */
final class TelegramMessageFormatter
{
    public const LRM = "\u{200E}";

    public static function prefixLrm(string $text): string
    {
        if (self::isCodeBlockMessage($text)) {
            return $text;
        }

        if (
            str_starts_with($text, self::LRM)
            || preg_match('/^(?:&#x200e;|&#8206;|&lrm;)/i', $text) === 1
        ) {
            return $text;
        }

        return self::LRM . $text;
    }

    public static function richMessage(string $text): string
    {
        $html = str_replace("\n", '<br>', self::prefixLrm($text));
        $encoded = json_encode(
            ['html' => $html . '<br>', 'is_rtl' => true],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $encoded === false ? '' : $encoded;
    }

    private static function isCodeBlockMessage(string $text): bool
    {
        // Persian::rtl may put an RLM at the start of each line. Remove such
        // marks only from this probe; the original message remains untouched.
        $probe = preg_replace('/^[\h]*[\x{200E}\x{200F}]+/mu', '', $text) ?? $text;
        $probe = trim($probe);

        if ($probe === '') {
            return false;
        }

        if (str_starts_with($probe, '```') && str_ends_with($probe, '```')) {
            return true;
        }

        return preg_match('/^(?:<pre\b[^>]*>.*<\/pre>|<code\b[^>]*>.*<\/code>)$/is', $probe) === 1;
    }
}
