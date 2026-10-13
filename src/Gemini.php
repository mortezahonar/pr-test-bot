<?php

declare(strict_types=1);

final class Gemini
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * HTTP statuses that are worth retrying (transient overload or server side).
     */
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    /**
     * How many times each model is tried before moving on to the next one.
     * Every model in the chain gets this many attempts of its own.
     */
    private const MAX_ATTEMPTS = 5;

    private const RETRY_DELAY = 3;

    /** @var string[] */
    private array $models;

    /**
     * @param string $models Comma separated list of model names, tried in order.
     */
    public function __construct(
        private string $apiKey,
        string $models = 'gemini-3.5-flash'
    ) {
        $list = array_values(array_filter(array_map('trim', explode(',', $models)), static fn (string $m): bool => $m !== ''));

        $this->models = $list !== [] ? $list : ['gemini-3.5-flash'];
    }

    /**
     * Generate text from a system instruction and a user prompt.
     *
     * Falls back to the next configured model when a model is unavailable,
     * and retries transient failures a few times before giving up.
     */
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        $lastError = null;

        foreach ($this->models as $model) {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                try {
                    $res = $this->request($model, $system, $userText, $timeout);
                } catch (Throwable $e) {
                    // A transport failure (timeout, connection reset) carries no
                    // HTTP status to retry on: try the next model right away
                    // instead of waiting on the same one again.
                    $lastError = $e;

                    break;
                }

                $status = (int) $res['status'];

                if ($status < 400 && is_array($res['json'])) {
                    $text = $this->extractText($res['json']);

                    if ($text !== null) {
                        return $text;
                    }

                    $reason    = $res['json']['candidates'][0]['finishReason'] ?? 'unknown';
                    $lastError = new RuntimeException(
                        'Gemini returned no text (finishReason=' . $reason . ', model=' . $model . '): ' . $res['raw']
                    );

                    break;
                }

                $lastError = new RuntimeException(
                    'Gemini HTTP ' . $status . ' (model=' . $model . '): ' . $res['raw']
                );

                if (!in_array($status, self::RETRYABLE_STATUS, true)) {
                    break;
                }

                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }

        throw $lastError ?? new RuntimeException('Gemini: no model configured');
    }

    /**
     * @param array<string, mixed> $json
     */
    private function extractText(array $json): ?string
    {
        $parts = $json['candidates'][0]['content']['parts'] ?? null;

        if (!is_array($parts)) {
            return null;
        }

        $text = '';

        foreach ($parts as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        return trim($text);
    }

    /**
     * @return array{status: int, json: mixed, raw: string}
     */
    private function request(string $model, string $system, string $userText, int $timeout): array
    {
        $url = self::ENDPOINT . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userText]]],
            ],
            'generationConfig' => [
                'temperature'      => 0.0,
                'topP'             => 0.95,
                'maxOutputTokens'  => 8192,
            ],
        ];

        return Http::json('POST', $url, ['Content-Type' => 'application/json'], $payload, $timeout);
    }
}
