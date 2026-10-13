<?php
// Loaded before the site by tests/e2e/teamchat_server.php so the AI gives a fixed answer without calling Gemini.
require_once dirname(__DIR__, 2) . '/src/Chat/TeamAi.php';
\Chat\TeamAi::$llm = function (string $system, string $conversation): string {
    return 'Max here: ' . (preg_match('/ANSWER THIS MESSAGE: [^:]*: (.*)$/s', $conversation, $m) ? trim($m[1]) : '?');
};
