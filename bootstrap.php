<?php
/**
 * bootstrap.php - loaded by every page. Starts the session and sends security headers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/menu.php';

date_default_timezone_set(APP_TZ);

// Session cookie: HttpOnly, SameSite=Strict, Secure when on HTTPS.
ini_set('session.use_strict_mode', '1');
session_name('sl_sid');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => HTTPS_ONLY,
]);
session_start();
if (($_SESSION['schema_v'] ?? '') !== SCHEMA_VERSION) {
    ensure_schema();
    $_SESSION['schema_v'] = SCHEMA_VERSION;
}

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");
if (HTTPS_ONLY) {
    header('Strict-Transport-Security: max-age=31536000');
}
