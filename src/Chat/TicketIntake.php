<?php
// src/Chat/TicketIntake.php
// When nobody from the team answers a handed-off chat within 3 minutes,
// or the customer asks for help outside opening hours, Max apologises and
// takes the details for a support ticket, one question at a time:
//   1. name
//   2. email address (or a telephone number if they have no email)
//   3. anything else relevant (order number, product, details) - optional
// then raises the ticket (Handoff::createTicket), which emails the support
// inbox and the customer with the ticket number, and hands the chat back
// to Max. A staff member can still accept the chat at any point.

declare(strict_types=1);

namespace Chat;

class TicketIntake
{
    private const NOTHING_MORE = '/^\s*(no|nope|none|nothing( else)?|n\/?a|that\'?s (it|all|everything)|no thanks?|no,? that\'?s (it|all|everything)|all good|thats all|nah)[\s.!]*$/i';

    public static function begin(string $sessionId, string $reason, ?int $now = null): bool
    {
        $session = Handoff::session($sessionId);
        if (!$session) return false;
        $state = [
            'reason' => $reason,
            'name'   => $session['customer_name'] ?: null,
            'email'  => $session['customer_email'] ?: null,
            'phone'  => $session['customer_phone'] ?: null,
            'extra'  => null,
            'step'   => null,
        ];
        $upd = db()->prepare("UPDATE chat_sessions SET mode = 'intake', intake = ?, updated_at = ? WHERE id = ? AND mode IN ('ai','live_requested','live_ended')");
        $upd->execute([json_encode($state), $now ?? time(), $sessionId]);
        if ($upd->rowCount() === 0) return false;

        if ($reason === 'closed') {
            $intro = "Our support team is closed at the moment. Our opening hours are " . \Support\Hours::SUMMARY
                   . ". I'll raise a support ticket so the team can get back to you " . \Support\Hours::nextOpening($now) . '.';
        } else {
            $intro = "I'm sorry, our team are all busy helping other customers at the moment. So they can get back to you as soon as possible, I'll raise a support ticket for you.";
        }
        Handoff::say($sessionId, 'bot', $intro);
        self::askNext($sessionId, $state);
        return true;
    }

    // A customer message while in intake mode.
    public static function handle(string $sessionId, string $text): array
    {
        $session = Handoff::session($sessionId);
        if (!$session || $session['mode'] !== 'intake') return ['ok' => false, 'error' => 'Not collecting ticket details'];
        $state = json_decode((string)$session['intake'], true) ?: ['step' => 'name'];
        $text  = trim($text);

        // Details offered out of turn are always picked up.
        $email = self::findEmail($text);
        $phone = self::findPhone($text);
        if ($email) $state['email'] = $email;
        if ($phone) $state['phone'] = $phone;

        switch ($state['step'] ?? 'name') {
            case 'name':
                $name = self::cleanName($text);
                if ($name === '') {
                    Handoff::say($sessionId, 'bot', "Sorry, I didn't catch your name. Could you tell me your name, please?");
                    self::save($sessionId, $state);
                    return ['ok' => true];
                }
                $state['name'] = $name;
                break;
            case 'contact':
                if (!$email && !$phone) {
                    Handoff::say($sessionId, 'bot', "Sorry, that doesn't look like an email address or phone number. What's the best email address for us to reply to? If you don't have one, a telephone number is fine.");
                    self::save($sessionId, $state);
                    return ['ok' => true];
                }
                break;
            case 'extra':
                $state['extra'] = preg_match(self::NOTHING_MORE, $text) ? '' : $text;
                break;
        }
        return self::askNext($sessionId, $state);
    }

    private static function askNext(string $sessionId, array $state): array
    {
        if (empty($state['name'])) {
            $state['step'] = 'name';
            Handoff::say($sessionId, 'bot', 'Could I take your name, please?');
        } elseif (empty($state['email']) && empty($state['phone'])) {
            $state['step'] = 'contact';
            Handoff::say($sessionId, 'bot', "Thanks, {$state['name']}. What's the best email address for us to reply to? If you don't have one, a telephone number is fine.");
        } elseif ($state['extra'] === null) {
            $state['step'] = 'extra';
            Handoff::say($sessionId, 'bot', 'Is there anything else our team should know, such as an order number, a product code or more detail about the problem? If not, just say no.');
        } else {
            return self::complete($sessionId, $state);
        }
        self::save($sessionId, $state);
        return ['ok' => true];
    }

