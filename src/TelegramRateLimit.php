<?php

declare(strict_types=1);

/**
 * Paces Telegram message-producing calls below the group limit and retries one
 * explicit, bounded flood-control response. It is intentionally per process;
 * the PR publication loop is serialized by the repository's Actions workflow.
 */
final class TelegramRateLimit
{
    private \Closure $clock;
    private \Closure $wait;
    private ?int $lastAttemptAt = null;

    /**
     * @param callable():int|null $clock Returns monotonic microseconds.
     * @param callable(int):void|null $wait Sleeps for the given microseconds.
     */
    public function __construct(
        private readonly int $minimumIntervalMicroseconds = 3_600_000,
        private readonly int $maximumRetryAfterSeconds = 120,
        ?callable $clock = null,
        ?callable $wait = null
    ) {
        if ($minimumIntervalMicroseconds < 0 || $maximumRetryAfterSeconds < 1) {
            throw new \InvalidArgumentException('Invalid Telegram rate-limit bounds.');
        }

        $this->clock = \Closure::fromCallable(
            $clock ?? static fn (): int => intdiv(hrtime(true), 1_000)
        );
        $this->wait = \Closure::fromCallable(
            $wait ?? static function (int $microseconds): void {
                if ($microseconds <= 0) {
                    return;
                }

                $seconds = intdiv($microseconds, 1_000_000);
                $remainder = $microseconds % 1_000_000;

                if ($seconds > 0) {
                    sleep($seconds);
                }

                if ($remainder > 0) {
                    usleep($remainder);
                }
            }
        );
    }

    /** @param callable():mixed $operation */
    public function call(callable $operation): mixed
    {
        $this->pace();

        try {
            return $operation();
        } catch (TelegramApiException $e) {
            $retryAfter = $e->retryAfter;

            if ($e->apiErrorCode !== 429
                || $retryAfter === null
                || $retryAfter < 1
                || $retryAfter > $this->maximumRetryAfterSeconds
            ) {
                throw $e;
            }

            ($this->wait)($retryAfter * 1_000_000);
            $this->pace();

            // Deliberately retry once only: repeated 429s must not create an
            // unbounded workflow run or duplicate later messages.
            return $operation();
        }
    }

    private function pace(): void
    {
        $now = ($this->clock)();

        if ($this->lastAttemptAt !== null) {
            $remaining = $this->minimumIntervalMicroseconds - ($now - $this->lastAttemptAt);

            if ($remaining > 0) {
                ($this->wait)($remaining);
                $now = ($this->clock)();
            }
        }

        // Count failed attempts too; that is conservative and avoids bursts.
        $this->lastAttemptAt = $now;
    }
}
