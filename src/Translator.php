<?php

declare(strict_types=1);

final class Translator
{
    private const L = "\u{27E6}";
    private const R = "\u{27E7}";
    private const AI_POLICY_URL = 'https://developer.joomla.org/generative-ai-policy.html';
    private const AI_POLICY_TRANSLATION_URL = 'https://t.me/Joomla_PR_test/211';

    /**
     * The only tags Telegram accepts in HTML mode. Any other tag found in the
     * source is text the author was talking about, not markup to render.
     */
    private const RENDERED_TAGS = [
        'a', 'b', 'blockquote', 'code', 'del', 'em', 'i', 'ins', 'pre', 's',
        'span', 'strike', 'strong', 'tg-emoji', 'tg-spoiler', 'u',
    ];

    /** @var array<int, array{type:string, data:array}> */
    private array $atoms = [];

    public function __construct(
        private TextGenerator $generator,
        private string $systemPrompt,
        private string $glossary = '',
        private bool $linkCommitHashes = true
    ) {
    }

    public function translateTitle(string $text): string
    {
        return $this->translate($text, false);
    }

    /**
     * Translate a GitHub comment/body HTML into Telegram HTML.
     * Images are removed here and handled by the caller (sendPhoto).
     */
    public function translateBody(string $html): string
    {
        return $this->translate($this->normalize($html), true);
    }

    private function translate(string $source, bool $markup): string
    {
        $source = trim($source);

        if ($source === '') {
            return '';
        }

        $this->atoms = [];
        $protected = $this->protect($source);

        if (trim($protected) === '') {
            return '';
        }

        $translated = $this->generator->generate($this->systemPrompt(), $protected);
        $restored = $this->restore($translated);

        if ($markup) {
            $restored = $this->postProcess($restored);
        }

        return trim($restored);
    }

    private function systemPrompt(): string
    {
        $prompt = $this->systemPrompt;

        if ($this->glossary !== '') {
            $prompt .= "\n\n" . $this->glossary;
        }

        return $prompt;
    }

    private function normalize(string $html): string
    {
        $s = $html;
        $s = str_replace("\r\n", "\n", $s);
        $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s;
        $s = preg_replace('#<hr\s*/?>#i', "\n[[HR]]\n", $s) ?? $s;
        $s = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $s) ?? $s;
        $s = preg_replace('#<p[^>]*>#i', '', $s) ?? $s;
        $s = str_replace('</p>', "\n", $s);
        $s = preg_replace('#</?sub>#i', '', $s) ?? $s;
        $s = preg_replace('#</?(b|strong|i|em|u|s|del|ins|blockquote|details|summary|tt)>#i', '', $s) ?? $s;
        $s = preg_replace('#\n{3,}#', "\n\n", $s) ?? $s;

