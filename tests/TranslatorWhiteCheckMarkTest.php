<?php
declare(strict_types=1);
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';

final class WhiteCheckMarkEchoGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $userText;
    }
}

$translator = new Translator(new WhiteCheckMarkEchoGenerator(), 'test');
$translated = $translator->translateBody('<p>Test completed :white_check_mark:</p>');

if (!str_contains($translated, '✅') || str_contains($translated, 'white_check_mark')) {
    fwrite(STDERR, "FAIL: :white_check_mark: must be restored as ✅\n");
    exit(1);
}

fwrite(STDOUT, "OK: white_check_mark is restored as ✅.\n");
