<?php
declare(strict_types=1);

/**
 * Good Samaritan Medical Center - configuration.
 * In production set these as environment variables and keep this file
 * outside the web root if possible.
 */
define('APP_NAME', 'Good Samaritan Medical Center');
define('APP_URL', getenv('BOH_URL') ?: 'http://localhost/balm-of-hope');
define('CLINIC_PUBLIC_URL', getenv('BOH_PUBLIC_URL') ?: '');
define('ASSET_VERSION', '20261106-06');
define('APP_ENV', getenv('BOH_ENV') ?: 'development'); // set to "production" live

function app_url(string $path = ''): string
{
    $basePath = rtrim((string)parse_url(APP_URL, PHP_URL_PATH), '/');
    $path = '/' . ltrim($path, '/');

    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        return $path;
    }

    return $basePath . $path;
}

function app_asset(string $path): string
{
    return app_url($path) . '?v=' . rawurlencode(ASSET_VERSION);
}

define('DB_HOST', getenv('BOH_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('BOH_DB_NAME') ?: 'balm_of_hope');
define('DB_USER', getenv('BOH_DB_USER') ?: 'root');
define('DB_PASS', getenv('BOH_DB_PASS') ?: '');
define('DB_DSN_OVERRIDE', getenv('BOH_DB_DSN') ?: ''); // optional (used for testing)

define('SESSION_IDLE_TIMEOUT', 1200);   // 20 min of inactivity logs staff out
define('MAX_FAILED_LOGINS', 5);         // lock account after N failures
define('LOCKOUT_MINUTES', 15);

define('CONSULTATION_FEE', 20.00);      // default fee, edit to suit
define('CLINIC_PHONE', getenv('BOH_CLINIC_PHONE') ?: '+263 775 606 149');
define('CLINIC_EMAIL', getenv('BOH_CLINIC_EMAIL') ?: '');
define('CLINIC_ADDRESS', getenv('BOH_CLINIC_ADDRESS') ?: '');

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
date_default_timezone_set('Africa/Harare');
