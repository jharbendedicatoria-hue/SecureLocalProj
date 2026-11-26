<?php
/**
 * users.php - admin only: add users, change roles, reset passwords, disable/enable, delete.
 * Create, role, reset, disable and delete all need a fresh MFA code.
 * Accounts with orders cannot be deleted (disable them instead, so the order history stays).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_full();
if ($me['role'] !== 'admin') {
    deny($me, 'users page');
}

/** 14 characters, mixed, and never contains the username. */
function temp_password(string $username): string
{
    do {
        $pw = 'Tmp-' . strtoupper(bin2hex(random_bytes(4))) . '#9';
    } while (stripos($pw, $username) !== false);
    return $pw;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name = trim((string) ($_POST['display_name'] ?? ''));
        $role = (string) ($_POST['role'] ?? '');
        if (!step_up_ok($me, (string) ($_POST['mfa'] ?? ''), 'create user ' . $username)) {
            flash('Enter a valid fresh MFA code to add a user.', 'error');
        } elseif (!valid_username($username)) {
            flash('Username: 3 to 40 letters, numbers, dots, dashes, or underscores.', 'error');
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            flash('Name must be 2 to 80 characters.', 'error');
        } elseif (!in_array($role, ['admin', 'staff'], true)) {
            flash('Choose a valid role.', 'error');
        } elseif (one('SELECT id FROM users WHERE username = ?', [$username])) {
            flash('That username is already taken.', 'error');
        } else {
            $pw = temp_password($username);
            run('INSERT INTO users (username, display_name, pw_hash, role, force_change) VALUES (?,?,?,?,1)',
                [$username, $name, password_hash($pw, PASSWORD_DEFAULT), $role]);
            audit($me['username'], 'user_created', $username . ' (' . $role . ')');
            $_SESSION['reveal'] = ['name' => $name, 'username' => $username, 'pw' => $pw];
            flash('Account created. Share the temporary password privately; they set a new one at first login.');
        }
        redirect('users.php');
    }

    $target = one('SELECT * FROM users WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if (!$target) {
        flash('That user no longer exists.', 'error');
    } elseif ((int) $target['id'] === (int) $me['id']) {
        flash('Use your Account page to change your own details.', 'error');
    } elseif (!step_up_ok($me, (string) ($_POST['mfa'] ?? ''), 'user ' . $target['username'] . ' ' . $action)) {
        flash('Enter a valid fresh MFA code to make this change.', 'error');
    } elseif ($action === 'reset') {
        $pw = temp_password($target['username']);
        run('UPDATE users SET pw_hash = ?, force_change = 1, failed_count = 0, locked_until = 0, session_version = session_version + 1 WHERE id = ?',
            [password_hash($pw, PASSWORD_DEFAULT), $target['id']]);
        audit($me['username'], 'password_reset', $target['username']);
        $_SESSION['reveal'] = ['name' => $target['display_name'], 'username' => $target['username'], 'pw' => $pw];
        flash('Temporary password created. Share it privately; they must change it at next login.');
    } elseif ($action === 'role') {
        $role = (string) ($_POST['role'] ?? '');
        if (!in_array($role, ['admin', 'staff'], true)) {
            flash('Choose a valid role.', 'error');
        } else {
            run('UPDATE users SET role = ?, session_version = session_version + 1 WHERE id = ?', [$role, $target['id']]);
            audit($me['username'], 'role_change', $target['username'] . ': ' . $target['role'] . ' -> ' . $role);
            flash('Role updated for ' . $target['display_name'] . ' to ' . $role . '.');
        }
    } elseif ($action === 'disable') {
        $new = (int) $target['disabled'] === 1 ? 0 : 1;
        run('UPDATE users SET disabled = ?, session_version = session_version + 1 WHERE id = ?', [$new, $target['id']]);
        audit($me['username'], $new ? 'user_disabled' : 'user_enabled', $target['username']);
        flash($new ? $target['display_name'] . ' is disabled.' : $target['display_name'] . ' is active again.');
    } elseif ($action === 'delete') {
        $n = (int) one('SELECT COUNT(*) AS n FROM orders WHERE created_by = ? OR assigned_to = ?', [$target['id'], $target['id']])['n'];
        if ($n > 0) {
            flash($target['display_name'] . ' has ' . $n . ' order(s). Disable the account instead so the history stays.', 'error');
        } else {
            run('DELETE FROM users WHERE id = ?', [$target['id']]);
            audit($me['username'], 'user_deleted', $target['username']);
            flash($target['display_name'] . ' was deleted.');
        }
    }
    redirect('users.php');
}

$reveal = $_SESSION['reveal'] ?? null;
unset($_SESSION['reveal']);
$users = all('SELECT * FROM users ORDER BY id');

