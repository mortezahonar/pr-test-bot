<?php

declare(strict_types=1);

require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/Persian.php';
require __DIR__ . '/src/Telegram.php';
require __DIR__ . '/src/GitHub.php';
require __DIR__ . '/src/Gemini.php';
require __DIR__ . '/src/Translator.php';
require __DIR__ . '/src/Store.php';
require __DIR__ . '/src/PrService.php';

$config = require __DIR__ . '/config.php';

Http::$proxy = (string) $config['proxy'];

$stateDir = (string) $config['state_dir'];

foreach (['bot_token', 'chat_id', 'gemini_api_key'] as $required) {
    if ((string) $config[$required] === '' || str_starts_with((string) $config[$required], 'PASTE_')) {
        fwrite(STDERR, "Missing config value: {$required}\n");
        exit(1);
    }
}

$telegram = new Telegram((string) $config['bot_token']);
$github = new GitHub((string) $config['github_token']);
$gemini = new Gemini((string) $config['gemini_api_key'], (string) $config['gemini_model']);
$store = new Store($stateDir);

$systemPrompt = (string) file_get_contents(__DIR__ . '/data/translate_system.txt');
$glossary = '';

if ((string) $config['glossary_path'] !== '' && is_file((string) $config['glossary_path'])) {
    $glossary = "دایره‌المعارف ترجمه جوملا (ترجمه‌های استاندارد — این‌ها را عیناً استفاده کن):\n\n"
        . (string) file_get_contents((string) $config['glossary_path']);
}

$labels = json_decode((string) file_get_contents(__DIR__ . '/data/labels.json'), true) ?: [];

$translator = new Translator($gemini, $systemPrompt, $glossary, (bool) $config['link_commit_hashes']);
$service = new PrService($github, $translator, $store, $labels, $stateDir);

$chatId = (string) $config['chat_id'];
$allowedUsers = array_map('strval', (array) $config['allowed_user_ids']);

$log = static function (string $message): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
};

$isAllowed = static function (int $userId) use ($allowedUsers): bool {
    return $allowedUsers === [] || in_array((string) $userId, $allowedUsers, true);
};

$offset = $store->getOffset();

$argv = $argv ?? [];
$once = in_array('--once', $argv, true);
$pollTimeout = $once ? 0 : 50;

$log('Bot started. mode=' . ($once ? 'once' : 'poll') . ' offset=' . $offset);

while (true) {
    try {
        $updates = $telegram->getUpdates($offset, $pollTimeout);
    } catch (Throwable $e) {
        $log('getUpdates error: ' . $e->getMessage());

        if ($once) {
            exit(1);
        }

        sleep(5);
        continue;
    }

    foreach ($updates as $update) {
        $offset = (int) $update['update_id'] + 1;
        $store->setOffset($offset);

        try {
            handleUpdate($update);
        } catch (Throwable $e) {
            $log('update error: ' . $e->getMessage());
        }
    }

    if ($once) {
        $log('Done. updates=' . count($updates) . ' offset=' . $offset);
        break;
    }
}

function handleUpdate(array $update): void
{
    global $telegram, $service, $store, $chatId, $isAllowed, $log;

    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $userId = (int) ($callback['from']['id'] ?? 0);

        if (!$isAllowed($userId)) {
            return;
        }

        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) $callback['id'];

        if (!preg_match('#^(approve|cancel):([a-zA-Z0-9_-]+)$#', $data, $m)) {
            answerCallback($callbackId, 'دستور نامعتبر');

            return;
        }

        answerCallback($callbackId, 'در حال پردازش...');
        handleJobAction($m[1], $m[2]);

        return;
    }

    if (!isset($update['message']['text'])) {
        return;
    }

    $message = $update['message'];
    $text = trim((string) $message['text']);
    $userId = (int) ($message['from']['id'] ?? 0);

    if (!$isAllowed($userId)) {
        return;
    }

    if (preg_match('#^/(approve|cancel)(?:@\w+)?\s+([a-zA-Z0-9_-]+)\s*$#', $text, $m) === 1) {
        handleJobAction($m[1], $m[2]);

        return;
    }

    if (preg_match('#^/(pr-topic|pr-update)(?:@\w+)?\s+(\d+)\s*$#', $text, $m) !== 1) {
        if (str_starts_with($text, '/start') || str_starts_with($text, '/help')) {
            $telegram->sendMessage($chatId, Persian::rtl(
                "دستورها:\n"
                . "/pr-topic <شماره PR> — ساخت تاپیک جدید\n"
                . "/pr-update <شماره PR> — آپدیت تاپیک موجود\n"
                . "/approve <کد> — تأیید و ارسال پیش‌نویس\n"
                . "/cancel <کد> — لغو پیش‌نویس"
            ));
        }

        return;
    }

    $command = $m[1];
    $pr = (int) $m[2];
    $isUpdate = $command === 'pr-update';

    $status = $telegram->sendMessage($chatId, Persian::rtl('در حال آماده‌سازی #' . $pr . ' ...'));

    try {
        $job = $service->plan($pr, $isUpdate);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $log('plan error: ' . get_class($e) . ': ' . $message . ' @ ' . $e->getFile() . ':' . $e->getLine());

        if ($message === 'NO_TOPIC') {
            $telegram->editMessageText($chatId, $status['message_id'], Persian::rtl(
                'این PR هنوز تاپیکی ندارد؛ اول /pr-topic ' . $pr . ' را اجرا کن.'
            ));
        } else {
            $telegram->editMessageText($chatId, $status['message_id'], Persian::rtl('خطا: ' . $message));
        }

        return;
    }

    $jobId = bin2hex(random_bytes(6));
    $store->savePending($jobId, $job);

    $summary = buildPreview($job);

    $keyboard = [
        'inline_keyboard' => [[
            ['text' => '✅ تأیید و ارسال', 'callback_data' => 'approve:' . $jobId],
            ['text' => 'لغو', 'callback_data' => 'cancel:' . $jobId],
        ]],
    ];

    $telegram->editMessageText($chatId, $status['message_id'], $summary);

    $telegram->sendMessage($chatId, Persian::rtl(
        'تأیید می‌کنی؟' . "\n\n"
        . 'دکمه را بزن یا این دستور را بنویس:' . "\n"
        . '/approve ' . $jobId . "\n"
        . '/cancel ' . $jobId
    ), null, $keyboard);
}

