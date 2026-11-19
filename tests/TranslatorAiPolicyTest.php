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

final class AiPolicyMergeLineGenerator implements TextGenerator
{
    public function generate(string $system, string $userText, int $timeout = 60): string
    {
        return preg_replace('/\n+(?=\x{27E6}\d+\x{27E7})/u', '', $userText) ?? $userText;
    }
}

$referenceResolver = static fn (int $number): ?string => $number === 123
    ? 'https://github.com/joomla/joomla-cms/pull/123'
    : null;

$translator = new Translator(new AiPolicyEchoGenerator(), 'test', '', true, $referenceResolver);
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

$merged = (new Translator(new AiPolicyMergeLineGenerator(), 'test', '', true, $referenceResolver))->translateBody(
    "Unrelated preceding text.\n\n"
    . '- [x] I read the [Generative AI policy](https://developer.joomla.org/generative-ai-policy.html)'
    . ' and my contribution is either not created with the help of AI or is compatible with the policy and GNU/GPL 2 or later.'
);
$assert(
    str_contains($merged, "Unrelated preceding text.\n- [x] "),
    'AI policy checklist must remain on a separate line from the preceding text'
);
$assert(
    !str_contains($merged, 'Unrelated preceding text.- [x]'),
    'AI policy checklist must not be concatenated to the preceding text'
);

$block = (new Translator(new AiPolicyEchoGenerator(), 'test', '', true, $referenceResolver))->translateBody(
    "Pull Request resolves #123.\n\n"
    . '- [x] I read the [Generative AI policy](https://developer.joomla.org/generative-ai-policy.html)'
    . ' and my contribution is either not created with the help of AI or is compatible with the policy and GNU/GPL 2 or later.'
    . "\n\n### Summary of Changes\n"
);
$expectedBlock = 'درخواست ادغام (Pull Request) شماره <a href="https://github.com/joomla/joomla-cms/pull/123">#123</a> را حل میکند.'
    . "\n\n" . Persian::separator() . "\n"
    . '- [x] ' . '<a href="https://developer.joomla.org/generative-ai-policy.html">سیاست هوش مصنوعی مولد</a>'
    . ' را مطالعه کردم و مشارکت من یا با کمک هوش مصنوعی ایجاد نشده است و یا با '
    . '<a href="https://t.me/Joomla_PR_test/211">این سیاست</a>'
    . ' و مجوز GNU/GPL نسخه ۲ یا بالاتر سازگار است.'
    . "\n" . Persian::separator();
$assert(str_contains($block, $expectedBlock), 'the complete PR policy block must use the fixed layout');
$assert(
    str_contains($block, "درخواست ادغام (Pull Request) شماره <a href=\"https://github.com/joomla/joomla-cms/pull/123\">#123</a> را حل میکند.\n\n" . Persian::separator()),
    'the resolve line must retain its PR number and blank line before the separator'
);
$assert(
    !str_contains($block, 'Pull Request resolves'),
    'the protected block must not leak the source resolve line into the translated output'
);

$emptyNumber = (new Translator(new AiPolicyEchoGenerator(), 'test', '', true, $referenceResolver))->translateBody(
    "Pull Request resolves # .\n\n"
    . '- [ ] I read the [Generative AI policy](https://developer.joomla.org/generative-ai-policy.html)'
    . ' and my contribution is either not created with the help of AI or is compatible with the policy and GNU/GPL 2 or later.'
);
$assert(
    str_contains($emptyNumber, 'درخواست ادغام (Pull Request) شماره # را حل میکند.'),
    'an empty source PR number must remain empty'
);

fwrite(STDOUT, "OK: AI policy checklist tests passed.\n");
