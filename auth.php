<?php
/**
 * auth.php - login, lockout, MFA state, roles, CSRF, flash messages, audit log and password rules.
 */

/** Escape any value before printing it in HTML. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
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

/** Every POST must carry the token from this session. */
function check_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $sent)) {
        http_response_code(400);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

/* ---------- Flash messages (shown once) ---------- */
function flash(string $text, string $tone = 'ok'): void
{
    $_SESSION['flash'] = ['text' => $text, 'tone' => $tone];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Audit log (never store passwords, codes or tokens) ---------- */
function audit(string $username, string $event, string $detail = ''): void
{
    run('INSERT INTO audit_log (ts, username, event, detail) VALUES (?,?,?,?)', [
        date('Y-m-d H:i:s'),
        mb_substr($username !== '' ? $username : '-', 0, 40),
        $event,
        mb_substr($detail, 0, 200),
    ]);
}

/* ---------- Rate limit (per IP, per minute) ---------- */
function rate_ok(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    run('DELETE FROM login_attempts WHERE created_at < ?', [time() - 60]);
    $n = (int) one('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ?', [$ip])['n'];
    if ($n >= LOGIN_RATE_LIMIT) {
        return false;
    }
    run('INSERT INTO login_attempts (ip, created_at) VALUES (?,?)', [$ip, time()]);
    return true;
}

/* ---------- Session ---------- */
function logout_session(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    session_destroy();
}

/** Returns the logged-in user (password and MFA may still be pending), or null. Enforces idle timeout. */
function session_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $u = one('SELECT * FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
    if (!$u || (int) $u['disabled'] === 1 || !isset($_SESSION['sv']) || (int) $_SESSION['sv'] !== (int) $u['session_version']) {
        logout_session();
        return null;
    }
    $idle = $u['role'] === 'admin' ? IDLE_ADMIN : IDLE_STAFF;
    if (time() - (int) ($_SESSION['last'] ?? 0) > $idle) {
        audit($u['username'], 'session_timeout');
        logout_session();
        return null;
    }
    $_SESSION['last'] = time();
    return $u;
}

/** Full access needs password AND MFA. Otherwise send the user to the right MFA page. */
function require_full(): array
{
    $u = session_user();
    if (!$u) {
        redirect('login.php?m=expired');
    }
    if (empty($_SESSION['mfa_ok'])) {
        redirect(((int) $u['mfa_enabled'] === 1) ? 'verify.php' : 'mfa_setup.php');
    }
    if ((int) $u['force_change'] === 1 && !in_array(basename($_SERVER['SCRIPT_NAME']), ['password.php', 'logout.php'], true)) {
        redirect('password.php?forced=1');
    }
    return $u;
}

/** Stop with a 403 page and log it. */
function deny(array $user, string $route): never
{
    audit($user['username'], 'access_denied', $route);
    http_response_code(403);
    render_error(403);
    exit;
}

/* ---------- Lockout ---------- */
/** Adds a failed attempt. Returns true when the account is now locked. */
function register_failure(array $u): bool
{
    $fails = (int) $u['failed_count'] + 1;
    $lock = $fails >= MAX_FAILS ? time() + LOCK_SECONDS : 0;
    run('UPDATE users SET failed_count = ?, locked_until = ? WHERE id = ?', [$lock ? 0 : $fails, $lock, $u['id']]);
    return $lock > 0;
}

/* ---------- Password rules (same as the design hint) ---------- */
const COMMON_PASSWORDS = ['password1234', 'password123!', 'qwerty123456', 'letmein12345', 'iloveyou12345', 'lemonade123456'];

/** Returns an error message, or null when the password is acceptable. */
function password_problem(string $pw, string $username): ?string
{
    if (mb_strlen($pw) < 12) {
        return 'Use at least 12 characters.';
    }
    if (in_array(mb_strtolower($pw), COMMON_PASSWORDS, true)) {
        return 'That password is too common.';
    }
    if ($username !== '' && str_contains(mb_strtolower($pw), mb_strtolower($username))) {
        return 'Password must not contain your username.';
    }
    return null;
}

/** Usernames: 3 to 40 letters, numbers, dots, dashes, or underscores. */
function valid_username(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._-]{3,40}$/', $name);
}

/* ---------- Roles and permissions (deny by default) ---------- */
/** Admin: everything. Staff: create orders, and only change orders they created or were assigned. */
function can_see_order(array $u, array $o): bool
{
    return $u['role'] === 'admin' || (int) $o['created_by'] === (int) $u['id'] || (int) ($o['assigned_to'] ?? 0) === (int) $u['id'];
}

function can_edit_order(array $u, array $o): bool
{
    return $u['role'] === 'admin' || (int) $o['created_by'] === (int) $u['id'];
}

function can_set_status(array $u, array $o): bool
{
    return can_see_order($u, $o);
}

/** Sensitive actions need a fresh code from the authenticator app or an unused backup code. */
function step_up_ok(array $u, string $code, string $route): bool
{
    $code = trim($code);
    $ok = totp_verify((string) one('SELECT totp_secret FROM users WHERE id = ?', [$u['id']])['totp_secret'], $code);
    if (!$ok) {
        foreach (all('SELECT id, code_hash FROM backup_codes WHERE user_id = ? AND used = 0', [$u['id']]) as $b) {
            if (password_verify(strtoupper(str_replace('-', '', $code)), $b['code_hash'])) {
                run('UPDATE backup_codes SET used = 1 WHERE id = ?', [$b['id']]);
                $ok = true;
                break;
            }
        }
    }
    audit($u['username'], $ok ? 'step_up_ok' : 'step_up_fail', $route);
    return $ok;
}
