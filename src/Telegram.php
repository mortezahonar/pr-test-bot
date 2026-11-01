<?php

declare(strict_types=1);

final class Telegram implements TelegramSender
{
    private TelegramRateLimit $rateLimit;

    public function __construct(private string $token, ?TelegramRateLimit $rateLimit = null)
    {
        $this->rateLimit = $rateLimit ?? new TelegramRateLimit();
    }

    private function url(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token . '/' . $method;
    }

    /**
     * The type of "result" depends on the method: most return an object, but
     * some (answerCallbackQuery, deleteMessage) return a bare true.
     *
     * @param array<string, mixed> $params
     */
    public function call(string $method, array $params = [], int $timeout = 60): mixed
    {
        $res = Http::form($this->url($method), $params, $timeout);

        if (!is_array($res['json']) || ($res['json']['ok'] ?? false) !== true) {
            throw $this->apiFailure($method, $res);
        }

        return $res['json']['result'];
    }

    /** @param array<string, mixed> $response */
    private function apiFailure(string $method, array $response): TelegramApiException
    {
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $parameters = is_array($json['parameters'] ?? null) ? $json['parameters'] : [];
        $description = (string) ($json['description'] ?? '');

        return new TelegramApiException(
            $method,
            is_int($json['error_code'] ?? null) ? $json['error_code'] : null,
            is_int($parameters['retry_after'] ?? null) ? $parameters['retry_after'] : null,
            preg_match('/parse|entity/i', $description) === 1
        );
    }

    /**
     * Sends text that is parsed as HTML. When Telegram rejects an entity the
     * text is escaped and retried, because an unstyled message beats no message.
     *
     * @param array<string, mixed> $params
     */
    private function callText(string $method, array $params, int $timeout = 60): mixed
    {
        try {
            return $this->call($method, $params, $timeout);
        } catch (TelegramApiException $e) {
            if (!$e->parseError) {
                throw $e;
            }

            foreach (['text', 'caption'] as $field) {
                if (isset($params[$field])) {
                    $params[$field] = htmlspecialchars((string) $params[$field], ENT_QUOTES | ENT_SUBSTITUTE);
                }
            }

            if (isset($params['rich_message'])) {
                $rich = json_decode((string) $params['rich_message'], true);

                if (is_array($rich)) {
                    foreach (['html', 'markdown'] as $key) {
                        if (isset($rich[$key])) {
                            $rich[$key] = htmlspecialchars((string) $rich[$key], ENT_QUOTES | ENT_SUBSTITUTE);
                        }
                    }

                    $params['rich_message'] = self::encode($rich);
                }
            }

            return $this->call($method, $params, $timeout);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rich messages are the only way to get a Persian message laid out from the
     * right. Two details matter: Telegram does not turn a newline into a line
     * break, and the bubble only starts from the right when the message ends
     * with a real <br> tag. The RLM marks are kept: without them a two-line
     * Persian message stays left-aligned on the desktop client.
     */
    private static function richMessage(string $text): string
    {
        $html = str_replace("\n", '<br>', $text);

        return self::encode(['html' => $html . '<br>', 'is_rtl' => true]);
    }

    /**
     * Long polling. Blocks up to $timeout seconds.
     */
    public function getUpdates(int $offset, int $timeout = 50): array
    {
        $res = Http::form($this->url('getUpdates'), [
            'offset'          => $offset,
            'timeout'         => $timeout,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ], $timeout + 20);

        if (!is_array($res['json']) || ($res['json']['ok'] ?? false) !== true) {
            throw $this->apiFailure('getUpdates', $res);
        }

        return $res['json']['result'];
    }

    public function sendMessage(string $chatId, string $text, ?int $threadId = null, ?array $keyboard = null): array
    {
        $params = [
            'chat_id'      => $chatId,
            'rich_message' => self::richMessage($text),
        ];

        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }

        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        return $this->rateLimit->call(fn () => $this->callText('sendRichMessage', $params));
    }

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null, ?int $threadId = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo'   => $photoUrl,
        ];

        if ($caption !== null && $caption !== '') {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }

        return $this->rateLimit->call(fn () => $this->callText('sendPhoto', $params, 120));
    }

    public function editMessageText(string $chatId, int $messageId, string $text, ?array $keyboard = null): array|bool
    {
        $params = [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'rich_message' => self::richMessage($text),
        ];

        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        return $this->callText('editMessageText', $params);
    }

    public function editMessageCaption(string $chatId, int $messageId, string $caption): array|bool
    {
        return $this->callText('editMessageCaption', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    public function deleteMessage(string $chatId, int $messageId): bool
    {
        return (bool) $this->call('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function createForumTopic(string $chatId, string $name): array
    {
        return $this->rateLimit->call(fn () => $this->call('createForumTopic', [
            'chat_id' => $chatId,
            'name'    => $name,
        ]));
    }

    public function editForumTopic(string $chatId, int $threadId, string $name): bool
    {
        return (bool) $this->call('editForumTopic', [
            'chat_id'           => $chatId,
            'message_thread_id' => $threadId,
            'name'              => $name,
        ]);
    }

    public function answerCallbackQuery(string $callbackId, ?string $text = null): bool
    {
        $params = ['callback_query_id' => $callbackId];

        if ($text !== null && $text !== '') {
            $params['text'] = $text;
        }

        return (bool) $this->call('answerCallbackQuery', $params);
    }
}
