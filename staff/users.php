<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['admin']); // admin only: any other role gets 403

$errors = [];
$creatableRoles = ['admin' => ROLE_LABELS['admin'], 'doctor' => ROLE_LABELS['doctor'], 'nurse' => ROLE_LABELS['nurse']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post('action');
    if ($action === 'create') {
        $name = post('full_name'); $email = mb_strtolower(post('email')); $role = post('role'); $pass = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Name and a valid email are required.'; }
        if (!array_key_exists($role, $creatableRoles)) { $errors[] = 'Choose Administrator, Doctor, or Nurse.'; }
        if (strlen($pass) < 10 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) { $errors[] = 'Password needs 10+ characters with letters and numbers.'; }
        if (!$errors) {
            try {
                db()->prepare('INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)')
                    ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
                audit('user_created', $email . ' as ' . $role);
                flash('success', 'User created.');
                redirect('/staff/users.php');
            } catch (PDOException $ex) {
                $errors[] = 'That email is already in use.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)post('id');
        if ($id !== $u['id']) { // never let admin lock themselves out
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
            audit('user_toggled', "user $id");
        }
        redirect('/staff/users.php');
    } elseif ($action === 'delete') {
        $id = (int)post('id');
        if ($id === $u['id']) {
            flash('error', 'You cannot delete your own account.');
        } elseif ($id < 1) {
            flash('error', 'Choose a valid staff account.');
        } else {
            $delete = db()->prepare('DELETE FROM users WHERE id=?');
            $delete->execute([$id]);
            if ($delete->rowCount() === 0) {
                flash('error', 'Staff account not found.');
            } else {
                audit('user_deleted', "user $id");
                flash('success', 'Staff account deleted. Associated clinical records have been retained.');
            }
        }
        redirect('/staff/users.php');
    } elseif ($action === 'reset') {
        $id = (int)post('id'); $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) >= 10 && preg_match('/[A-Za-z]/', $pass) && preg_match('/\d/', $pass)) {
            db()->prepare('UPDATE users SET password_hash=?, failed_logins=0, locked_until=NULL WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            audit('password_reset', "user $id");
            flash('success', 'Password reset.');
        } else {
            flash('error', 'Password needs 10+ characters with letters and numbers.');
        }
        redirect('/staff/users.php');
    }
}

$users = db()->query('SELECT id, full_name, email, role, is_active, last_login FROM users ORDER BY role, full_name')->fetchAll();
staff_header('Users', 'users.php');
?>
<div class="page-head"><div><h1>Users</h1><p>Add staff accounts and manage access to the clinic system.</p></div></div>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>

<section class="panel" id="add-users"><h2>Add users</h2><p>New accounts can be administrators, doctors, or nurses. Existing legacy staff roles are retained.</p>
  <form method="post" action="<?= e(app_url('/staff/users.php')) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="120" required></div>
    <div><label for="email">Email</label><input id="email" type="email" name="email" maxlength="160" required></div>
    <div><label for="role">Role</label><select id="role" name="role"><?php foreach ($creatableRoles as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="new-password" required></div>
    <div><button class="btn btn-dark" type="submit">Create user</button></div>
  </form></section>

  <section class="panel" id="users-list"><h2>Users list</h2>
  <div class="table-wrap"><table><tr><th>Name</th><th>Email</th><th>Role</th><th>Last login</th><th>Status</th><th>Actions</th></tr>
  <?php foreach ($users as $r): ?>
    <tr><td><?= e($r['full_name']) ?></td><td><?= e($r['email']) ?></td><td><?= e(ROLE_LABELS[$r['role']]) ?></td>
      <td><?= $r['last_login'] ? e(date('j M H:i', strtotime($r['last_login']))) : 'Never' ?></td>
      <td><?= $r['is_active'] ? badge('Active', 'paid') : badge('Disabled', 'emergency') ?></td>
      <td class="staff-account-actions">
        <?php if ((int)$r['id'] !== $u['id']): ?>
        <form method="post" action="<?= e(app_url('/staff/users.php')) ?>" data-confirm="Change this account's status?"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button></form>
        <?php endif; ?>
        <form method="post" action="<?= e(app_url('/staff/users.php')) ?>" class="staff-password-reset"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="password" name="password" placeholder="New password" aria-label="New password for <?= e($r['full_name']) ?>" autocomplete="new-password"><button class="btn btn-sm" type="submit">Reset</button></form>
        <?php if ((int)$r['id'] !== $u['id']): ?>
        <form method="post" action="<?= e(app_url('/staff/users.php')) ?>" data-confirm="Permanently delete the account for <?= e($r['full_name']) ?>? Historical clinical records will be retained. This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
        <?php endif; ?>
      </td></tr>
  <?php endforeach; ?></table></div></section>

<?php staff_footer();
