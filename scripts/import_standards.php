<?php
// scripts/import_standards.php - loads scripts/standards/chunks.json.gz into
// the technical standards tier when it has changed (run on every deploy;
// no-op otherwise). The embeddings cron then indexes the new chunks.
require dirname(__DIR__) . '/src/bootstrap.php';
$path = ROOT . '/scripts/standards/chunks.json.gz';
if (!is_file($path)) { echo "No standards bundle.\n"; exit(0); }
$sha = sha1_file($path);
$cur = db()->query("SELECT value FROM settings WHERE key = 'standards_sha'")->fetchColumn();
$have = (int)db()->query("SELECT COUNT(*) FROM standards_documents")->fetchColumn();
if ($cur === $sha && $have > 0 && !in_array('--force', $argv, true)) { echo "Standards up to date.\n"; exit(0); }
$r = \Knowledge\Standards::import($path);
db()->prepare("INSERT INTO settings (key,value,updated_at) VALUES ('standards_sha',?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")->execute([$sha, time()]);
echo "Imported {$r['documents']} standards, {$r['chunks']} clause chunks.\n";
