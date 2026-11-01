<?php

declare(strict_types=1);

/**
 * A Telegram API failure with only the metadata needed for control flow.
 * Raw response bodies are deliberately excluded from exception messages/logs.
 */
final class TelegramApiException extends RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly ?int $apiErrorCode,
        public readonly ?int $retryAfter = null,
        public readonly bool $parseError = false
    ) {
        $message = 'Telegram API ' . $method . ' failed';

        if ($apiErrorCode !== null) {
            $message .= ' (code ' . $apiErrorCode . ')';
        }

        if ($parseError) {
            $message .= ' (parse error)';
        }

        parent::__construct($message, $apiErrorCode ?? 0);
    }
}
