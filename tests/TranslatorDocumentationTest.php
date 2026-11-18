<?php
declare(strict_types=1);
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';

final class DocumentationEchoGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $userText;
    }
}

$source = <<<'TEXT'
### Link to documentations
Please select:
- [x] Documentation link for guide.joomla.org: <link>
- [ ] No documentation changes for guide.joomla.org needed
- [ ] Pull Request link for manual.joomla.org: <link>
- [x] No documentation changes for manual.joomla.org needed
TEXT;

$translated = (new Translator(new DocumentationEchoGenerator(), 'test'))->translateBody($source);
$expected = <<<'TEXT'
<b>پیوند به مستندات</b>

لطفاً انتخاب کنید:

- [x] پیوند مستندات برای guide.joomla.org:

- [ ] نیازی به تغییر مستندات برای guide.joomla.org نیست

- [ ] پیوند Pull Request برای manual.joomla.org:

- [x] نیازی به تغییر مستندات برای manual.joomla.org نیست
TEXT;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert($translated === $expected, 'documentation block must use the exact fixed Persian layout');
$assert(!str_contains($translated, 'Link to documentations'), 'English documentation heading must not remain');
$assert(!str_contains($translated, '<link>'), 'source documentation placeholders must not remain');
$assert(substr_count($translated, '[x]') === 2, 'two checked documentation items must remain checked');
$assert(substr_count($translated, '[ ]') === 2, 'two unchecked documentation items must remain unchecked');

fwrite(STDOUT, "OK: documentation checklist tests passed.\n");
