<?php
// config/config.example.php — copy to config/config.php and fill in values
return [
    'db_path'        => __DIR__ . '/../data/chatbot.db',
    'upload_path'    => __DIR__ . '/../uploads/',
    'log_path'       => __DIR__ . '/../logs/',

    // Generate: php -r "echo bin2hex(random_bytes(32));"
    'encrypt_key'    => 'CHANGE_ME_32_BYTE_HEX_KEY',

    'gemini_flash'   => 'gemini-1.5-flash',
    'gemini_pro'     => 'gemini-1.5-pro',

    'cors_origins'   => [
        'https://www.blake-uk.com',
        'https://blake-uk.com',
        'https://chat.blake-uk.com',
    ],

    'rate_limit_chat'    => 20,
    'rate_limit_admin'   => 60,
    'session_lifetime'   => 3600,
    'escalate_threshold' => 0.4,
];
