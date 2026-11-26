<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = session_user();
if (!$u) {
    redirect('login.php?m=expired');
}
if (!empty($_SESSION['mfa_ok'])) {
    redirect('orders.php');
}
if ((int) $u['mfa_enabled'] !== 1) {
    redirect('mfa_setup.php');
}

$backup = isset($_GET['backup']);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!rate_ok()) {
        $message = 'Too many attempts. Wait a minute and try again.';
    } else {
        $code = trim((string) ($_POST['code'] ?? ''));
        $ok = !$backup && totp_verify((string) $u['totp_secret'], $code);
        if (!$ok) {
            // Backup code: one use only. Stored as a hash, so compare with password_verify.
            $clean = strtoupper(str_replace('-', '', $code));
            foreach (all('SELECT id, code_hash FROM backup_codes WHERE user_id = ? AND used = 0', [$u['id']]) as $b) {
                if (password_verify($clean, $b['code_hash'])) {
                    run('UPDATE backup_codes SET used = 1 WHERE id = ?', [$b['id']]);
                    $ok = true;
                    break;
                }
            }
        }
        if ($ok) {
            run('UPDATE users SET failed_count = 0 WHERE id = ?', [$u['id']]);
            audit($u['username'], 'mfa_ok', $backup ? 'backup code' : 'authenticator');
            session_regenerate_id(true);
            $_SESSION['mfa_ok'] = true;
            $_SESSION['last'] = time();
            redirect('orders.php');
        }
        $now_locked = register_failure($u);
        audit($u['username'], $now_locked ? 'account_locked' : 'mfa_fail');
        if ($now_locked) {
            logout_session();
            redirect('login.php?m=expired');
        }
        $message = 'That code didn’t work. Check the code and try again.';
    }
}

html_open('Verify it’s you', 'auth-body');
?>
<main class="auth-page">
  <div class="auth-theme"><?= theme_toggle() ?></div>
  <div class="auth-brand"><?= brand() ?></div>
  <section class="auth-card">
    <div class="auth-icon"><?= icon('shield', 24) ?></div>
    <div class="eyebrow">Two-step verification</div>
    <h1><?= $backup ? 'Use a backup code' : 'Verify it’s you' ?></h1>
    <p class="subhead"><?= $backup ? 'Enter one of the backup codes you saved during setup.' : 'Enter the 6-digit code from your authenticator app.' ?></p>
    <?php if ($message !== ''): ?>
      <div class="alert error" role="alert"><strong>Couldn’t verify</strong><span><?= e($message) ?></span></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('verify.php' . ($backup ? '?backup=1' : ''))) ?>" class="stack">
      <?= csrf_field() ?>
      <label class="field"><span class="label"><?= $backup ? 'Backup code' : 'Verification code' ?></span>
        <input name="code" class="<?= $backup ? '' : 'code-input' ?>" inputmode="<?= $backup ? 'text' : 'numeric' ?>"
               maxlength="<?= $backup ? 9 : 6 ?>" placeholder="<?= $backup ? 'XXXX-XXXX' : '000000' ?>" autocomplete="one-time-code" required></label>
      <button class="button primary full" type="submit">Verify</button>
    </form>
    <a class="text-link centered" href="<?= e(url($backup ? 'verify.php' : 'verify.php?backup=1')) ?>">
      <?= $backup ? 'Use an authenticator code instead' : 'Use a backup code instead' ?></a>
  </section>
</main>
<?php html_close();
