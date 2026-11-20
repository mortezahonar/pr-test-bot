<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/TelegramApiException.php';
require __DIR__ . '/../src/TelegramRateLimit.php';
require __DIR__ . '/../src/TelegramSender.php';
require __DIR__ . '/../src/TelegramMessageFormatter.php';
require __DIR__ . '/../src/Telegram.php';
require __DIR__ . '/../src/Store.php';

$config = require __DIR__ . '/../config.php';
Http::$proxy = (string) $config['proxy'];

$token = (string) $config['bot_token'];
$chatId = (string) $config['chat_id'];

if ($token === '' || $chatId === '') {
    fwrite(STDERR, "Missing Telegram runtime configuration.\n");
    exit(1);
}

$telegram = new Telegram($token, new TelegramRateLimit());
$iconId = $telegram->laboratoryIconId();
$topicDir = (string) $config['state_dir'] . '/topics';
$updated = 0;
$skipped = 0;

$files = glob($topicDir . '/*.json') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $record = json_decode((string) file_get_contents($file), true);

    if (!is_array($record)) {
        $skipped++;
        continue;
    }

    foreach ($record as $topic) {
        $threadId = is_array($topic) ? (int) ($topic['thread_id'] ?? 0) : 0;

        if ($threadId < 1) {
            $skipped++;
            continue;
        }

        $telegram->editForumTopicIcon($chatId, $threadId, $iconId);
        $updated++;
    }
}

fwrite(STDOUT, 'Updated forum topic icons: ' . $updated . '; skipped: ' . $skipped . "\n");
