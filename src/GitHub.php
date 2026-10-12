<?php

declare(strict_types=1);

final class GitHub
{
    private const API = 'https://api.github.com';
    private const REPO = 'joomla/joomla-cms';

    public function __construct(private string $token = '')
    {
    }

    /**
     * GET a path with automatic pagination. Returns the decoded array.
     */
    public function paginate(string $path): array
    {
        $items = [];
        $page = 1;
        $separator = str_contains($path, '?') ? '&' : '?';

        while (true) {
            $url = self::API . $path . $separator . 'per_page=100&page=' . $page;
            $res = $this->request('GET', $url);

            if (!is_array($res['json'])) {
                throw new RuntimeException('GitHub invalid JSON for ' . $url);
            }

            if ($res['json'] === [] || array_is_list($res['json']) === false) {
                // Single object response (not paginated).
                return $res['json'];
            }

            $items = array_merge($items, $res['json']);

            if (count($res['json']) < 100) {
                break;
            }

            $page++;

            if ($page > 20) {
                break;
            }
        }

        return $items;
    }

    public function pull(int $number): array
    {
        return $this->paginate('/repos/' . self::REPO . '/pulls/' . $number);
    }

    public function issueComments(int $number): array
    {
        $all = $this->paginate('/repos/' . self::REPO . '/issues/' . $number . '/comments');

        return is_array($all) ? $all : [];
    }

    public function reviewComments(int $number): array
    {
        $all = $this->paginate('/repos/' . self::REPO . '/pulls/' . $number . '/comments');

        return is_array($all) ? $all : [];
    }

    public function timeline(int $number): array
    {
        $all = $this->paginate('/repos/' . self::REPO . '/issues/' . $number . '/timeline');

        return is_array($all) ? $all : [];
    }

    public function reviews(int $number): array
    {
        $all = $this->paginate('/repos/' . self::REPO . '/pulls/' . $number . '/reviews');

        return is_array($all) ? $all : [];
    }

    public function user(string $login): array
    {
        return $this->paginate('/users/' . rawurlencode($login));
    }

    private function request(string $method, string $url): array
    {
        $headers = [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'joomla-pr-test-bot',
        ];

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $res = Http::json($method, $url, $headers);

        if ($res['status'] === 403 || $res['status'] === 429) {
            throw new RuntimeException('GitHub rate limit or forbidden (HTTP ' . $res['status'] . '): ' . $res['raw']);
        }

        if ($res['status'] >= 400) {
            throw new RuntimeException('GitHub HTTP ' . $res['status'] . ': ' . $res['raw']);
        }

        return $res;
    }
}
