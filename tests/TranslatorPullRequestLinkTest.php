<?php
declare(strict_types=1);
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';

final class PullRequestLinkEchoGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $userText;
    }
}

$referenceResolver = static fn (int $number): ?string => match ($number) {
    123 => 'https://github.com/joomla/joomla-cms/pull/123',
    456 => 'https://github.com/joomla/joomla-cms/issues/456',
    default => null,
};

$translated = (new Translator(new PullRequestLinkEchoGenerator(), 'test', '', true, $referenceResolver))->translateBody(
    'This fixes #123 and also references #456.' . "\n\n"
    . '<code>#789</code>' . "\n\n"
    . 'See https://example.test/page#789.'
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(
    str_contains($translated, '<a href="https://github.com/joomla/joomla-cms/pull/123">#123</a>')
    && str_contains($translated, '<a href="https://github.com/joomla/joomla-cms/issues/456">#456</a>'),
    'numeric references must link to the matching Joomla issue or PR'
);
$assert(
    str_contains($translated, '<code>#789</code>')
    && !str_contains($translated, 'pull/789'),
    'numeric references inside code must not become links'
);
$assert(
    str_contains($translated, '<a href="https://example.test/page#789">https://example.test/page#789</a>')
    && substr_count($translated, 'pull/789') === 0,
    'numeric URL fragments must not become PR links'
);

fwrite(STDOUT, "OK: Pull Request reference link tests passed.\n");
