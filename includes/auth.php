<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

secure_session_start();
send_security_headers();

const ROLE_LABELS = [
    'admin' => 'Administrator', 'reception' => 'Receptionist', 'nurse' => 'Nurse',
    'doctor' => 'Doctor', 'pharmacist' => 'Pharmacist',
];

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(array $roles = []): array
{
    $u = current_user();
    if (!$u) {
        redirect(app_url('/login.php'));
    }
    if ($roles && $u['role'] !== 'admin' && !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied: your role cannot open this page.');
    }
    return $u;
}

/** Returns error string or null on success. */
function attempt_login(string $email, string $password): ?string
{
    $generic = 'Invalid email or password.';
    $st = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u || !$u['is_active']) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // timing equaliser
        audit('login_failed', 'unknown/inactive: ' . mb_substr($email, 0, 80));
        return $generic;
    }
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        return 'Account temporarily locked. Try again later.';
    }
    if (!password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        $lock = null;
        if ($fails >= MAX_FAILED_LOGINS) {
            $lock = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
            $fails = 0;
        }
        db()->prepare('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?')->execute([$fails, $lock, $u['id']]);
        audit('login_failed', 'user ' . $u['id']);
        return $generic;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('UPDATE users SET failed_logins=0, locked_until=NULL, last_login=NOW() WHERE id=?')->execute([$u['id']]);

    session_regenerate_id(true);                // prevents session fixation
    $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['full_name'], 'role' => $u['role']];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    audit('login', 'ok');
    return null;
}

function do_logout(): void
{
    audit('logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