        return trim($s);
    }

    private function protect(string $s): string
    {
        // Joomla's PR template has one fixed AI-policy checklist line. Keep
        // its state from the source PR, but do not let the translator rewrite
        // the sentence or its two policy links.
        $s = preg_replace_callback(
            '~(?mi)^(?<prefix>[ \t]*(?:[-*][ \t]*)?)\[(?<checked>[ xX])\][ \t]*'
            . 'I read the \[Generative AI policy\]\(https://developer\.joomla\.org/generative-ai-policy\.html\)'
            . '[ \t]+and my contribution is either not created with the help of AI or is compatible with the policy and GNU/GPL 2 or later\.[ \t]*$~',
            function (array $m): string {
                return $this->atom('ai_policy', [
                    'prefix'  => $m['prefix'],
                    'checked' => strtolower($m['checked']) === 'x',
                ]);
            },
            $s
        ) ?? $s;

        // Markdown links and images come from GitHub, which renders Markdown;
        // Telegram does not. Without this the URL inside "[text](url)" is
        // turned into an anchor of its own and the link ends up broken. A
        // markdown image becomes a link to the image, because the photo itself
        // is collected from HTML <img> tags by the caller.
        $s = preg_replace_callback('#!?\[([^\]\n]*)\]\((https?://[^\s)]+)\)#i', function (array $m): string {
            $label = $m[1] === '' ? $m[2] : $m[1];

            return '<a href="' . $m[2] . '">' . $label . '</a>';
        }, $s) ?? $s;

        // images (kept as atoms; sent separately)
        $s = preg_replace_callback('#<img\b[^>]*>#i', function (array $m): string {
            $alt = preg_match('#alt="([^"]*)"#i', $m[0], $a) === 1 ? $a[1] : '';
            $src = preg_match('#src="([^"]*)"#i', $m[0], $a) === 1 ? $a[1] : '';

            return $this->atom('img', ['src' => $src, 'alt' => $alt]);
        }, $s) ?? $s;

        // opening anchors
        $s = preg_replace_callback('#<a\s+[^>]*href="([^"]*)"[^>]*>#i', function (array $m): string {
            return $this->atom('linkopen', ['href' => $m[1]]);
        }, $s) ?? $s;

        // closing anchors
        $s = preg_replace_callback('#</a>#i', function (): string {
            return $this->atom('linkclose', []);
        }, $s) ?? $s;

        // code spans (content is never translated)
        $s = preg_replace_callback('#<code>(.*?)</code>#is', function (array $m): string {
            return $this->atom('code', ['text' => html_entity_decode($m[1], ENT_QUOTES)]);
        }, $s) ?? $s;

        // GitHub stores this emoji as a shortcode, while its web UI renders ✅.
        // Protect only this shortcode so the translator cannot rewrite it.
        $s = preg_replace_callback('/:white_check_mark:/', function (): string {
            return $this->atom('emoji', ['text' => '✅']);
        }, $s) ?? $s;

        // mentions
        $s = preg_replace_callback('#@([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))#', function (array $m): string {
            return $this->atom('mention', ['login' => $m[1]]);
        }, $s) ?? $s;

        // HTML entities written literally in the source
        $s = preg_replace_callback('~&(?:[a-zA-Z]+|#[0-9]+);~', function (array $m): string {
            return $this->atom('entity', ['text' => $m[0]]);
        }, $s) ?? $s;

        // bare URLs
        $s = preg_replace_callback('#https?://[^\s<>"\]]+#i', function (array $m): string {
            return $this->atom('url', ['url' => rtrim($m[0], '.,;:)')]);
        }, $s) ?? $s;

        // commit hashes
        $s = preg_replace_callback('#\b[0-9a-f]{40}\b#', function (array $m): string {
            return $this->atom('hash', ['hash' => $m[0]]);
        }, $s) ?? $s;

        // Anything left that looks like a tag cannot be rendered by Telegram:
        // the author was naming it, so escape it and keep it readable instead
        // of letting Telegram reject the whole message.
        $s = preg_replace_callback('#</?([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^<>]*)?/?>#', function (array $m): string {
            if (in_array(strtolower($m[1]), self::RENDERED_TAGS, true)) {
                return $m[0];
            }

            return $this->atom('plain', ['text' => $m[0]]);
        }, $s) ?? $s;

        return $s;
    }

    private function restore(string $s): string
    {
        $pattern = '#' . self::L . '(\d+)' . self::R . '#u';

        return preg_replace_callback($pattern, function (array $m): string {
            $atom = $this->atoms[(int) $m[1]] ?? null;

            if ($atom === null) {
                return '';
            }

            return $this->render($atom);
        }, $s) ?? $s;
    }

    private function render(array $atom): string
    {
        $data = $atom['data'];

        switch ($atom['type']) {
            case 'linkopen':
                return '<a href="' . $this->esc($data['href']) . '">';
            case 'linkclose':
                return '</a>';
            case 'code':
                return '<code>' . $this->esc($data['text']) . '</code>';
            case 'emoji':
                return $data['text'];
            case 'ai_policy':
                $checkbox = $data['checked'] ? 'x' : ' ';

                return "\n" . $data['prefix'] . '[' . $checkbox . '] '
                    . '<a href="' . self::AI_POLICY_URL . '">سیاست هوش مصنوعی مولد</a>'
                    . ' را مطالعه کردم و مشارکت من یا با کمک هوش مصنوعی ایجاد نشده است و یا با '
                    . '<a href="' . self::AI_POLICY_TRANSLATION_URL . '">این سیاست</a>'
                    . ' و مجوز GNU/GPL نسخه ۲ یا بالاتر سازگار است.'
                    . "\n";
            case 'entity':
                return '<code>' . $this->esc($data['text']) . '</code>';
            case 'plain':
                // A tag Telegram cannot render: show it as text, not as markup.
                return $this->esc($data['text']);
            case 'mention':
                $login = $data['login'];

                return '<a href="https://github.com/' . $login . '">@' . $login . '</a>';
            case 'url':
                $url = $data['url'];

                return '<a href="' . $this->esc($url) . '">' . $this->esc($url) . '</a>';
            case 'hash':
                if ($this->linkCommitHashes) {
                    return '<a href="https://github.com/joomla/joomla-cms/commit/' . $data['hash'] . '">'
                        . substr($data['hash'], 0, 7) . '</a>';
                }

                return $this->esc($data['hash']);
            case 'img':
                return '';
        }

        return '';
    }

    private function postProcess(string $s): string
    {
        $s = str_replace('[[HR]]', Persian::separator(), $s);
        $s = $this->collapseBlankLines($s);
        $s = $this->tidyTags($s);

        return trim($s);
    }

    private function collapseBlankLines(string $s): string
    {
        $lines = explode("\n", $s);
        $out = [];
        $blank = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $blank++;
                if ($blank > 1) {
                    continue;
                }
            } else {
                $blank = 0;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private function tidyTags(string $s): string
    {
        // Avoid nested/mismatched anchor tags produced by the model.
        $s = preg_replace('#(<a [^>]*>)\s*(<a [^>]*>)#', '$1', $s) ?? $s;
        $s = preg_replace('#(</a>)\s*(</a>)#', '$1', $s) ?? $s;

        return $s;
    }

    private function atom(string $type, array $data): string
    {
        $id = count($this->atoms);
        $this->atoms[$id] = ['type' => $type, 'data' => $data];

        return self::L . $id . self::R;
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
