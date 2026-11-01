<?php

declare(strict_types=1);

require __DIR__ . '/../src/TelegramMessageFormatter.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$lrm = TelegramMessageFormatter::LRM;
$text = 'English-leading Telegram message';
$assert(
    TelegramMessageFormatter::prefixLrm($text) === $lrm . $text,
    'ordinary text and captions must start with U+200E'
);
$assert(
    TelegramMessageFormatter::prefixLrm($lrm . $text) === $lrm . $text,
    'an existing Unicode LRM must not be duplicated'
);
$assert(
    TelegramMessageFormatter::prefixLrm('&#x200E;' . $text) === '&#x200E;' . $text,
    'an existing HTML LRM entity must not be duplicated'
);

$fencedCode = "```php\necho 'x';\n```";
$assert(
    TelegramMessageFormatter::prefixLrm($fencedCode) === $fencedCode,
    'a standalone fenced code block must not receive an LRM'
);
$rtlFencedCode = "\u{200F}```php\n\u{200F}echo 'x';\n\u{200F}```";
$assert(
    TelegramMessageFormatter::prefixLrm($rtlFencedCode) === $rtlFencedCode,
    'RLM marks already present on code-block lines must not cause an LRM prefix'
);
$htmlCode = '<pre><code>echo &quot;x&quot;;</code></pre>';
$assert(
    TelegramMessageFormatter::prefixLrm($htmlCode) === $htmlCode,
    'a standalone HTML code block must not receive an LRM'
);

$rich = json_decode(TelegramMessageFormatter::richMessage($text), true);
$assert(is_array($rich), 'rich message formatter must return valid JSON');
$assert(str_starts_with($rich['html'] ?? '', $lrm), 'rich Telegram messages must start with U+200E');
$assert(str_contains($rich['html'] ?? '', $text), 'rich message text must remain intact');

$richCode = json_decode(TelegramMessageFormatter::richMessage($htmlCode), true);
$assert(
    is_array($richCode) && !str_starts_with($richCode['html'] ?? '', $lrm),
    'standalone code blocks must remain unprefixed in rich messages'
);

fwrite(STDOUT, "OK: Telegram message formatter tests passed.\n");
