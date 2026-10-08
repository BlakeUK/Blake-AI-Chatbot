#!/usr/bin/env php
<?php
// scripts/set_smtp.php: set up outgoing email from the command line. Same effect as
// Admin > Email > Save, for when the password must not pass through a person or a browser.
//
// Everything comes from the environment (so nothing sensitive appears on the command line or in a log):
//   SMTP_HOST, SMTP_PORT, SMTP_SECURITY (tls|ssl|none), SMTP_USERNAME, SMTP_FROM_EMAIL,
//   SMTP_FROM_NAME, SMTP_REPLY_TO, SMTP_NOTIFY, SMTP_PASSWORD
// Only the values that are present (non-empty) are changed. The password is encrypted exactly as the
// admin form does it and is never printed.
// Usage: php scripts/set_smtp.php [--test=someone@example.com]

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/bootstrap.php';

$env = fn(string $k): string => trim((string)getenv($k));
$die = function (string $m): never { fwrite(STDERR, "set_smtp: $m\n"); exit(1); };

$host = $env('SMTP_HOST');
if ($host !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])$/', $host)) $die('SMTP_HOST is not a valid host name');
$port = $env('SMTP_PORT');
if ($port !== '' && (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535)) $die('SMTP_PORT must be 1 to 65535');
$sec = $env('SMTP_SECURITY');
if ($sec !== '' && !in_array($sec, ['tls', 'ssl', 'none'], true)) $die('SMTP_SECURITY must be tls, ssl or none');
foreach (['SMTP_FROM_EMAIL', 'SMTP_REPLY_TO', 'SMTP_NOTIFY'] as $k) {
    $v = $env($k);
    if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $die("$k is not a valid email address");
}

$map = ['smtp_host' => $host, 'smtp_port' => $port, 'smtp_security' => $sec, 'smtp_username' => $env('SMTP_USERNAME'),
        'smtp_from_email' => $env('SMTP_FROM_EMAIL'), 'smtp_from_name' => $env('SMTP_FROM_NAME'),
        'smtp_reply_to' => $env('SMTP_REPLY_TO'), 'support_notify_email' => $env('SMTP_NOTIFY')];
$changed = [];
foreach ($map as $key => $value) {
    if ($value === '') continue;
    \Mail\Smtp::saveSetting($key, $value);
    $changed[] = $key;
}
$pw = (string)getenv('SMTP_PASSWORD');           // not trimmed: a password may legitimately contain spaces
if ($pw !== '') { \Mail\Smtp::savePassword($pw); $changed[] = 'password'; }
if ($changed) {
    db()->prepare('INSERT INTO audit_log (admin_id, action, target, detail) VALUES (NULL, ?, NULL, ?)')
        ->execute(['email_settings_saved_cli', implode(', ', $changed)]);
}

$cfg = \Mail\Smtp::settings();
echo "changed: " . ($changed ? implode(', ', $changed) : '(nothing)') . "\n";
echo "host: {$cfg['host']}  port: {$cfg['port']}  security: {$cfg['security']}\n";
echo "username: {$cfg['username']}  password saved: " . (($cfg['password'] ?? '') !== '' ? 'yes' : 'NO') . "\n";
echo "from: {$cfg['from_name']} <{$cfg['from_email']}>  reply-to: " . ($cfg['reply_to'] !== '' ? $cfg['reply_to'] : '(none)') . "\n";
echo "staff copy goes to: {$cfg['notify']}\n";
echo "configured: " . (\Mail\Smtp::isConfigured($cfg) ? 'yes' : 'NO') . "\n";

foreach ($argv as $a) {
    if (str_starts_with($a, '--test=')) {
        $to = substr($a, 7);
        try {
            \Mail\Smtp::send($to, 'Blake UK support desk: test email',
                "This is a test email from the Blake UK support desk.\n\nIf you can read this, ticket confirmation emails will be delivered.\n");
            echo "TEST EMAIL SENT to $to\n";
        } catch (\Throwable $e) {
            echo "TEST EMAIL FAILED: " . $e->getMessage() . "\n";
            exit(2);
        }
    }
}