    private static function complete(string $sessionId, array $state): array
    {
        $summary = self::summarise($sessionId);
        $details = $summary['summary'] . ($state['extra'] ? "\n\nAdditional information from the customer: {$state['extra']}" : '');
        $res = Handoff::createTicket($sessionId, null, [
            'subject' => $summary['subject'],
            'details' => $details,
            'name'    => $state['name'],
            'email'   => $state['email'] ?? '',
            'phone'   => $state['phone'] ?? '',
        ]);
        if (!$res['ok']) {
            Handoff::say($sessionId, 'bot', 'Sorry, I could not raise the ticket just now. Please email sales@blake-uk.com and our team will help.');
            return $res;
        }
        db()->prepare("UPDATE chat_sessions SET mode = 'ai', intake = NULL, handoff_at = NULL, target_admin_id = NULL, updated_at = ? WHERE id = ?")->execute([time(), $sessionId]);

        $code = $res['code'];
        $when = \Support\Hours::isOpen() ? 'as soon as possible' : \Support\Hours::nextOpening();
        if (!empty($state['email'])) {
            $msg = "Thank you, {$state['name']}. I've raised support ticket {$code} and a confirmation is on its way to {$state['email']}. Our team will get back to you {$when}.";
        } else {
            $msg = "Thank you, {$state['name']}. I've raised support ticket {$code}. Our team will call you on {$state['phone']} {$when}.";
        }
        Handoff::say($sessionId, 'bot', $msg . " Please quote {$code} if you contact us about this. Is there anything else I can help with in the meantime?");
        return ['ok' => true, 'ticket_id' => $res['ticket_id'], 'code' => $code];
    }

    private static function save(string $sessionId, array $state): void
    {
        db()->prepare('UPDATE chat_sessions SET intake = ?, updated_at = ? WHERE id = ?')->execute([json_encode($state), time(), $sessionId]);
    }

    public static function findEmail(string $text): ?string
    {
        return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) ? strtolower($m[0]) : null;
    }

    // UK-style numbers: 07..., 01.../02..., +44 ..., 10-11 digits once cleaned.
    public static function findPhone(string $text): ?string
    {
        if (!preg_match('/(\+?44[\s\-]?\(?0?\)?|0)[\d\s\-()]{8,14}\d/', $text, $m)) return null;
        $digits = preg_replace('/\D/', '', $m[0]);
        if (str_starts_with($digits, '44')) $digits = '0' . substr($digits, 2);
        $digits = preg_replace('/^00/', '0', $digits);
        return (strlen($digits) >= 10 && strlen($digits) <= 11) ? $digits : null;
    }

    public static function cleanName(string $text): string
    {
        $t = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', ' ', $text);
        $t = preg_replace('/(\+?44|0)[\d\s\-()]{8,14}\d/', ' ', $t);
        $t = preg_replace('/^\s*(hi|hello|hey)[,!.\s]+/i', '', $t);
        $t = preg_replace('/^\s*(my name is|my name\'?s|name\'?s|name is|i am|i\'m|im|it\'?s|this is|call me)\s+/i', '', $t);
        $t = preg_replace("/[^\\p{L}\\s'\\-.]/u", ' ', $t);
        $words = preg_split('/\s+/', trim($t, " \t\n.-'"), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words, fn($w) => !preg_match('/^(and|my|email|is|phone|number|thanks|thank|you|please)$/i', $w)));
        if (!$words || count($words) > 5) return '';
        return implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), array_slice($words, 0, 4)));
    }

    // Subject line and short summary of the customer's issue from the
    // conversation (Gemini, with a plain fallback).
    public static function summarise(string $sessionId): array
    {
        $s = db()->prepare("SELECT role, content FROM chat_messages WHERE session_id = ? AND role IN ('user','assistant','agent') ORDER BY id");
        $s->execute([$sessionId]);
        $msgs = $s->fetchAll();
        $userMsgs = array_values(array_map(fn($m) => $m['content'], array_filter($msgs, fn($m) => $m['role'] === 'user')));
        $fallback = [
            'subject' => mb_substr($userMsgs[0] ?? 'Support request', 0, 100),
            'summary' => $userMsgs ? 'Customer wrote: ' . implode(' / ', array_slice($userMsgs, 0, 6)) : 'Support request from website chat.',
        ];
        $key = \Gemini\Client::getStoredApiKey();
        if (!$key || !$msgs) return $fallback;
        $transcript = '';
        foreach (array_slice($msgs, -20) as $m) {
            $text = $m['role'] === 'user' ? \Support\Pii::mask($m['content']) : $m['content'];
            $transcript .= ($m['role'] === 'user' ? 'Customer' : 'Blake UK') . ': ' . mb_substr($text, 0, 600) . "\n";
        }
        try {
            $raw = (new \Gemini\Client($key))->chat(
                \Gemini\Client::getModel('gemini_chat_model', 'gemini_flash'),
                [['role' => 'user', 'content' => "Summarise this website support chat for a support ticket. Reply with JSON only: {\"subject\": \"max 10 words\", \"summary\": \"1-3 sentences describing what the customer needs, including any product, order or technical details they gave\"}\n\n{$transcript}"]]
            );
            $j = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $raw)), true);
            if (is_array($j) && !empty($j['subject']) && !empty($j['summary'])) {
                return ['subject' => mb_substr(trim($j['subject']), 0, 120), 'summary' => trim($j['summary'])];
            }
        } catch (\Throwable $e) {}
        return $fallback;
    }
}
