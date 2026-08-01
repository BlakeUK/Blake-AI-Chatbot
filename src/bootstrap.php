<?php
// src/bootstrap.php — loaded by every public endpoint

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$cfg = require ROOT . '/config/config.php';
define('CFG', $cfg);

// ── Autoload (simple PSR-4 style without Composer) ───────────────────────────
spl_autoload_register(function (string $class): void {
    $base = ROOT . '/src/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// ── Database ──────────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . CFG['db_path']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    return $pdo;
}

// ── JSON helpers ──────────────────────────────────────────────────────────────
function json_out(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $msg, int $code = 400): never {
    json_out(['error' => $msg], $code);
}

function json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : [];
}

// ── CORS ──────────────────────────────────────────────────────────────────────
function cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && (in_array($origin, CFG['cors_origins'], true) || widget_origin_allowed($origin))) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Extends CORS to any origin an active widget client is configured to allow —
// same allowlist widget/init.php already enforces before issuing a token, so
// this doesn't widen access, it just lets the browser read the response once
// a request has already earned a valid token under that same policy.
function widget_origin_allowed(string $origin): bool {
    $stmt = db()->query('SELECT allowed_origins FROM widget_clients WHERE active = 1');
    foreach ($stmt->fetchAll() as $row) {
        $list = json_decode($row['allowed_origins'] ?? '[]', true) ?: [];
        if (empty($list) || in_array($origin, $list, true)) {
            return true;
        }
    }
    return false;
}

// ── Rate limiting ─────────────────────────────────────────────────────────────
function rate_limit(string $endpoint, int $limit): void {
    $ip   = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $win  = (int)(time() / 60);
    $pdo  = db();

    $pdo->exec('DELETE FROM rate_limits WHERE window_start < ' . ($win - 1));

    $stmt = $pdo->prepare('
        INSERT INTO rate_limits (ip_hash, endpoint, window_start, count)
        VALUES (?, ?, ?, 1)
        ON CONFLICT(ip_hash, endpoint, window_start)
        DO UPDATE SET count = count + 1
    ');
    $stmt->execute([$ip, $endpoint, $win]);

    $row = $pdo->prepare('SELECT count FROM rate_limits WHERE ip_hash=? AND endpoint=? AND window_start=?');
    $row->execute([$ip, $endpoint, $win]);
    if ((int)($row->fetchColumn() ?: 0) > $limit) {
        json_err('Rate limit exceeded', 429);
    }
}
