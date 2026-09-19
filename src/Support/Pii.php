<?php
// src/Support/Pii.php
// Masks customer personal data before text is sent to the AI model
// (Confluent RAG guide: filter PII out of what reaches the LLM). The
// original text is still stored in chat_messages for staff; only the copy
// in the model prompt is masked. Blake UK's own contact details in the
// knowledge base are not passed through this.

declare(strict_types=1);

namespace Support;

class Pii
{
    public static function mask(string $text): string
    {
        // Email addresses
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email address]', $text);
        // Payment card numbers (13-19 digits, optional spaces/dashes)
        $text = preg_replace('/\b(?:\d[ \-]?){12,18}\d\b/', '[card number]', $text);
        // UK phone numbers: +44 / 0 followed by 9-10 more digits
        $text = preg_replace('/(?<![\w\d])(?:\+44\s?\(?0?\)?|0)(?:[\s\-()]*\d){9,10}(?![\d])/', '[phone number]', $text);
        return $text;
    }
}
