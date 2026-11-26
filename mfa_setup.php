<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = session_user();
if (!$u) {
    redirect('login.php?m=expired');
}
$full = !empty($_SESSION['mfa_ok']);
if ((int) $u['mfa_enabled'] === 1 && !$full) {
    redirect('verify.php');
}

// The secret is created once and kept until the first correct code is entered.
$secret = (string) $u['totp_secret'];
if ($secret === '') {
    $secret = new_totp_secret();
    run('UPDATE users SET totp_secret = ? WHERE id = ?', [$secret, $u['id']]);
}

$message = '';
$new_codes = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) $u['mfa_enabled'] === 0) {
    check_csrf();
    $code = trim((string) ($_POST['code'] ?? ''));
    if (totp_verify($secret, $code)) {
        run('DELETE FROM backup_codes WHERE user_id = ?', [$u['id']]);
        $new_codes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));   // 8 characters, shown as XXXX-XXXX
            $new_codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            run('INSERT INTO backup_codes (user_id, code_hash) VALUES (?,?)', [$u['id'], password_hash($raw, PASSWORD_DEFAULT)]);
        }
        run('UPDATE users SET mfa_enabled = 1 WHERE id = ?', [$u['id']]);
        audit($u['username'], 'mfa_enrolled');
        session_regenerate_id(true);
        $_SESSION['mfa_ok'] = true;
        $_SESSION['last'] = time();
    } else {
        $message = 'That code didn’t match. Check your app and try again.';
    }
}

$pretty_secret = implode(' ', str_split($secret, 4));
$app = $full ? $u : null;

if ($new_codes !== null || ($full && (int) $u['mfa_enabled'] === 1)) {
    // Standard frame for logged-in users. Backup codes are shown only once, right after setup.
    app_open('Backup codes', 'mfa', $u);
    echo '<div class="settings-narrow"><div class="page-header"><div><div class="eyebrow">Security</div><h1>' . ($new_codes ? 'Backup codes' : 'MFA status') . '</h1>'
        . '<p>' . ($new_codes ? 'Two-step verification is now on for your account.' : 'Two-step verification is on for your account.') . '</p></div></div>'
        . '<section class="panel settings-card success-card"><div class="success-mark">' . icon('check', 24) . '</div><h2>MFA is turned on</h2>';
    if ($new_codes) {
        echo '<div class="alert warning"><strong>Save these now. Each works once.</strong><span>Keep them somewhere safe. You won’t be able to see these codes again.</span></div>'
            . '<div class="backup-grid">';
        foreach ($new_codes as $c) {
            echo '<code>' . e($c) . '</code>';
        }
        echo '</div>';
    }
    echo '<div class="settings-actions"><a class="button primary" href="' . e(url('orders.php')) . '">Continue to orders</a></div></section></div>';
    app_close();
    exit;
}

// Setup steps (also used when a pending login has to finish MFA setup first).
$body = '<div class="settings-narrow"><div class="page-header"><div><div class="eyebrow">Security</div><h1>Set up two-step verification</h1>'
    . '<p>Protect your account with an authenticator app.</p></div></div>'
    . '<section class="panel settings-card">'
    . '<div class="step"><span>1</span><div><h2>Add SecureLocal to your authenticator</h2><p>Open your authenticator app, choose “Enter a setup key”, and type this key.</p></div></div>'
    . '<div class="setup-key"><code>' . e($pretty_secret) . '</code></div>'
    . '<hr><div class="step"><span>2</span><div><h2>Verify the setup</h2><p>Enter the 6-digit code shown in your app.</p></div></div>'
    . ($message !== '' ? '<div class="alert error" role="alert"><strong>Not yet</strong><span>' . e($message) . '</span></div>' : '')
    . '<form method="post" action="' . e(url('mfa_setup.php')) . '" class="mfa-form">' . csrf_field()
    . '<label class="field"><span class="label">Verification code</span><input class="code-input" name="code" inputmode="numeric" maxlength="6" placeholder="000000" required></label>'
    . '<button class="button primary" type="submit">Confirm and turn on MFA</button></form></section></div>';

if ($full) {
    app_open('Set up MFA', 'mfa', $u);
    echo $body;
    app_close();
} else {
    html_open('Set up MFA', 'auth-body');
    echo '<main class="auth-page"><div class="auth-theme">' . theme_toggle() . '</div><div class="auth-brand">' . brand() . '</div><section class="auth-card">' . $body . '</section></main>';
    html_close();
}
