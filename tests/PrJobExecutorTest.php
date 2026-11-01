<?php

declare(strict_types=1);

require __DIR__ . '/../src/TelegramApiException.php';
require __DIR__ . '/../src/TelegramRateLimit.php';
require __DIR__ . '/../src/TelegramSender.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/PrJobExecutor.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$tempDir = sys_get_temp_dir() . '/pr-bot-job-test-' . bin2hex(random_bytes(6));
$store = new Store($tempDir);

$fakeTelegram = new class implements TelegramSender {
    public array $calls = [];
    public bool $failPhotoOnce = true;

    public function createForumTopic(string $chatId, string $name): array
    {
        $this->calls[] = ['topic', $name];
        return ['message_thread_id' => 987];
    }

    public function sendMessage(string $chatId, string $text, ?int $threadId = null, ?array $keyboard = null): array
    {
        $this->calls[] = ['message', $text, $threadId];
        return ['message_id' => count($this->calls)];
    }

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null, ?int $threadId = null): array
    {
        $this->calls[] = ['photo', $photoUrl, $caption, $threadId];

        if ($this->failPhotoOnce) {
            $this->failPhotoOnce = false;
            throw new TelegramApiException('sendPhoto', 429, 1);
        }

        return ['message_id' => count($this->calls)];
    }
};

$job = [
    'pr' => 123,
    'is_update' => false,
    'topic_name' => 'Test topic',
    'thread_id' => null,
    'actions' => [
        ['kind' => 'topic', 'name' => 'Test topic'],
        ['kind' => 'message', 'text' => 'first'],
        ['kind' => 'photo', 'url' => 'https://example.test/image.png', 'caption' => null],
        ['kind' => 'message', 'text' => 'last'],
    ],
    'last_item_at' => '2026-01-01T00:00:00Z',
];
$store->savePending('job123', $job);
$executor = new PrJobExecutor($fakeTelegram, $store, '-100123');

try {
    $executor->execute('job123', $store->loadPending('job123'));
    $assert(false, 'the first photo attempt should fail in this test');
} catch (TelegramApiException) {
    $pending = $store->loadPending('job123');
    $assert(is_array($pending), 'failed publication must retain the pending job');
    $assert(($pending['next_action'] ?? null) === 2, 'progress must stop at the first unsent action');
    $assert(($pending['thread_id'] ?? null) === 987, 'created topic id must be checkpointed');
    $assert(($pending['sent'] ?? null) === 1, 'successful message count must be checkpointed');
}

$pending = $store->loadPending('job123');
$result = $executor->execute('job123', $pending);
$assert($result['text'] === 'انجام شد. پیام: 2 — عکس: 1', 'completion summary must include all successful actions');
$assert(count(array_filter($fakeTelegram->calls, static fn (array $call): bool => $call[0] === 'topic')) === 1, 'resumption must not create a duplicate topic');
$assert(count(array_filter($fakeTelegram->calls, static fn (array $call): bool => $call[0] === 'message')) === 2, 'each text action must be sent once');
$assert(count(array_filter($fakeTelegram->calls, static fn (array $call): bool => $call[0] === 'photo')) === 2, 'the failed photo is retried and then sent once');
$assert(($store->loadPending('job123')['state_saved'] ?? false) === true, 'topic state completion must be checkpointed');
$assert(($store->loadTopic(123)['thread_id'] ?? null) === 987, 'topic mapping must be saved after all actions complete');

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;

        if (is_dir($child)) {
            $removeTree($child);
        } else {
            unlink($child);
        }
    }

    rmdir($path);
};
$removeTree($tempDir);

fwrite(STDOUT, "OK: PR job resume tests passed.\n");
