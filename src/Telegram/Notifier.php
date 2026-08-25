<?php
// src/Telegram/Notifier.php
//
// Staff-facing alerts to a configured Telegram chat when a support ticket
// is created or reassigned, with inline buttons for forwarding to another
// department and replying by email. Button taps are picked up by a cron
// script (scripts/process_telegram_updates.php) polling getUpdates, not a
// webhook - there's still no public endpoint to register or secure for
// this, at the cost of up to ~1 minute's delay between a tap and it taking
// effect (the cron interval). This never talks to customers directly -
// nothing here is customer-facing.

declare(strict_types=1);

namespace Telegram;

class Notifier
{
    private const API_BASE      = 'https://api.telegram.org/bot';
    private const ADMIN_URL     = 'https://chat.blakegroup.uk/admin/';
    private const DEPARTMENTS   = ['sales', 'technical', 'accounts'];

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

        $settingsRows = $pdo->prepare('SELECT key, value FROM settings WHERE key IN (?, ?, ?, ?, ?)');
        $settingsRows->execute([
            'telegram_chat_id', 'telegram_alerts_enabled',
            'telegram_chat_id_sales', 'telegram_chat_id_technical', 'telegram_chat_id_accounts',
        ]);

        $chatId  = null;
        $enabled = true; // default on: once token+chat_id are both saved, alerts just work
        // Per-department overrides are optional - a department with no chat
        // id of its own falls back to the shared one (resolveChatId()
        // below), so setting up alerts at all still only takes one chat id.
        $deptChatIds = ['sales' => null, 'technical' => null, 'accounts' => null];
        foreach ($settingsRows->fetchAll() as $s) {
            if ($s['key'] === 'telegram_chat_id') {
                $chatId = $s['value'] !== '' ? $s['value'] : null;
            }
            if ($s['key'] === 'telegram_alerts_enabled') {
                $enabled = $s['value'] === '1';
            }
            if ($s['key'] === 'telegram_chat_id_sales') {
                $deptChatIds['sales'] = $s['value'] !== '' ? $s['value'] : null;
            }
            if ($s['key'] === 'telegram_chat_id_technical') {
                $deptChatIds['technical'] = $s['value'] !== '' ? $s['value'] : null;
            }
            if ($s['key'] === 'telegram_chat_id_accounts') {
                $deptChatIds['accounts'] = $s['value'] !== '' ? $s['value'] : null;
            }
        }

