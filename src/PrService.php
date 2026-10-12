<?php

declare(strict_types=1);

final class PrService
{
    private array $nameCache = [];
    private string $nameCachePath;

    public function __construct(
        private GitHub $github,
        private Translator $translator,
        private Store $store,
        private array $labels,
        private string $stateDir
    ) {
        $this->nameCachePath = $stateDir . '/usernames.json';

        if (is_file($this->nameCachePath)) {
            $decoded = json_decode((string) file_get_contents($this->nameCachePath), true);
            $this->nameCache = is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * Build the full action plan for a PR.
     *
     * @return array{pr:int, is_update:bool, topic_name:string, thread_id:?int, actions:array, last_item_at:?string, warnings:array}
     */
    public function plan(int $pr, bool $isUpdate): array
    {
        $pull = $this->github->pull($pr);

        if (!isset($pull['title'], $pull['user']['login'])) {
            throw new RuntimeException('PR #' . $pr . ' not found or API response is incomplete.');
        }

        $threadId = null;
        $lastCommentAt = null;

        if ($isUpdate) {
            $topic = $this->store->loadTopic($pr);

            if ($topic === null) {
                throw new RuntimeException('NO_TOPIC');
            }

            $threadId = (int) ($topic['thread_id'] ?? 0);
            $lastCommentAt = (string) ($topic['last_comment_at'] ?? '');
        }

        $topicName = $this->topicName($pr, (string) $pull['title']);
        $actions = [];
        $warnings = [];
        $newest = null;

        if (!$isUpdate) {
            $actions[] = ['kind' => 'topic', 'name' => $topicName];
            $actions[] = ['kind' => 'message', 'text' => $this->header(
                (string) $pull['user']['login'],
                (string) ($pull['author_association'] ?? 'NONE'),
                (string) ($pull['created_at'] ?? '')
            )];

            $trackerUrl = 'https://issues.joomla.org/tracker/joomla-cms/' . $pr;
            $title = $this->translator->translateTitle((string) $pull['title']);
            $link = '<a href="' . $trackerUrl . '">' . $trackerUrl . '</a>';
            $body = $this->translator->translateBody((string) ($pull['body'] ?? ''));

            $actions[] = ['kind' => 'message', 'text' => Persian::rtl($title . "\n\n" . $link . "\n\n" . $body)];

            $this->collectImages($actions, (string) ($pull['body'] ?? ''));
            $newest = (string) ($pull['created_at'] ?? '');
        }

        $items = $this->conversationItems($pr, $lastCommentAt);

        foreach ($items as $item) {
            $createdAt = (string) $item['created_at'];

            if ($newest === null || $createdAt > $newest) {
                $newest = $createdAt;
            }

            foreach ($this->itemActions($item) as $action) {
                $actions[] = $action;
            }
        }

        if ($isUpdate && count($actions) === 0) {
            $warnings[] = 'No new items.';
        }

        return [
            'pr'           => $pr,
            'is_update'    => $isUpdate,
            'topic_name'   => $topicName,
            'thread_id'    => $threadId,
            'actions'      => $actions,
            'last_item_at' => $newest,
            'warnings'     => $warnings,
        ];
    }

    private function conversationItems(int $pr, ?string $after): array
    {
        $items = [];

        foreach ($this->github->issueComments($pr) as $c) {
            $items[] = [
                'type'               => 'comment',
                'created_at'         => (string) ($c['created_at'] ?? ''),
                'login'              => (string) ($c['user']['login'] ?? ''),
                'author_association' => (string) ($c['author_association'] ?? 'NONE'),
                'body'               => (string) ($c['body'] ?? ''),
            ];
        }

        foreach ($this->github->reviews($pr) as $r) {
            $state = (string) ($r['state'] ?? '');

            if ($state === '' || $state === 'COMMENTED') {
                continue;
            }

            $items[] = [
                'type'               => 'review',
                'created_at'         => (string) ($r['submitted_at'] ?? $r['created_at'] ?? ''),
                'login'              => (string) ($r['user']['login'] ?? ''),
                'author_association' => (string) ($r['author_association'] ?? 'NONE'),
                'state'              => $state,
                'body'               => (string) ($r['body'] ?? ''),
            ];
        }

        foreach ($this->github->timeline($pr) as $e) {
            $event = (string) ($e['event'] ?? '');

            $allowed = ['labeled', 'unlabeled', 'renamed', 'cross-referenced', 'review_requested', 'review_requested_removed', 'closed', 'merged', 'reopened'];

            if (!in_array($event, $allowed, true)) {
                continue;
            }

            $items[] = [
                'type'       => 'event',
                'event'      => $event,
                'created_at' => (string) ($e['created_at'] ?? ''),
                'login'      => (string) ($e['actor']['login'] ?? ($e['user']['login'] ?? '')),
                'raw'        => $e,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp($a['created_at'], $b['created_at']);
        });

        if ($after !== null && $after !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($after): bool {
                return $item['created_at'] > $after;
            }));
        }

        return $items;
    }

