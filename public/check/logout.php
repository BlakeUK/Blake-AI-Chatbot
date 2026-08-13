<?php
// public/check/logout.php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

\Auth\CheckTool::logout();
json_out(['ok' => true]);
