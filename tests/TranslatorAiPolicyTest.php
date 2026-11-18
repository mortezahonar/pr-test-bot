<?php
declare(strict_types=1);
require __DIR__ . '/../src/TextGenerator.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/Translator.php';

final class AiPolicyEchoGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return $userText;
    }
}

$translator = new Translator(new AiPolicyEchoGenerator(), 'test');
$unchecked = $translator->translateBody(
    '- [ ] I read the [Generative AI policy](https://developer.joomla.org/generative-ai-policy.html)'
    . ' and my contribution is either not created with the help of AI or is compatible with the policy and GNU/GPL 2 or later.'
);
$checked = $translator->translateBody(
    '- [x] I read the [Generative AI policy](https://developer.joomla.org/generative-ai-policy.html)'
    . ' and my contribution is either not created with the help of AI or is compatible with the policy and GNU/GPL 2 or later.'
);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($unchecked, '- [ ] '), 'unchecked policy must remain unchecked');
$assert(str_contains($checked, '- [x] '), 'checked policy must remain checked');
$assert(
    substr_count($unchecked, 'سیاست هوش مصنوعی مولد') === 1
    && substr_count($unchecked, 'این سیاست') === 1,
    'policy sentence must use the fixed Persian wording'
);
$assert(
    str_contains($unchecked, 'href="https://developer.joomla.org/generative-ai-policy.html"')
    && str_contains($unchecked, 'href="https://t.me/Joomla_PR_test/211"'),
    'policy sentence must contain both requested links'
);
$assert(
    !str_contains($unchecked, 'Generative AI policy')
    && !str_contains($unchecked, '(لینک صفحه اصلی)'),
    'source placeholder wording must not remain in the fixed sentence'
);
$assert(
    preg_replace('/\[[ xX]\]/', '', $unchecked) === preg_replace('/\[[ xX]\]/', '', $checked),
    'checked state must be the only difference between the two policy lines'
);

fwrite(STDOUT, "OK: AI policy checklist tests passed.\n");
