<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

/* ---------- Secure session ---------- */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('BOHSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Idle timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();

    // Bind session to browser fingerprint (mitigates hijacking)
    $fp = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'boh');
    if (isset($_SESSION['fp']) && !hash_equals($_SESSION['fp'], $fp)) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['fp'] = $fp;
}

/* ---------- HTTP security headers ---------- */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
         . "style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; "
         . "script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header_remove('X-Powered-By');
}

/* ---------- Output escaping (XSS) ---------- */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Security token invalid or expired. Please go back and reload the page.');
    }
}

/* ---------- Input helpers ---------- */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}
function get_int(string $key): int
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : 0;
}
function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}
function num_or_null(string $v): ?float
{
    return ($v === '' || !is_numeric($v)) ? null : (float)$v;
}

/* ---------- Audit log ---------- */
function audit(string $action, string $detail = ''): void
{
    try {
        $st = db()->prepare('INSERT INTO audit_log (user_id, action, detail, ip_address) VALUES (?,?,?,?)');
        $st->execute([$_SESSION['user']['id'] ?? null, $action, mb_substr($detail, 0, 255), client_ip()]);
    } catch (Throwable $e) {
        error_log('audit failed: ' . $e->getMessage());
    }
}
function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/* ---------- Simple IP rate limiter (public forms) ---------- */
function rate_limited(string $bucket, int $max, int $windowSeconds): bool
{
    $now = time();
    $hits = array_filter($_SESSION['rl'][$bucket] ?? [], fn($t) => $t > $now - $windowSeconds);
    if (count($hits) >= $max) {
        $_SESSION['rl'][$bucket] = $hits;
        return true;
    }
    $hits[] = $now;
    $_SESSION['rl'][$bucket] = $hits;
    return false;
}

/* ---------- Flash messages ---------- */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function redirect(string $url): never
{
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = app_url($url);
    }
    header('Location: ' . $url);
    exit;
}
