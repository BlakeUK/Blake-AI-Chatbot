<?php
// public/api/admin/session.php — GET: check whether the current browser
// already holds a logged-in session (used on page load, since a refresh
// resets all client-side state but the session cookie can still be valid).

require dirname(__DIR__, 3) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

\Auth\Admin::session();

if (empty($_SESSION['admin_id'])) {
    json_out(['ok' => false]);
}

json_out(['ok' => true, 'csrf' => \Auth\Admin::csrf(), 'role' => \Auth\Admin::role()]);
