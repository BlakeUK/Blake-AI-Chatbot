<?php
// src/Telegram/Notifier.php
//
// Staff-facing alerts only — this never talks to customers. A message fires
// to a configured Telegram chat when a support ticket is created. No inbound
// webhook: chat-id discovery uses a one-off getUpdates poll instead, so
// there's no public endpoint to register or secure for this feature.

declare(strict_types=1);

namespace Telegram;

class Notifier
{
    private const API_BASE  = 'https://api.telegram.org/bot';
    private const ADMIN_URL = 'https://chat.blakegroup.uk/admin/';

    // ── Config ────────────────────────────────────────────────────────────────
    // Bot token is a real credential (holds/read access to the bot) so it's
    // encrypted at rest via the existing api_keys mechanism, same as Gemini.
    // Chat id + the enabled flag aren't secrets - just "where do alerts go" -
    // so they live in the plain settings table, matching product_extract_template.

    public static function getConfig(): array
    {
        $pdo = db();

        $token = null;
        $row = $pdo->prepare('SELECT key_enc, iv, tag FROM api_keys WHERE service = ?');
        $row->execute(['telegram']);
        if ($r = $row->fetch()) {
            $key = hex2bin(CFG['encrypt_key']);
            $dec = openssl_decrypt(
                hex2bin($r['key_enc']), 'aes-256-gcm', $key,
                OPENSSL_RAW_DATA, hex2bin($r['iv']), hex2bin($r['tag'])
            );
            $token = $dec ?: null;
        }

        $settingsRows = $pdo->prepare('SELECT key, value FROM settings WHERE key IN (?, ?)');
        $settingsRows->execute(['telegram_chat_id', 'telegram_alerts_enabled']);

        $chatId  = null;
        $enabled = true; // default on: once token+chat_id are both saved, alerts just work
        foreach ($settingsRows->fetchAll() as $s) {
            if ($s['key'] === 'telegram_chat_id') {
                $chatId = $s['value'] !== '' ? $s['value'] : null;
            }
            if ($s['key'] === 'telegram_alerts_enabled') {
                $enabled = $s['value'] === '1';
            }
        }

        return ['bot_token' => $token, 'chat_id' => $chatId, 'enabled' => $enabled];
    }

    public static function isConfigured(): bool
    {
        $c = self::getConfig();
        return $c['bot_token'] !== null && $c['chat_id'] !== null;
    }

    // ── Low-level send ───────────────────────────────────────────────────────
    // Returns a result array rather than throwing - callers (admin "test"
    // button, sendTicketAlert below) need to handle failure without a
    // try/catch at every call site, and sendTicketAlert in particular must
    // never let a Telegram problem bubble up into the customer-facing flow.

    public static function send(string $text, ?string $tokenOverride = null, ?string $chatIdOverride = null): array
    {
        $needsLookup = $tokenOverride === null || $chatIdOverride === null;
        $cfg    = $needsLookup ? self::getConfig() : null;
        $token  = $tokenOverride  ?? ($cfg['bot_token'] ?? null);
        $chatId = $chatIdOverride ?? ($cfg['chat_id'] ?? null);

        if (!$token || !$chatId) {
            return ['ok' => false, 'error' => 'Bot token and chat ID are both required'];
        }

        $ch = curl_init(self::API_BASE . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]),
            // Short timeouts - this is a side-effect off a customer-facing
            // request path, it must not make escalate.php slow.
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'error' => "Network error: {$err}"];
        }

        $data = json_decode((string)$resp, true);
        if ($code !== 200 || !is_array($data) || empty($data['ok'])) {
            $desc = is_array($data) ? ($data['description'] ?? 'Unknown error') : 'Invalid response';
            return ['ok' => false, 'error' => "Telegram API error ({$code}): {$desc}"];
        }

        return ['ok' => true, 'error' => null];
    }

    // ── Ticket alert ──────────────────────────────────────────────────────────
    // Called from escalate.php right after a support ticket is inserted.
    // Deliberately swallows everything: a broken/unconfigured/rate-limited
    // Telegram integration must never stop a customer's ticket from being
    // created or their confirmation message from being returned.

    public static function sendTicketAlert(int $ticketId, string $subject, ?string $email, ?string $pageUrl): void
    {
        try {
            $cfg = self::getConfig();
            if (!$cfg['enabled'] || !$cfg['bot_token'] || !$cfg['chat_id']) {
                return; // not configured, or deliberately silenced - not an error
            }

            $lines = [
                '🎫 <b>New support ticket #' . $ticketId . '</b>',
                htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
                '',
            ];
            if ($email) {
                $lines[] = 'From: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
            }
            if ($pageUrl) {
                $lines[] = 'Viewing: ' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8');
            }
            $lines[] = '';
            $lines[] = '<a href="' . self::ADMIN_URL . '">Open in admin</a>';

            $result = self::send(implode("\n", $lines), $cfg['bot_token'], $cfg['chat_id']);
            if (!$result['ok']) {
                error_log('Telegram ticket alert failed: ' . $result['error']);
            }
        } catch (\Throwable $e) {
            error_log('Telegram ticket alert exception: ' . $e->getMessage());
        }
    }

    // ── Chat-id discovery ─────────────────────────────────────────────────────
    // No webhook is registered for this bot (staff-alerts-only, nothing needs
    // to receive customer traffic), so plain getUpdates polling works fine.
    // If a webhook is ever added later for a different feature, note that
    // Telegram disables getUpdates for a bot the moment a webhook is set.

    public static function getRecentChats(?string $tokenOverride = null): array
    {
        $token = $tokenOverride ?? (self::getConfig()['bot_token'] ?? null);
        if (!$token) {
            return [];
        }

        $ch = curl_init(self::API_BASE . $token . '/getUpdates?limit=50');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return [];
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data) || empty($data['ok']) || !is_array($data['result'] ?? null)) {
            return [];
        }

        $seen  = [];
        $chats = [];
        // Newest first, so if the same chat messaged more than once the
        // freshest name/username wins and shows at the top of the list.
        foreach (array_reverse($data['result']) as $update) {
            $msg  = $update['message'] ?? $update['channel_post'] ?? null;
            $chat = $msg['chat'] ?? null;
            if (!$chat || !isset($chat['id'])) {
                continue;
            }

            $id = (string)$chat['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $name  = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
            $label = $chat['title'] ?? ($name !== '' ? $name : ('Chat ' . $id));
            if (!empty($chat['username'])) {
                $label .= ' (@' . $chat['username'] . ')';
            }

            $chats[] = ['chat_id' => $id, 'label' => $label, 'type' => $chat['type'] ?? 'private'];
        }

        return $chats;
    }
}
