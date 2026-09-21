<?php
// scripts/setup_downloads.php - makes sure known customer forms are offered
// as downloads (idempotent; run on deploy). Admins can change or add more in
// Admin > Files / RAG.
require dirname(__DIR__) . '/src/bootstrap.php';
$known = [
    'Blake_UK_Application_for_Trading_Account%' => [
        'Trade Account Application Form and Terms & Conditions',
        'trade account, trading account, account application, apply for an account, open an account, credit account, business account, account form, application form, terms and conditions, t&c',
    ],
];
foreach ($known as $like => [$title, $kw]) {
    $s = db()->prepare("SELECT id, public_download FROM knowledge_files WHERE filename LIKE ? AND status = 'indexed' ORDER BY id DESC LIMIT 1");
    $s->execute([$like]);
    $f = $s->fetch();
    if (!$f) { echo "Download not uploaded yet: {$like}\n"; continue; }
    if ((int)$f['public_download'] === 1) { echo "Download already set: {$title}\n"; continue; }
    \Knowledge\Downloads::set((int)$f['id'], true, $title, $kw);
    echo "Download enabled: {$title}\n";
}
