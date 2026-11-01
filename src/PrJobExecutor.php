<?php

declare(strict_types=1);

/** Executes a pending PR plan while checkpointing each completed Telegram action. */
final class PrJobExecutor
{
    /** @return array{messages:int, photos:int} */
    public static function previewCounts(array $actions): array
    {
        $counts = ['messages' => 0, 'photos' => 0];

        foreach ($actions as $action) {
            $kind = $action['kind'] ?? '';

            if ($kind === 'message') {
                $counts['messages']++;
            } elseif ($kind === 'photo') {
                $counts['photos']++;
            }
        }

        return $counts;
    }

    public function __construct(
        private readonly TelegramSender $telegram,
        private readonly Store $store,
        private readonly string $chatId
    ) {
    }

    /** @return array{thread_id:?int, text:string} */
    public function execute(string $jobId, array $job): array
    {
        if (!is_array($job['actions'] ?? null)) {
            throw new RuntimeException('Pending PR job has no action list.');
        }

        $actions = $job['actions'];
        $actionCount = count($actions);
        $nextAction = max(0, min($actionCount, (int) ($job['next_action'] ?? 0)));
        $threadId = isset($job['thread_id']) && $job['thread_id'] !== null
            ? (int) $job['thread_id']
            : null;
        $sent = max(0, (int) ($job['sent'] ?? 0));
        $photos = max(0, (int) ($job['photos'] ?? 0));

        for ($index = $nextAction; $index < $actionCount; $index++) {
            $action = $actions[$index];

            switch ($action['kind'] ?? '') {
                case 'topic':
                    $result = $this->telegram->createForumTopic($this->chatId, (string) ($action['name'] ?? ''));
                    $threadId = (int) ($result['message_thread_id'] ?? 0);

                    if ($threadId < 1) {
                        throw new RuntimeException('Telegram did not return a forum topic id.');
                    }
                    break;

                case 'message':
                    $this->telegram->sendMessage(
                        $this->chatId,
                        (string) ($action['text'] ?? ''),
                        $threadId
                    );
                    $sent++;
                    break;

                case 'photo':
                    $this->telegram->sendPhoto(
                        $this->chatId,
                        (string) ($action['url'] ?? ''),
                        isset($action['caption']) && $action['caption'] !== null
                            ? (string) $action['caption']
                            : null,
                        $threadId
                    );
                    $photos++;
                    break;

                default:
                    throw new RuntimeException('Pending PR job contains an unsupported action.');
            }

            $job['thread_id'] = $threadId;
            $job['next_action'] = $index + 1;
            $job['sent'] = $sent;
            $job['photos'] = $photos;
            $this->store->savePending($jobId, $job);
        }

        if (empty($job['state_saved'])) {
            if (!empty($job['is_update'])) {
                if (($job['last_item_at'] ?? null) !== null) {
                    $this->store->updateLastCommentAt((int) $job['pr'], (string) $job['last_item_at']);
                }
            } else {
                $this->store->saveTopic((int) $job['pr'], [
                    'thread_id'       => $threadId,
                    'topic'           => (string) ($job['topic_name'] ?? ''),
                    'tracker'         => 'https://issues.joomla.org/tracker/joomla-cms/' . (int) $job['pr'],
                    'github'          => 'https://github.com/joomla/joomla-cms/pull/' . (int) $job['pr'],
                    'last_comment_at' => (string) ($job['last_item_at'] ?? ''),
                    'created_at'      => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }

            $job['state_saved'] = true;
            $job['thread_id'] = $threadId;
            $job['next_action'] = $actionCount;
            $job['sent'] = $sent;
            $job['photos'] = $photos;
            $this->store->savePending($jobId, $job);
        }

        return [
            'thread_id' => $threadId,
            'text'      => 'انجام شد. پیام: ' . $sent . ' — عکس: ' . $photos,
        ];
    }
}
