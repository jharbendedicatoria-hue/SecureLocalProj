<?php
require_once __DIR__ . '/includes/bootstrap.php';

$message = '';
$locked = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!rate_ok()) {
        $message = 'Too many attempts. Wait a minute and try again.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $u = one('SELECT * FROM users WHERE username = ?', [$username]);

        if (!$u || (int) $u['disabled'] === 1) {
            // Same work as a real check, so timing does not reveal which accounts exist.
            password_verify($password, password_hash('dummy-password', PASSWORD_DEFAULT));
            audit($username, 'login_fail', 'unknown or disabled account');
            $message = 'Invalid username or password.';
        } elseif ((int) $u['locked_until'] > time()) {
            audit($username, 'login_locked');
            $message = 'This account is temporarily locked. Try again in 15 minutes.';
            $locked = true;
        } elseif (!password_verify($password, $u['pw_hash'])) {
            $now_locked = register_failure($u);
            audit($username, $now_locked ? 'account_locked' : 'login_fail');
            $message = $now_locked ? 'This account is temporarily locked. Try again in 15 minutes.' : 'Invalid username or password.';
            $locked = $now_locked;
        } else {
            run('UPDATE users SET failed_count = 0 WHERE id = ?', [$u['id']]);
            audit($username, 'password_ok');
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['mfa_ok'] = false;
            $_SESSION['sv'] = (int) $u['session_version'];
            $_SESSION['last'] = time();
            redirect((int) $u['mfa_enabled'] === 1 ? 'verify.php' : 'mfa_setup.php');
        }
    }
}
$expired = ($_GET['m'] ?? '') === 'expired';

html_open('Log in', 'login-body');
?>
<main class="login-page">
  <section class="login-side">
    <div class="login-theme"><?= theme_toggle() ?></div>
    <div class="login-form-wrap">
      <div class="login-product">
        <span class="login-product-logo"><img src="<?= e(url('assets/img/the-lemonade-bar-logo.png')) ?>" alt=""></span>
        <span><strong><?= e(BRAND_NAME) ?></strong><small>Staff order portal</small></span>
      </div>
      <div class="login-heading">
        <div class="eyebrow">Staff sign in</div>
        <h1>Welcome back</h1>
        <p class="subhead">Log in to manage orders and keep every glass on track.</p>
      </div>
      <?php if ($expired): ?>
        <div class="alert warning" role="status"><strong>You were signed out</strong><span>Please log in again.</span></div>
      <?php endif; ?>
      <?php if ($message !== ''): ?>
        <div class="alert error" role="alert">
          <strong><?= $locked ? 'Account temporarily locked' : 'We couldn’t log you in' ?></strong>
          <span><?= e($message) ?></span>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('login.php')) ?>" class="stack">
        <?= csrf_field() ?>
        <label class="field"><span class="label">Username</span>
          <input name="username" autocomplete="username" placeholder="Enter your username" required maxlength="40"></label>
        <label class="field"><span class="label">Password</span>
          <input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required maxlength="200"></label>
        <button class="button primary full" type="submit">Log in</button>
      </form>
      <p class="login-security"><?= icon('lock', 14) ?> Private workspace · Authorized users only</p>
    </div>
  </section>
  <section class="login-brand-side">
    <div class="lemon-art lemon-one"><span></span><i></i></div>
    <div class="lemon-art lemon-two"><span></span><i></i></div>
    <div class="lemon-art lemon-three"><span></span><i></i></div>
    <div class="brand-showcase">
      <div class="logo-frame"><img src="<?= e(url('assets/img/the-lemonade-bar-logo.png')) ?>" alt="<?= e(BRAND_NAME) ?>"></div>
      <p class="brand-kicker">Made fresh. Served with care.</p>
      <h2>Bright drinks.<br>Smooth orders.</h2>
      <p>From Messenger to pickup, SecureLocal helps our team keep every order simple, safe, and refreshingly organized.</p>
      <div class="brand-feature"><?= icon('shield', 18) ?><span><strong>Secure by design</strong>Protected access for your whole team</span></div>
    </div>
  </section>
</main>
<?php html_close();
