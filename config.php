<?php

declare(strict_types=1);

/*
 * Runtime configuration.
 *
 * Secrets are loaded from (in order):
 *   1. environment variables
 *   2. secrets.php in this directory (never commit it)
 *
 * Copy config.example.php to secrets.php and fill in your values.
 */

$config = [
    'bot_token'          => (string) (getenv('PR_BOT_TOKEN') ?: ''),
    'chat_id'            => (string) (getenv('PR_CHAT_ID') ?: ''),
    'github_token'       => (string) (getenv('GITHUB_TOKEN') ?: ''),
    'gemini_api_key'     => (string) (getenv('GEMINI_API_KEY') ?: ''),
    'gemini_model'       => (string) (getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash'),
    'proxy'              => (string) (getenv('PR_BOT_PROXY') ?: ''),
    'state_dir'          => __DIR__ . '/state',
    'glossary_path'      => (string) (getenv('PR_GLOSSARY') ?: (getenv('HOME') . '/.config/opencode/references/joomla-translation-glossary.md')),
    'link_commit_hashes' => true,
    'allowed_user_ids'   => [],
];

if (is_file(__DIR__ . '/secrets.php')) {
    $local = require __DIR__ . '/secrets.php';

    if (is_array($local)) {
        $config = array_replace($config, $local);
    }
}

return $config;
