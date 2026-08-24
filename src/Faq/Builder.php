<?php
// src/Faq/Builder.php
// Auto-builds the FAQ table from real chat exchanges. Called from
// public/api/chat/send.php after an answer is judged grounded (see
// Chat\Responder::confidence()/shouldEscalate()) - an escalated or
// low-confidence exchange never reaches capture(), so the FAQ list only
// ever fills up with answers the bot was actually able to ground in
// knowledge/product/keyword-link context, not guesses.

declare(strict_types=1);

namespace Faq;

class Builder
{
    // A candidate only merges into an existing entry if this fraction of
    // its significant words overlap with the existing entry's - high
    // enough that two questions sharing one common word ("returns" inside
    // both a returns-policy question and an unrelated one) don't collapse
    // together, low enough that real rephrasings ("how long does delivery
    // take" / "what are your delivery times") still match.
    private const MATCH_THRESHOLD = 0.6;

    // Same list Knowledge\Search::naturalLanguageMatch() filters on for
    // customer chat queries. Kept as its own small copy rather than reusing
    // that (private) list - the two call sites have no other coupling and
    // this list is short and stable enough that duplicating it is simpler
    // than widening Search's API just for this.
    private const STOPWORDS = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'am', 'be', 'been', 'being',
        'do', 'does', 'did', 'doing', 'have', 'has', 'had', 'having',
        'i', 'me', 'my', 'we', 'our', 'us', 'you', 'your', 'yours',
        'it', 'its', 'this', 'that', 'these', 'those',
        'what', 'who', 'whom', 'which', 'when', 'where', 'why', 'how',
        'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with', 'about', 'from', 'into', 'as', 'than',
        'and', 'or', 'but', 'if', 'so', 'not', 'no',
        'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'must',
        'please', 'hi', 'hello', 'hey', 'thanks', 'thank',
    ];

    // Either bumps an existing near-duplicate entry's hit_count, or creates
    // a new one. Deliberately never rewrites an existing entry's
    // question/answer text - once an entry exists, its wording is stable
    // (whether that's the first auto-captured answer or something an admin
    // has since hand-edited via the admin FAQ tab), so a later, differently
    // phrased Gemini answer to the same underlying question can't silently
    // drift or revert an admin's edit. Editing text is the admin's job
    // (public/api/admin/faq.php); this only ever tracks how often a
    // question comes up.
    public static function capture(string $question, string $answer, int $messageId): void
    {
        $question = trim($question);
        $answer   = trim($answer);
        if ($question === '' || $answer === '' || mb_strlen($question) > 500) {
            return;
        }
        // A one-off answer built around this specific customer's order
        // number, email, or postcode has no business becoming a public FAQ
        // entry - even though tracking questions are short-circuited before
        // this point (see send.php), a customer can still paste this kind
        // of thing into an otherwise general question.
        if (self::looksPersonal($question) || self::looksPersonal($answer)) {
            return;
        }

        $norm = self::normalise($question);
        if ($norm === '') {
            return;
        }

        $pdo        = db();
        $existingId = self::findMatch($norm);

        if ($existingId) {
            $pdo->prepare('UPDATE faq_entries SET hit_count = hit_count + 1, last_message_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$messageId, time(), $existingId]);
            return;
        }

        $pdo->prepare('
            INSERT INTO faq_entries (question, question_norm, answer, hit_count, first_message_id, last_message_id)
            VALUES (?, ?, ?, 1, ?, ?)
        ')->execute([$question, $norm, $answer, $messageId, $messageId]);
    }

    // Top entries for the widget's quick-question chips. Deliberately no
    // minimum hit_count - with a thin history that just returns the most
    // recent grounded questions, which is still a reasonable starting set;
    // as real usage accumulates, genuinely popular questions naturally sort
    // to the top ahead of them.
    public static function top(int $limit = 6): array
    {
        $limit = max(1, min($limit, 20));
        $stmt  = db()->prepare('SELECT id, question, answer, hit_count FROM faq_entries ORDER BY hit_count DESC, id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private static function findMatch(string $norm): ?int
    {
        $pdo = db();

        // 1) Exact normalised match - same question, different
        // capitalisation/punctuation. The common case.
        $stmt = $pdo->prepare('SELECT id FROM faq_entries WHERE question_norm = ? LIMIT 1');
        $stmt->execute([$norm]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }

        // 2) Near-duplicate via FTS5 candidate retrieval + word-overlap
        // check - catches rephrasings an exact match would miss, without
        // merging genuinely different questions that just share a keyword.
        $terms = self::significantWords($norm);
        if (!$terms) {
            return null;
        }

        $ftsQuery = implode(' OR ', array_map(fn($w) => '"' . str_replace('"', '""', $w) . '"', $terms));
        $stmt = $pdo->prepare('
            SELECT fe.id, fe.question_norm
            FROM faq_fts
            JOIN faq_entries fe ON fe.id = faq_fts.rowid
            WHERE faq_fts MATCH ?
            ORDER BY rank
            LIMIT 5
        ');
        $stmt->execute([$ftsQuery]);

        foreach ($stmt->fetchAll() as $row) {
            $overlap = self::overlap($terms, self::significantWords($row['question_norm']));
            if ($overlap >= self::MATCH_THRESHOLD) {
                return (int)$row['id'];
            }
        }
        return null;
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        return trim($text);
    }

    private static function significantWords(string $norm): array
    {
        $words = array_filter(
            $norm === '' ? [] : explode(' ', $norm),
            fn($w) => $w !== '' && !in_array($w, self::STOPWORDS, true)
        );
        return array_values($words);
    }

    // Containment, not Jaccard: what fraction of the SMALLER question's
    // significant words also appear in the other one. A longer, more
    // verbose rephrasing that fully contains a shorter question's key
    // terms should match even though it also adds new words of its own -
    // plain intersection-over-union punishes exactly that case, since the
    // union keeps growing with every extra word the rephrasing adds.
    // Guarded to require at least 2 significant words on both sides, or a
    // single shared keyword between a very short question and anything
    // that happens to contain it would score a false 100%.
    private static function overlap(array $a, array $b): float
    {
        if (count($a) < 2 || count($b) < 2) {
            return 0.0;
        }
        $setA = array_unique($a);
        $setB = array_unique($b);
        $minSize = min(count($setA), count($setB));
        return $minSize ? count(array_intersect($setA, $setB)) / $minSize : 0.0;
    }

    // Cheap, deliberately conservative pattern checks - not trying to catch
    // everything personal, just the common shapes (email address, a long
    // digit run that's almost certainly an order/tracking number, a UK
    // postcode) that have no business in a page any customer can read.
    private static function looksPersonal(string $text): bool
    {
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $text)) {
            return true;
        }
        if (preg_match('/\d{8,}/', $text)) {
            return true;
        }
        if (preg_match('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', $text)) {
            return true;
        }
        return false;
    }
}
