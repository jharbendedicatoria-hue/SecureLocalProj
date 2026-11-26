<?php
/**
 * user_edit.php - admin only: edit another account's name and username.
 * Needs the admin's own password. Changing the username signs that user out.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_full();
if ($me['role'] !== 'admin') {
    deny($me, 'user edit page');
}

$target = one('SELECT * FROM users WHERE id = ?', [(int) ($_GET['id'] ?? $_POST['id'] ?? 0)]);
if (!$target) {
    http_response_code(404);
    render_error(404);
    exit;
}
if ((int) $target['id'] === (int) $me['id']) {
    redirect('account.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim((string) ($_POST['display_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    if (!password_verify((string) ($_POST['admin_pw'] ?? ''), $me['pw_hash'])) {
        audit($me['username'], 'profile_edit_fail', $target['username']);
        flash('Enter your own password to save changes.', 'error');
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        flash('Name must be 2 to 80 characters.', 'error');
    } elseif (!valid_username($username)) {
        flash('Username: 3 to 40 letters, numbers, dots, dashes, or underscores.', 'error');
    } elseif (one('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $target['id']])) {
        flash('That username is already taken.', 'error');
    } else {
        $renamed = $username !== $target['username'];
        run('UPDATE users SET display_name = ?, username = ?' . ($renamed ? ', session_version = session_version + 1' : '') . ' WHERE id = ?',
            [$name, $username, $target['id']]);
        if ($renamed) {
            audit($me['username'], 'user_renamed', $target['username'] . ' -> ' . $username);
        }
        audit($me['username'], 'profile_updated', $username);
        flash('Account saved.');
        redirect('users.php');
    }
    redirect('user_edit.php?id=' . (int) $target['id']);
}

app_open('Edit account', 'users', $me);
echo flash_banner();
?>
<div class="settings-narrow">
  <div class="page-header">
    <div><div class="eyebrow">Admin</div><h1>Edit account</h1><p>Change the name and username for <strong><?= e($target['display_name']) ?></strong>.</p></div>
  </div>
  <section class="panel settings-card">
    <form method="post" action="<?= e(url('user_edit.php?id=' . (int) $target['id'])) ?>" class="stack">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $target['id'] ?>">
      <label class="field"><span class="label">Display name</span>
        <input name="display_name" value="<?= e($target['display_name']) ?>" required minlength="2" maxlength="80"></label>
      <label class="field"><span class="label">Username</span>
        <input name="username" value="<?= e($target['username']) ?>" required maxlength="40"></label>
      <label class="field"><span class="label">Your password <small>to confirm the change</small></span>
        <input type="password" name="admin_pw" required autocomplete="current-password" maxlength="200"></label>
      <div class="settings-actions">
        <a class="button secondary" href="<?= e(url('users.php')) ?>">Cancel</a>
        <button class="button primary" type="submit">Save account</button>
      </div>
    </form>
  </section>
</div>
<?php app_close();