function answerCallback(string $callbackId, string $text): void
{
    global $telegram, $log;

    try {
        $telegram->answerCallbackQuery($callbackId, $text);
    } catch (Throwable $e) {
        // Telegram expires callback queries within seconds, and this bot polls
        // on a schedule. A failed acknowledgement must not abort the action.
        $log('callback ack failed: ' . $e->getMessage());
    }
}

function handleJobAction(string $action, string $jobId): void
{
    global $telegram, $store, $chatId;

    $job = $store->loadPending($jobId);

    if ($job === null) {
        $telegram->sendMessage($chatId, Persian::rtl('این پیش‌نویس منقضی شده است.'));

        return;
    }

    if ($action === 'cancel') {
        $store->deletePending($jobId);
        $telegram->sendMessage($chatId, Persian::rtl('لغو شد.'), $job['thread_id'] ?? null);

        return;
    }

    $store->deletePending($jobId);

    $result = executeJob($job);
    $telegram->sendMessage($chatId, Persian::rtl($result['text']), $result['thread_id']);
}

function buildPreview(array $job): string
{
    $count = 0;
    $photos = 0;

    foreach ($job['actions'] as $action) {
        if ($action['kind'] === 'message' || $action['kind'] === 'topic') {
            $count++;
        }

        if ($action['kind'] === 'photo') {
            $photos++;
        }
    }

    $lines = [
        'پیش‌نمایش #' . $job['pr'] . ($job['is_update'] ? ' (آپدیت)' : ' (تاپیک جدید)'),
        '',
        'عنوان تاپیک: ' . $job['topic_name'],
        'تعداد پیام: ' . $count,
        'تعداد عکس: ' . $photos,
    ];

    if (!empty($job['warnings'])) {
        $lines[] = '';
        $lines[] = implode(' | ', $job['warnings']);
    }

    return Persian::rtl(implode("\n", $lines));
}

function executeJob(array $job): array
{
    global $telegram, $store, $chatId, $log;

    $threadId = isset($job['thread_id']) && $job['thread_id'] !== null ? (int) $job['thread_id'] : null;
    $sent = 0;
    $photos = 0;

    foreach ($job['actions'] as $action) {
        switch ($action['kind']) {
            case 'topic':
                $result = $telegram->createForumTopic($chatId, (string) $action['name']);
                $threadId = (int) $result['message_thread_id'];
                usleep(300000);
                break;

            case 'message':
                $telegram->sendMessage($chatId, (string) $action['text'], $threadId);
                $sent++;
                usleep(400000);
                break;

            case 'photo':
                $telegram->sendPhoto($chatId, (string) $action['url'], $action['caption'] !== null ? (string) $action['caption'] : null, $threadId);
                $photos++;
                usleep(400000);
                break;
        }
    }

    if ($job['is_update']) {
        if ($job['last_item_at'] !== null) {
            $store->updateLastCommentAt((int) $job['pr'], (string) $job['last_item_at']);
        }
    } else {
        $store->saveTopic((int) $job['pr'], [
            'thread_id'      => $threadId,
            'topic'          => (string) $job['topic_name'],
            'tracker'        => 'https://issues.joomla.org/tracker/joomla-cms/' . $job['pr'],
            'github'         => 'https://github.com/joomla/joomla-cms/pull/' . $job['pr'],
            'last_comment_at' => (string) ($job['last_item_at'] ?? ''),
            'created_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    return [
        'thread_id' => $threadId,
        'text'      => 'انجام شد. پیام: ' . $sent . ' — عکس: ' . $photos,
    ];
}
