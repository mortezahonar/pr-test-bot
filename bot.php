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

$fileIndex = array_search('--update-file', $argv, true);

if ($fileIndex !== false && isset($argv[$fileIndex + 1])) {
    processUpdateFile((string) $argv[$fileIndex + 1], $offset);
    exit(0);
}

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

/**
 * Process a single update that the worker stored, instead of polling Telegram.
 */
function processUpdateFile(string $file, int $offset): void
{
    global $log;

    $raw = is_file($file) ? (string) file_get_contents($file) : '';

    $log('Bot started. mode=update-file offset=' . $offset);

    if (trim($raw) === '') {
        $log('update-file: missing or empty file ' . $file);

        return;
    }

    $update = json_decode($raw, true);

    if (!is_array($update)) {
        $log('update-file: invalid JSON in ' . $file);

        return;
    }

    try {
        handleUpdate($update);
    } catch (Throwable $e) {
        $log('update error: ' . $e->getMessage());
    }
}

function handleUpdate(array $update): void
{
    global $telegram, $service, $store, $chatId, $isAllowed, $log;

    $updateId = (int) ($update['update_id'] ?? 0);
    $kind = 'other';

    if (isset($update['callback_query'])) {
        $kind = 'callback';
    } elseif (isset($update['message']['text'])) {
        $kind = 'text';
    } elseif (isset($update['message'])) {
        $kind = 'message-no-text';
    }

    $log(
        'update #' . $updateId . ' kind=' . $kind
        . ' user=' . (string) ($update['message']['from']['username'] ?? $update['callback_query']['from']['username'] ?? '?')
        . ' text=' . json_encode($update['message']['text'] ?? '-', JSON_UNESCAPED_UNICODE)
    );

    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $userId = (int) ($callback['from']['id'] ?? 0);

        if (!$isAllowed($userId)) {
            $log('callback from user ' . $userId . ' not allowed');

            return;
        }

        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) $callback['id'];
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $log('callback data: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        if ($data === 'ask') {
            answerCallback($callbackId, 'شمارهٔ PR را بفرست');
            $store->setAwaiting((string) $userId, 'auto');
            $log('menu: asked for a PR number');
            $telegram->sendMessage($chatId, Persian::rtl('شمارهٔ PR چند است؟ فقط عدد را بفرست.'));

            return;
        }

        if ($data === 'no') {
            answerCallback($callbackId, 'باشد');

            if ($messageId > 0) {
                $telegram->editMessageText($chatId, $messageId, Persian::rtl('باشد. کاری انجام نشد.'), ['inline_keyboard' => []]);
            }

            return;
        }

        if (preg_match('#^(upd|new):(\d{1,7})$#', $data, $m) === 1) {
            answerCallback($callbackId, 'در حال پردازش...');
            $log('menu choice: ' . $m[1] . ' pr=' . $m[2]);

            if ($messageId > 0) {
                $telegram->editMessageText($chatId, $messageId, Persian::rtl('شمارهٔ PR: ' . $m[2]), ['inline_keyboard' => []]);
            }

            runPlan((int) $m[2], $m[1] === 'upd');

            return;
        }

        if (!preg_match('#^(approve|cancel):([a-zA-Z0-9_-]+)$#', $data, $m)) {
            answerCallback($callbackId, 'دستور نامعتبر');

            return;
        }

        answerCallback($callbackId, 'در حال پردازش...');
        $log('callback action: ' . $m[1] . ' job=' . $m[2]);
        handleJobAction($m[1], $m[2]);

        return;
    }

    if (!isset($update['message']['text'])) {
        $log('no message text');

        return;
    }

    $message = $update['message'];
    $text = trim((string) $message['text']);
    $userId = (int) ($message['from']['id'] ?? 0);

    if (!$isAllowed($userId)) {
        $log('message from user ' . $userId . ' not allowed');

        return;
    }

    // People type these commands by hand: tolerate a missing leading slash and
    // stray trailing characters.
    $command = rtrim($text, "/ \t");

    $awaiting = $store->getAwaiting((string) $userId);
    $pr = 0;
    $isUpdate = false;

    if ($awaiting !== null && preg_match('~^#?(\d{1,7})$~', $command, $n) === 1) {
        // We asked for a PR number and this is the answer.
        $store->clearAwaiting((string) $userId);
        $pr = (int) $n[1];
        $log('number received: pr=' . $pr . ' mode=' . $awaiting);
        askAbout($pr, $store->topicExists($pr));

        return;
    } elseif (preg_match('#^/?(approve|cancel)(?:@\w+)?\s+([a-zA-Z0-9_-]+)$#', $command, $m) === 1) {
        $log('action: ' . $m[1] . ' job=' . $m[2]);
        handleJobAction($m[1], $m[2]);

        return;
    } elseif (preg_match('#^/?pr[-_]?(topic|update)(?:@\w+)?\s+(\d+)$#', $command, $m) === 1) {
        $store->clearAwaiting((string) $userId);
        $pr = (int) $m[2];
        $hasTopic = $store->topicExists($pr);
        $isUpdate = $m[1] === 'update';
        $log('explicit command: update=' . ($isUpdate ? 'yes' : 'no') . ' pr=' . $pr . ' topic=' . ($hasTopic ? 'yes' : 'no'));

        // An explicit command that contradicts the current state asks instead of acting.
        if (($isUpdate && !$hasTopic) || (!$isUpdate && $hasTopic)) {
            askAbout($pr, $hasTopic);

            return;
        }
    } elseif (preg_match('#^/?pr[-_]?(topic|update)(?:@\w+)?$#', $command, $m) === 1) {
        // No number given: ask for it instead of repeating a command list.
        $store->setAwaiting((string) $userId, $m[1]);
        $log('asked for a PR number, mode=' . $m[1]);
        $telegram->sendMessage($chatId, Persian::rtl('شمارهٔ PR چند است؟ فقط عدد را بفرست.'));

        return;
    } elseif ($awaiting !== null) {
        $log('awaited a number, got: ' . json_encode($text, JSON_UNESCAPED_UNICODE));
        $telegram->sendMessage($chatId, Persian::rtl('عدد را نگرفتم. شمارهٔ PR را به رقم بفرست.'));

        return;
    } else {
        $log('no branch matched; showing the menu');

        if (str_starts_with($text, '/')) {
            $telegram->sendMessage($chatId, menuText(), null, menuKeyboard());
        }

        return;
    }

    runPlan($pr, $isUpdate);
}