    private function itemActions(array $item): array
    {
        $actions = [];

        if ($item['type'] === 'event') {
            $actions[] = ['kind' => 'message', 'text' => $this->eventMessage($item)];

            return $actions;
        }

        $actions[] = ['kind' => 'message', 'text' => $this->header(
            $item['login'],
            $item['author_association'],
            $item['created_at']
        )];

        $prefix = '';

        if ($item['type'] === 'review') {
            $state = $this->labels['review_state'][$item['state']] ?? $item['state'];
            $prefix = Persian::rtl($state) . "\n\n";
        }

        $segments = $this->segments($item['body']);
        $buffer = $prefix;
        $lastLine = '';

        foreach ($segments as $segment) {
            if ($segment['type'] === 'text') {
                $translated = $this->translator->translateBody($segment['html']);

                if ($translated !== '') {
                    $buffer .= $translated . "\n\n";
                    $lines = array_filter(array_map('trim', explode("\n", $translated)));
                    $lastLine = (string) (end($lines) ?: '');
                }

                continue;
            }

            if ($buffer !== '') {
                $actions[] = ['kind' => 'message', 'text' => Persian::rtl(trim($buffer))];
                $buffer = '';
            }

            $caption = $segment['alt'] !== '' ? $segment['alt'] : $lastLine;

            if ($caption !== '') {
                $caption = $this->translator->translateTitle($caption);
            }

            $actions[] = ['kind' => 'photo', 'url' => $segment['src'], 'caption' => $caption === '' ? null : Persian::rtl($caption)];
        }

        if (trim($buffer) !== '') {
            $actions[] = ['kind' => 'message', 'text' => Persian::rtl(trim($buffer))];
        }

        return $actions;
    }

    private function collectImages(array &$actions, string $body): void
    {
        foreach ($this->segments($body) as $segment) {
            if ($segment['type'] !== 'img') {
                continue;
            }

            $caption = $segment['alt'] !== '' ? $this->translator->translateTitle($segment['alt']) : null;
            $actions[] = ['kind' => 'photo', 'url' => $segment['src'], 'caption' => $caption === null ? null : Persian::rtl($caption)];
        }
    }

    private function segments(string $body): array
    {
        $parts = preg_split('#(<img\b[^>]*>)#i', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('#^<img\b#i', $part) === 1) {
                $alt = preg_match('#alt="([^"]*)"#i', $part, $m) === 1 ? $m[1] : '';
                $src = preg_match('#src="([^"]*)"#i', $part, $m) === 1 ? $m[1] : '';

                $out[] = ['type' => 'img', 'src' => $src, 'alt' => $alt];
                continue;
            }

            $out[] = ['type' => 'text', 'html' => $part];
        }

        return $out;
    }

    private function eventMessage(array $item): string
    {
        $event = $item['event'];
        $raw = $item['raw'] ?? [];
        $login = $item['login'];
        $actor = $login !== '' ? $this->profileLink($login) : '';
        $template = $this->labels['events'][$event] ?? $event;

        $replace = [
            '{actor}' => $actor,
            '{label}' => (string) ($raw['label']['name'] ?? ''),
            '{old}'   => (string) ($raw['rename']['from'] ?? ''),
            '{new}'   => (string) ($raw['rename']['to'] ?? ''),
            '{title}' => (string) ($raw['source']['issue']['title'] ?? ''),
        ];

        $text = strtr($template, $replace);

        if ($event === 'cross-referenced' && isset($raw['source']['issue']['html_url'])) {
            $text .= ' (<a href="' . $raw['source']['issue']['html_url'] . '">' . $raw['source']['issue']['html_url'] . '</a>)';
        }

        return Persian::rtl($text);
    }

    private function header(string $login, string $association, string $createdAt): string
    {
        $name = $this->realName($login);
        $label = $this->labels['association'][$association] ?? '';
        $first = $label !== '' ? $label . ': ' . $name : $name;

        return Persian::rtl($first)
            . "\n\n" . Persian::rtl($this->profileLink($login))
            . "\n\n" . Persian::rtl(Persian::date($createdAt));
    }

    private function profileLink(string $login): string
    {
        return '<a href="https://github.com/' . $login . '">' . $login . '</a>';
    }

    private function realName(string $login): string
    {
        if (array_key_exists($login, $this->nameCache)) {
            return $this->nameCache[$login];
        }

        $user = $this->github->user($login);
        $name = trim((string) ($user['name'] ?? ''));

        if ($name === '') {
            $name = $login;
        }

        $this->nameCache[$login] = $name;
        $this->flushNames();

        return $name;
    }

    private function flushNames(): void
    {
        file_put_contents(
            $this->nameCachePath,
            json_encode($this->nameCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }

    private function topicName(int $pr, string $rawTitle): string
    {
        $version = '';

        if (preg_match('#^\s*\[([^\]]+)\]#', $rawTitle, $m) === 1) {
            $version = $m[1];
        }

        $translated = $this->translator->translateTitle($rawTitle);
        $translated = preg_replace('#^\s*\[[^\]]+\]\s*#u', '', $translated) ?? $translated;

        return '[#' . $pr . '] - ' . ($version !== '' ? '[' . $version . '] ' : '') . trim($translated);
    }
}
