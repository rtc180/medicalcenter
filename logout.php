<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

// POST only + CSRF, so a stray link or image cannot log staff out.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    do_logout();
}
redirect('/login.php');
