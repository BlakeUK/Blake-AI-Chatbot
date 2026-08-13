<?php
// scripts/ensure_check_tool_config.php
// Idempotently adds the /check/ login credential to an EXISTING
// config/config.php - deploy_remote.sh only generates config.php once,
// on first install (see its own "Config already exists — skipping"
// guard), so a key added to config.example.php after that first install
// never reaches an already-deployed server without an explicit step
// like this one. Run on every deploy; does nothing once the key is present.
//
// Only the bcrypt hash is committed here, never the plaintext - a
// strong, high-entropy password's bcrypt hash isn't practically
// crackable offline even with full read access to this script (same
// reasoning the Caddyfile's own basic_auth setup used to follow before
// this replaced it with a proper session login).

declare(strict_types=1);

$path = dirname(__DIR__) . '/config/config.php';
if (!file_exists($path)) {
    fwrite(STDERR, "config/config.php does not exist yet — nothing to update.\n");
    exit(0);
}

$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Could not read config/config.php\n");
    exit(1);
}

if (str_contains($content, 'check_tool_username')) {
    echo "check_tool_username already present — skipping.\n";
    exit(0);
}

$addition = "    'check_tool_username'      => 'BlakeUK',\n"
          . "    'check_tool_password_hash' => '\$2y\$12\$PHA4pOBPj5UnShrSyjUha.PIRvSkTS8WI9SRBzwGeLajByJgyAO56',\n";

$pos = strrpos($content, '];');
if ($pos === false) {
    fwrite(STDERR, "Could not find the closing '];' in config/config.php — not modified.\n");
    exit(1);
}

file_put_contents($path, substr($content, 0, $pos) . $addition . substr($content, $pos));
echo "Added /check/ login credentials to config/config.php\n";
