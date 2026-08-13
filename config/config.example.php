<?php
// config/config.example.php — copy to config/config.php and fill in values
return [
    'db_path'        => __DIR__ . '/../data/chatbot.db',
    'upload_path'    => __DIR__ . '/../uploads/',
    'log_path'       => __DIR__ . '/../logs/',

    // Generate: php -r "echo bin2hex(random_bytes(32));"
    'encrypt_key'    => 'CHANGE_ME_32_BYTE_HEX_KEY',

    // Shared secret baked into the first-party mobile apps (mobile/admin_app,
    // mobile/customer_app) at build time — see mobile/*/lib/api_client.dart's
    // kAppKey. Native HTTP clients don't send a browser Origin header, so
    // public/api/chat/session.php can't use CFG['cors_origins'] to recognise
    // them the way it does the widget on blake-uk.com; this header is the
    // equivalent signal for a non-browser first-party caller. Not a strong
    // secret (anything shipped in an app binary can be extracted) but it
    // stops a plain script from getting first-party treatment for free just
    // by omitting Origin, which is what CFG['mobile_app_key'] being unset
    // treated as first-party before this existed.
    // Generate: php -r "echo bin2hex(random_bytes(24));"
    'mobile_app_key' => 'CHANGE_ME_MOBILE_APP_KEY',

    'gemini_flash'   => 'gemini-1.5-flash',
    'gemini_pro'     => 'gemini-1.5-pro',

    'cors_origins'   => [
        'https://www.blake-uk.com',
        'https://blake-uk.com',
        'https://chat.blakegroup.uk',
    ],

    'rate_limit_chat'    => 20,
    'rate_limit_admin'   => 60,
    'session_lifetime'   => 3600,
    'escalate_threshold' => 0.4,

    // Login gate for public/check/ (the sitemap/SEO tool) - deliberately
    // one fixed credential, not another admin_users account, since this
    // tool is scoped to itself rather than the rest of the admin panel.
    // On an already-deployed server, scripts/ensure_check_tool_config.php
    // adds these two keys on the next deploy if they're missing - config.php
    // only gets generated from this file once, on first install, so a key
    // added here later never reaches an existing deployment on its own.
    // Generate a hash: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
    'check_tool_username'      => 'CHANGE_ME',
    'check_tool_password_hash' => 'CHANGE_ME_BCRYPT_HASH',
];
