<?php

declare(strict_types=1);

final class Gemini
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private string $apiKey,
        private string $model = 'gemini-2.5-flash'
    ) {
    }

    /**
     * Generate text from a system instruction and a user prompt.
     */
    public function generate(string $system, string $userText, int $timeout = 180): string
    {
        $url = self::ENDPOINT . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->apiKey);

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

        $res = Http::json('POST', $url, ['Content-Type' => 'application/json'], $payload, $timeout);

        if ($res['status'] >= 400 || !is_array($res['json'])) {
            throw new RuntimeException('Gemini HTTP ' . $res['status'] . ': ' . $res['raw']);
        }

        $parts = $res['json']['candidates'][0]['content']['parts'] ?? null;

        if (!is_array($parts)) {
            $reason = $res['json']['candidates'][0]['finishReason'] ?? 'unknown';
            throw new RuntimeException('Gemini returned no text (finishReason=' . $reason . '): ' . $res['raw']);
        }

        $text = '';

        foreach ($parts as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        return trim($text);
    }
}
