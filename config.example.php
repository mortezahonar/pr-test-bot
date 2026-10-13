<?php

declare(strict_types=1);

/*
 * Copy this file to secrets.php and fill in your values.
 * secrets.php is git-ignored and must never be committed.
 */

return [
    'bot_token'          => 'PASTE_TELEGRAM_BOT_TOKEN',
    'chat_id'            => 'PASTE_CHAT_ID',
    'github_token'       => 'PASTE_GITHUB_TOKEN',
    'gemini_api_key'     => 'PASTE_GEMINI_API_KEY',
    'gemini_model'       => 'gemini-3.5-flash,gemini-3.1-flash-lite',

    // Optional SOCKS5 proxy, e.g. 'socks5h://127.0.0.1:9050'.
    'proxy'              => '',

    // Optional: restrict the bot to these Telegram user ids. Empty = anyone in chat_id.
    'allowed_user_ids'   => [],
];
