<?php

declare(strict_types=1);

final class Store
{
    private string $topicsDir;
    private string $pendingDir;
    private string $awaitingDir;

    public function __construct(private string $dir)
    {
        $this->topicsDir = $dir . '/topics';
        $this->pendingDir = $dir . '/pending';
        $this->awaitingDir = $dir . '/awaiting';

        foreach ([$dir, $this->topicsDir, $this->pendingDir, $this->awaitingDir] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }

    public function topicPath(int $pr): string
    {
        return $this->topicsDir . '/' . $pr . '.json';
    }

    public function topicExists(int $pr): bool
    {
        return is_file($this->topicPath($pr));
    }

    /**
     * topics/<PR>.json holds a single key: the PR number.
     * Returns the inner array or null.
     */
    public function loadTopic(int $pr): ?array
    {
        $path = $this->topicPath($pr);

        if (!is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);

        if (!is_array($data) || !isset($data[(string) $pr]) || !is_array($data[(string) $pr])) {
            return null;
        }

        return $data[(string) $pr];
    }

    /**
     * Backup the existing mapping, then write the nested structure.
     */
    public function saveTopic(int $pr, array $topic): void
    {
        $path = $this->topicPath($pr);

        if (is_file($path)) {
            copy($path, $path . '.bak.' . date('Ymd-His'));
        }

        $this->writeJson($path, [(string) $pr => $topic]);
    }

    public function updateLastCommentAt(int $pr, string $iso): void
    {
        $topic = $this->loadTopic($pr);

        if ($topic === null) {
            return;
        }

        $topic['last_comment_at'] = $iso;
        $this->saveTopic($pr, $topic);
    }

    public function getOffset(): int
    {
        $path = $this->dir . '/offset.json';
        $data = is_file($path) ? $this->readJson($path) : null;

        return is_array($data) ? (int) ($data['offset'] ?? 0) : 0;
    }

    public function setOffset(int $offset): void
    {
        $this->writeJson($this->dir . '/offset.json', ['offset' => $offset]);
    }

    public function savePending(string $id, array $job): void
    {
        $this->writeJson($this->pendingDir . '/' . $id . '.json', $job);
    }

    public function loadPending(string $id): ?array
    {
        $path = $this->pendingDir . '/' . $id . '.json';

        if (!preg_match('#^[a-zA-Z0-9_-]+$#', $id) || !is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);

        return is_array($data) ? $data : null;
    }

    public function deletePending(string $id): void
    {
        $path = $this->pendingDir . '/' . $id . '.json';

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Remember that we asked this user a question and are waiting for the answer.
     */
    public function setAwaiting(string $userId, string $command): void
    {
        $this->writeJson(
            $this->awaitingPath($userId),
            ['command' => $command, 'at' => gmdate('c')]
        );
    }

    /**
     * The command we are waiting for a number for, or null.
     */
    public function getAwaiting(string $userId): ?string
    {
        $path = $this->awaitingPath($userId);

        if (!is_file($path)) {
            return null;
        }

        $data = $this->readJson($path);

        if (!is_array($data)) {
            return null;
        }

        // An old question must not swallow today's message.
        $at = strtotime((string) ($data['at'] ?? ''));

        if ($at === false || time() - $at > 1800) {
            $this->clearAwaiting($userId);

            return null;
        }

        $command = (string) ($data['command'] ?? '');

        return $command === '' ? null : $command;
    }

    public function clearAwaiting(string $userId): void
    {
        $path = $this->awaitingPath($userId);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function awaitingPath(string $userId): string
    {
        return $this->awaitingDir . '/' . preg_replace('#[^0-9]#', '', $userId) . '.json';
    }

    private function readJson(string $path): mixed
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        return json_decode($raw, true);
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json . "\n", LOCK_EX);
    }
}