/**
 * Ask whether to update the existing topic or create a missing one, with buttons.
 */
function askAbout(int $pr, bool $hasTopic): void
{
    global $telegram, $chatId, $log;

    $log('asking about pr=' . $pr . ' topic=' . ($hasTopic ? 'yes' : 'no'));

    $telegram->sendMessage(
        $chatId,
        Persian::rtl(
            'برای PR #' . $pr . ($hasTopic
                ? ' از قبل تاپیک ساخته شده است. آیا می‌خواهی آپدیتش کنم؟'
                : ' تاپیکی ساخته نشده است. بسازم؟')
        ),
        null,
        [
            'inline_keyboard' => [[
                [
                    'text'          => $hasTopic ? 'بله، آپدیت کن' : 'بله، بساز',
                    'callback_data' => ($hasTopic ? 'upd:' : 'new:') . $pr,
                ],
                ['text' => 'نه', 'callback_data' => 'no'],
            ]],
        ]
    );
}

/**
 * The entry point: what the user sees before anything else.
 */
function menuText(): string
{
    return Persian::rtl(
        'ربات ترجمهٔ PRهای جوملا' . "\n\n"
        . 'کامنت‌های هر PR را به فارسی ترجمه می‌کند و در تاپیک همان PR می‌گذارد.' . "\n"
        . 'برای شروع، شمارهٔ PR را بفرست.'
    );
}

/**
 * @return array<string, mixed>
 */
function menuKeyboard(): array
{
    return [
        'inline_keyboard' => [[
            ['text' => 'شمارهٔ PR را وارد می‌کنم', 'callback_data' => 'ask'],
        ]],
    ];
}

function runPlan(int $pr, bool $isUpdate): void
{
    global $telegram, $service, $store, $chatId, $log;

    $log('run plan: update=' . ($isUpdate ? 'yes' : 'no') . ' pr=' . $pr);

    $status = $telegram->sendMessage($chatId, Persian::rtl('در حال آماده‌سازی #' . $pr . ' ...'));

    try {
        $job = $service->plan($pr, $isUpdate);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $log('plan error: ' . get_class($e) . ': ' . $message . ' @ ' . $e->getFile() . ':' . $e->getLine());

        if ($message === 'NO_TOPIC') {
            $telegram->editMessageText($chatId, $status['message_id'], Persian::rtl(
                'این PR هنوز تاپیکی ندارد؛ اول تاپیکش را بساز.'
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
        'تأیید می‌کنی؟' . "\n"
        . ($job['is_update'] ? 'آپدیت PR #' . $pr : 'تاپیک PR #' . $pr)
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
