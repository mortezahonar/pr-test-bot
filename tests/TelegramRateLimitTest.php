<?php

declare(strict_types=1);

require __DIR__ . '/../src/TelegramApiException.php';
require __DIR__ . '/../src/TelegramRateLimit.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$makeLimiter = static function (int $interval, array &$waits, int &$now): TelegramRateLimit {
    return new TelegramRateLimit(
        $interval,
        120,
        static function () use (&$now): int {
            return $now;
        },
        static function (int $microseconds) use (&$waits, &$now): void {
            $waits[] = $microseconds;
            $now += $microseconds;
        }
    );
};

$now = 1_000_000;
$waits = [];
$limiter = $makeLimiter(3_600_000, $waits, $now);
$assert($limiter->call(static fn (): string => 'first') === 'first', 'first operation must run immediately');
$assert($limiter->call(static fn (): string => 'second') === 'second', 'second operation must complete');
$assert($waits === [3_600_000], 'message-producing calls must be spaced by 3.6 seconds');

$now = 0;
$waits = [];
$limiter = $makeLimiter(0, $waits, $now);
$calls = 0;
$result = $limiter->call(static function () use (&$calls): string {
    $calls++;

    if ($calls === 1) {
        throw new TelegramApiException('sendPhoto', 429, 15);
    }

    return 'sent';
});
$assert($result === 'sent' && $calls === 2, 'one Telegram 429 must retry exactly once');
$assert($waits === [15_000_000], '429 retry must honor retry_after');

foreach ([
    ['code' => 400, 'retry_after' => 15, 'expected_calls' => 1],
    ['code' => 429, 'retry_after' => null, 'expected_calls' => 1],
    ['code' => 429, 'retry_after' => 121, 'expected_calls' => 1],
] as $case) {
    $now = 0;
    $waits = [];
    $limiter = $makeLimiter(0, $waits, $now);
    $calls = 0;

    try {
        $limiter->call(static function () use (&$calls, $case): never {
            $calls++;
            throw new TelegramApiException('sendRichMessage', $case['code'], $case['retry_after']);
        });
        $assert(false, 'invalid or non-429 errors must be rethrown');
    } catch (TelegramApiException) {
        $assert($calls === $case['expected_calls'] && $waits === [], 'invalid or non-429 errors must not retry or wait');
    }
}

$now = 0;
$waits = [];
$limiter = $makeLimiter(0, $waits, $now);
$calls = 0;
try {
    $limiter->call(static function () use (&$calls): never {
        $calls++;
        throw new TelegramApiException('sendRichMessage', 429, 2);
    });
    $assert(false, 'a repeated 429 must reach the caller');
} catch (TelegramApiException) {
    $assert($calls === 2 && $waits === [2_000_000], 'a second 429 must not cause an unbounded retry loop');
}

$exception = new TelegramApiException('sendPhoto', 429, 5);
$assert(!str_contains($exception->getMessage(), 'retry_after'), 'exception messages must not contain response payloads');

fwrite(STDOUT, "OK: Telegram rate-limit tests passed.\n");
