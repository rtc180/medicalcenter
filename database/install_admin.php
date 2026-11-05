<?php
declare(strict_types=1);
/**
 * One-time CLI installer for the first administrator.
 *   php database/install_admin.php "Full Name" admin@example.com "StrongPassword123!"
 * Delete or block access to this file after use.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/db.php';

[$script, $name, $email, $pass] = array_pad($argv, 4, null);
if (!$name || !$email || !$pass) {
    exit("Usage: php install_admin.php \"Full Name\" email password\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Invalid email.\n");
}
if (strlen($pass) < 10) {
    exit("Password must be at least 10 characters.\n");
}
$st = db()->prepare('INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)');
$st->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
echo "Administrator created: $email\n";
