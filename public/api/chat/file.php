<?php
// public/api/chat/file.php?t=<token> - serves a knowledge file an admin has
// marked "offer as download" (Knowledge\Downloads). Nothing else in the
// uploads folder is reachable: unmarked files, unknown tokens -> 404.
require dirname(__DIR__, 3) . '/src/bootstrap.php';

$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $t)) { http_response_code(404); exit('Not found'); }
$s = db()->prepare("SELECT filename, mime_type, stored_path FROM knowledge_files WHERE download_token = ? AND public_download = 1");
$s->execute([$t]);
$f = $s->fetch();
$path = $f ? realpath((string)$f['stored_path']) : false;
$uploads = realpath(CFG['upload_path']);
if (!$f || !$path || !is_file($path) || !$uploads || !str_starts_with($path, $uploads)) { http_response_code(404); exit('Not found'); }

$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string)$f['filename']));
header('Content-Type: ' . ($f['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (str_contains((string)$f['mime_type'], 'pdf') ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