app_open('Users', 'users', $me);
echo flash_banner();
?>
<div class="page-header">
  <div><div class="eyebrow">Admin</div><h1>Users</h1><p>Add or remove accounts, change roles, and reset passwords. Changes need a fresh MFA code.</p></div>
</div>

<?php if ($reveal): ?>
  <div class="alert warning" role="status">
    <strong>Temporary password for <?= e($reveal['name']) ?> (@<?= e($reveal['username']) ?>)</strong>
    <span>Shown once: <code><?= e($reveal['pw']) ?></code>. Share it privately. They must set a new password at their next login.</span>
  </div>
<?php endif; ?>

<section class="panel settings-card add-user-panel">
  <h2>Add user</h2>
  <form method="post" action="<?= e(url('users.php')) ?>" class="add-user-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label class="field"><span class="label">Username</span><input name="username" maxlength="40" required placeholder="e.g. lea.santos"></label>
    <label class="field"><span class="label">Display name</span><input name="display_name" maxlength="80" required placeholder="e.g. Lea Santos"></label>
    <label class="field"><span class="label">Role</span>
      <select name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select></label>
    <label class="field"><span class="label">Your MFA code</span><input name="mfa" inputmode="numeric" maxlength="9" required autocomplete="one-time-code" placeholder="000000"></label>
    <button class="button primary" type="submit">Create user</button>
  </form>
  <small class="muted">They get a temporary password and must set their own at first login.</small>
</section>

<section class="panel table-panel">
  <div class="table-title"><h2>Team members</h2><span><?= count($users) ?> users</span></div>
  <div class="table-scroll">
    <table class="users-table">
      <thead><tr><th>User</th><th>Role</th><th>Status</th><th>MFA</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u):
          $active = (int) $u['disabled'] === 0;
          $initials = '';
          foreach (preg_split('/\s+/', trim($u['display_name'])) as $p) { $initials .= mb_substr($p, 0, 1); }
          $is_me = (int) $u['id'] === (int) $me['id'];
        ?>
          <tr>
            <td>
              <div class="person"><span><?= e($initials) ?></span><div><strong><?= e($u['display_name']) ?></strong><small>@<?= e($u['username']) ?></small></div></div>
              <?php if ((int) $u['force_change'] === 1): ?><small class="muted">Must change password</small><?php endif; ?>
            </td>
            <td><?= e(ucfirst($u['role'])) ?><?= $is_me ? ' <small class="muted">(you)</small>' : '' ?></td>
            <td><span class="tag <?= $active ? 'active' : 'disabled' ?>"><?= $active ? 'Active' : 'Disabled' ?></span></td>
            <td><span class="mfa-state <?= (int) $u['mfa_enabled'] ? 'on' : 'off' ?>"><i></i><?= (int) $u['mfa_enabled'] ? 'On' : 'Off' ?></span></td>
            <td>
              <?php if ($is_me): ?>
                <small class="muted">Your account: use the Account page.</small>
              <?php else: ?>
                <div class="action-stack">
                  <a class="button small secondary" href="<?= e(url('user_edit.php?id=' . (int) $u['id'])) ?>">Edit account</a>

                  <form method="post" action="<?= e(url('users.php')) ?>" class="action-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <select name="role" aria-label="Role for <?= e($u['username']) ?>">
                      <option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                      <option value="staff"<?= $u['role'] === 'staff' ? ' selected' : '' ?>>Staff</option>
                    </select>
                    <input name="mfa" inputmode="numeric" maxlength="9" placeholder="MFA code" required autocomplete="one-time-code">
                    <button class="button small" type="submit">Save role</button>
                  </form>

                  <form method="post" action="<?= e(url('users.php')) ?>" class="action-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input name="mfa" inputmode="numeric" maxlength="9" placeholder="MFA code" required autocomplete="one-time-code">
                    <button class="button small" type="submit">Reset password</button>
                  </form>

                  <form method="post" action="<?= e(url('users.php')) ?>" class="action-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input name="mfa" inputmode="numeric" maxlength="9" placeholder="MFA code" required autocomplete="one-time-code">
                    <button class="button small secondary" type="submit"><?= $active ? 'Disable' : 'Enable' ?></button>
                  </form>

                  <form method="post" action="<?= e(url('users.php')) ?>" class="action-form" data-confirm="Delete <?= e($u['username']) ?> permanently? This cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input name="mfa" inputmode="numeric" maxlength="9" placeholder="MFA code" required autocomplete="one-time-code">
                    <button class="button small danger" type="submit">Delete</button>
                  </form>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<div class="security-note"><?= icon('shield') ?><p><strong>Fresh verification required</strong><span>Adding, changing, resetting, disabling, or deleting an account needs a current MFA code. Accounts with orders can't be deleted; disable them instead.</span></p></div>
<?php app_close();