        return ['bot_token' => $token, 'chat_id' => $chatId, 'enabled' => $enabled, 'dept_chat_ids' => $deptChatIds];
    }

    // Which chat a given department's alerts actually go to: its own
    // override if one is configured, otherwise the shared default -
    // callers never need to know whether per-department routing is set up.
    private static function resolveChatId(array $cfg, ?string $department): ?string
    {
        if ($department !== null && !empty($cfg['dept_chat_ids'][$department])) {
            return $cfg['dept_chat_ids'][$department];
        }
        return $cfg['chat_id'];
    }

    private static function deptLabel(?string $department): string
    {
        return match ($department) {
            'sales'     => 'Sales',
            'technical' => 'Technical',
            'accounts'  => 'Accounts',
            default     => 'General',
        };
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

    public static function send(string $text, ?string $tokenOverride = null, ?string $chatIdOverride = null, ?array $replyMarkup = null): array
    {
        $needsLookup = $tokenOverride === null || $chatIdOverride === null;
        $cfg    = $needsLookup ? self::getConfig() : null;
        $token  = $tokenOverride  ?? ($cfg['bot_token'] ?? null);
        $chatId = $chatIdOverride ?? ($cfg['chat_id'] ?? null);

        if (!$token || !$chatId) {
            return ['ok' => false, 'error' => 'Bot token and chat ID are both required'];
        }

        $fields = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $fields['reply_markup'] = json_encode($replyMarkup);
        }

        $ch = curl_init(self::API_BASE . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
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

    // Inline keyboard attached to a ticket alert: buttons to forward to
    // whichever departments it ISN'T currently in, plus a mailto: link so
    // "reply by email" (the agreed answer for how staff actually reply -
    // no in-app sending) is one tap away on mobile too, not just a visible
    // address someone has to copy. Returns null (no reply_markup at all)
    // rather than an empty keyboard when there's nothing to show.
    // Public specifically so this pure logic (which departments to offer,
    // whether to include the mailto button) is directly testable without
    // needing to mock the Telegram API the rest of this class talks to.
    public static function buildTicketKeyboard(int $ticketId, ?string $currentDepartment, ?string $email, string $subject): ?array
    {
        $rows = [];

        $forwardRow = [];
        foreach (self::DEPARTMENTS as $dept) {
            if ($dept === $currentDepartment) {
                continue;
            }
            $forwardRow[] = ['text' => '→ ' . self::deptLabel($dept), 'callback_data' => "fwd:{$ticketId}:{$dept}"];
        }
        if ($forwardRow) {
            $rows[] = $forwardRow;
        }

        if ($email) {
            $mailto = 'mailto:' . $email . '?subject=' . rawurlencode('Re: ' . ($subject !== '' ? $subject : 'your enquiry'));
            $rows[] = [['text' => '✉️ Reply by email', 'url' => $mailto]];
        }

        return $rows ? ['inline_keyboard' => $rows] : null;
    }

    // ── Ticket alert ──────────────────────────────────────────────────────────
    // Called from escalate.php right after a support ticket is inserted.
    // Deliberately swallows everything: a broken/unconfigured/rate-limited
    // Telegram integration must never stop a customer's ticket from being
    // created or their confirmation message from being returned.

    public static function sendTicketAlert(int $ticketId, string $subject, ?string $email, ?string $pageUrl, ?string $department = null): void
    {
        try {
            $cfg    = self::getConfig();
            $chatId = self::resolveChatId($cfg, $department);
            if (!$cfg['enabled'] || !$cfg['bot_token'] || !$chatId) {
                return; // not configured, or deliberately silenced - not an error
            }

            $lines = [
                '🎫 <b>New support ticket #' . $ticketId . '</b> — ' . self::deptLabel($department),
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

            $keyboard = self::buildTicketKeyboard($ticketId, $department, $email, $subject);
            $result   = self::send(implode("\n", $lines), $cfg['bot_token'], $chatId, $keyboard);
            if (!$result['ok']) {
                error_log('Telegram ticket alert failed: ' . $result['error']);
            }
        } catch (\Throwable $e) {
            error_log('Telegram ticket alert exception: ' . $e->getMessage());
        }
    }

    // Fires when an admin moves a ticket to a different department (see
    // public/api/admin/tickets.php's PUT handler) - the AI's routing guess
    // on creation above isn't always right, and the department that ends up
    // owning it needs to actually notice it landed in their queue, not just
    // find it later. Goes to the RECEIVING department's chat (falling back
    // to the shared one, same as ticket creation); the losing department
    // doesn't need a separate alert, it's off their plate.
    public static function sendDepartmentChangeAlert(int $ticketId, string $subject, ?string $fromDepartment, string $toDepartment, ?string $email = null): void
    {
        try {
            $cfg    = self::getConfig();
            $chatId = self::resolveChatId($cfg, $toDepartment);
            if (!$cfg['enabled'] || !$cfg['bot_token'] || !$chatId) {
                return;
            }

            $lines = [
                '🔀 <b>Ticket #' . $ticketId . ' reassigned to ' . self::deptLabel($toDepartment) . '</b>'
                    . ' (was: ' . self::deptLabel($fromDepartment) . ')',
                htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
                '',
                '<a href="' . self::ADMIN_URL . '">Open in admin</a>',
            ];

            $keyboard = self::buildTicketKeyboard($ticketId, $toDepartment, $email, $subject);
            $result   = self::send(implode("\n", $lines), $cfg['bot_token'], $chatId, $keyboard);
            if (!$result['ok']) {
                error_log('Telegram department-change alert failed: ' . $result['error']);
            }
        } catch (\Throwable $e) {
            error_log('Telegram department-change alert exception: ' . $e->getMessage());
        }
    }

    // Fires when a customer asks to talk to a person right now (see
    // Chat\LiveChat::requestLive()) - urgent by nature, so styled and
    // worded differently from a normal ticket alert. No "claim" button
    // here deliberately: claiming only makes sense from the admin panel
    // or operator console, where there's actually somewhere to type a
    // reply - a Telegram-only claim would let someone grab it and then
    // have no way to talk to the customer. Forward buttons still apply
    // (the AI's department guess can still be wrong here too); there's
    // no mailto button since a live-chat request has no email attached
    // (nothing to reply to later - the customer is waiting right now).
    public static function sendLiveChatAlert(int $ticketId, string $subject, ?string $pageUrl, ?string $department = null): void
    {
        try {
            $cfg    = self::getConfig();
            $chatId = self::resolveChatId($cfg, $department);
            if (!$cfg['enabled'] || !$cfg['bot_token'] || !$chatId) {
                return;
            }

            $lines = [
                '🔴 <b>Live chat requested — #' . $ticketId . '</b> — ' . self::deptLabel($department),
                htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
                '',
                'A customer is waiting right now.',
                '<a href="' . self::ADMIN_URL . '">Open in admin to claim</a>',
            ];

            $keyboard = self::buildTicketKeyboard($ticketId, $department, null, $subject);
            $result   = self::send(implode("\n", $lines), $cfg['bot_token'], $chatId, $keyboard);
            if (!$result['ok']) {
                error_log('Telegram live-chat alert failed: ' . $result['error']);
            }
        } catch (\Throwable $e) {
            error_log('Telegram live-chat alert exception: ' . $e->getMessage());
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

    // ── Update polling (button taps) ─────────────────────────────────────────
    // Called from scripts/process_telegram_updates.php, not a request path,
    // so no timeout pressure the way sendTicketAlert() has - still kept
    // short since this runs every minute regardless. $offset is Telegram's
    // own update_id cursor; the caller persists wherever it gets to so nothing
    // is processed twice. allowed_updates narrows what Telegram bothers
    // sending back to just what this actually handles.
    public static function pollUpdates(string $token, int $offset): array
    {
        $url = self::API_BASE . $token . '/getUpdates?' . http_build_query([
            'offset'          => $offset,
            'timeout'         => 0,
            'allowed_updates' => json_encode(['callback_query']),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
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
        return $data['result'];
    }

    // Clears the "loading" spinner Telegram shows on a tapped button and
    // surfaces a short confirmation toast - required within a reasonable
    // time of any callback_query or the tap just looks broken client-side.
    public static function answerCallbackQuery(string $token, string $callbackId, string $text): void
    {
        $ch = curl_init(self::API_BASE . $token . '/answerCallbackQuery');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['callback_query_id' => $callbackId, 'text' => $text]),
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // Strips (or replaces) the buttons on an already-sent alert once its
    // action has been taken - a second person tapping the same now-stale
    // "forward to Sales" after someone already did shouldn't be possible.
    // Pass null to remove the keyboard entirely.
    public static function editMessageReplyMarkup(string $token, string $chatId, int $messageId, ?array $keyboard): void
    {
        $ch = curl_init(self::API_BASE . $token . '/editMessageReplyMarkup');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'reply_markup' => json_encode($keyboard ?? ['inline_keyboard' => []]),
            ]),
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
