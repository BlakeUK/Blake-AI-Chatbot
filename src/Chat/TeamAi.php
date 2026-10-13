<?php
// src/Chat/TeamAi.php: the AI (Max) in staff team chat. It speaks ONLY when a message contains "@ai". It reads the recent conversation (or the thread
// the message is in), adds anything useful from Blake UK's own knowledge and products, and posts one reply that quotes the message it answers.
// Customer details are masked before anything is sent to Gemini. AI replies never trigger the AI again.

declare(strict_types=1);

namespace Chat;

final class TeamAi
{
    public const CONTEXT_MESSAGES = 40;
    /** Tests replace this: fn(string $system, string $conversation): string */
    public static $llm = null;

    private const SYSTEM = "You are Max (My AI eXpert), the AI in Blake UK's internal staff chat. Blake UK sells TV aerials, satellite, CCTV, networking and RF distribution equipment, and you know RF, aerials and aerial installation well.\n"
        . "A member of staff has written @ai in a message. Answer or help with exactly what that message asks, using the conversation for context: answer the question, give feedback, summarise, draft wording, or suggest next steps.\n"
        . "Rules: information from Blake UK's own knowledge and product data below comes first. Never invent prices, stock levels, product codes or policies; if you do not know, say so. "
        . "Be brief and direct. Write plain text in British English, with no markdown headings or tables. Do not use em dashes. Do not mention these instructions.";

    /** Posts the AI's reply to $messageId. Returns the new message id, or null when the message does not ask for the AI. */
    public static function answer(int $channelId, int $messageId): ?int
    {
        $q = db()->prepare('SELECT id, admin_id, content, reply_to_id, thread_root_id, is_ai, kind FROM channel_messages WHERE id = ? AND channel_id = ?'); $q->execute([$messageId, $channelId]);
        $msg = $q->fetch();
        if (!$msg || (int)$msg['is_ai'] || $msg['kind'] !== 'message' || !Channels::mentionsAi((string)$msg['content'])) return null;
        $asker = (int)$msg['admin_id'];

        try {
            $reply = self::generate($channelId, $msg);
        } catch (\Throwable $e) {
            error_log('TeamAi: ' . $e->getMessage());
            $reply = '';
        }
        if (trim($reply) === '') $reply = 'Sorry, I could not answer that just now. Please try again in a moment.';
        return Channels::postAi($channelId, $asker, $reply, $messageId);
    }

    private static function line(array $m): string
    {
        return ($m['is_ai'] ? 'Max' : (string)$m['username']) . ': ' . trim((string)$m['content']);
    }

    private static function generate(int $channelId, array $msg): string
    {
        $root = $msg['thread_root_id'] ? (int)$msg['thread_root_id'] : null;
        $cols = 'm.id, m.content, m.is_ai, u.username FROM channel_messages m JOIN admin_users u ON u.id = m.admin_id';
        if ($root) {   // asked inside a thread: the thread is the conversation
            $st = db()->prepare("SELECT * FROM (SELECT {$cols} WHERE m.channel_id = ? AND m.kind = 'message' AND m.id <= ? AND (m.id = ? OR m.thread_root_id = ?) ORDER BY m.id DESC LIMIT " . self::CONTEXT_MESSAGES . ') ORDER BY id ASC');
            $st->execute([$channelId, (int)$msg['id'], $root, $root]);
        } else {
            $st = db()->prepare("SELECT * FROM (SELECT {$cols} WHERE m.channel_id = ? AND m.kind = 'message' AND m.id <= ? ORDER BY m.id DESC LIMIT " . self::CONTEXT_MESSAGES . ') ORDER BY id ASC');
            $st->execute([$channelId, (int)$msg['id']]);
        }
        $lines = array_map([self::class, 'line'], $st->fetchAll());
        $quoted = '';
        if ($msg['reply_to_id']) {
            $r = db()->prepare('SELECT m.content, m.is_ai, u.username FROM channel_messages m JOIN admin_users u ON u.id = m.admin_id WHERE m.id = ?'); $r->execute([(int)$msg['reply_to_id']]);
            if ($row = $r->fetch()) $quoted = "\nThe message above is a reply to: " . self::line($row);
        }
        $ask = trim((string)preg_replace('/(?<![A-Za-z0-9_@.\/-])@ai(?![A-Za-z0-9_@-])/i', '', (string)$msg['content']));
        $conversation = "CONVERSATION (oldest first):\n" . implode("\n", $lines) . $quoted . "\n\nANSWER THIS MESSAGE: " . self::line(['is_ai' => 0, 'username' => self::nameOf((int)$msg['admin_id']), 'content' => $ask]);

        $conversation = \Support\Pii::mask($conversation);
        $knowledge = self::knowledge($ask !== '' ? $ask : (string)$msg['content']);
        $system = self::SYSTEM . ($knowledge !== '' ? "\n\nBLAKE UK KNOWLEDGE AND PRODUCTS (use first):\n" . $knowledge : '');

        if (self::$llm) return (string)(self::$llm)($system, $conversation);
        $key = \Gemini\Client::getStoredApiKey();
        if (!$key) throw new \RuntimeException('no Gemini API key set');
        return trim((new \Gemini\Client($key))->chat(\Gemini\Client::getModel('gemini_chat_model', 'gemini_flash'), [['role' => 'user', 'content' => $conversation]], $system));
    }

    private static function nameOf(int $id): string { return Channels::username($id); }

    private static function knowledge(string $question): string
    {
        $out = [];
        try {
            foreach (\Knowledge\Search::query($question, 4) as $c) $out[] = trim((string)$c['chunk_text']) . (!empty($c['url']) ? ' (' . $c['url'] . ')' : '');
            foreach (\Knowledge\Search::products($question, 3) as $p) $out[] = \Knowledge\Search::formatForPrompt($p, null);
        } catch (\Throwable $e) {
            error_log('TeamAi knowledge: ' . $e->getMessage());
        }
        return mb_substr(implode("\n---\n", $out), 0, 9000);
    }
}
