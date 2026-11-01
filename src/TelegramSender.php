<?php

declare(strict_types=1);

interface TelegramSender
{
    public function sendMessage(string $chatId, string $text, ?int $threadId = null, ?array $keyboard = null): array;

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null, ?int $threadId = null): array;

    public function createForumTopic(string $chatId, string $name): array;
}
