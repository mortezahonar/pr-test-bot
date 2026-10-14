<?php

declare(strict_types=1);

/**
 * A tiny log sink shared by the text generators.
 *
 * The generators are built before the real logger exists, so they write
 * through this static sink instead of holding a reference.
 */
final class Log
{
    /** @var (callable(string): void)|null */
    public static $sink = null;

    public static function write(string $message): void
    {
        if (self::$sink !== null) {
            (self::$sink)($message);
        }
    }
}
