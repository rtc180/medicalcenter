<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$user = require_login();

$statement = db()->prepare('SELECT full_name, email, role, last_login, created_at FROM users WHERE id=? AND is_active=1');
$statement->execute([$user['id']]);
$profile = $statement->fetch();
if (!$profile) {
    do_logout();
    redirect('/login.php');
}

staff_header('Profile');
?>
<div class="page-head"><h1>Profile</h1></div>
<section class="panel profile-card">
  <div class="profile-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($profile['full_name'], 0, 1))) ?></div>
  <dl>
    <div><dt>Name</dt><dd><?= e($profile['full_name']) ?></dd></div>
    <div><dt>Email</dt><dd><?= e($profile['email']) ?></dd></div>
    <div><dt>Role</dt><dd><?= e(ROLE_LABELS[$profile['role']] ?? $profile['role']) ?></dd></div>
    <div><dt>Last login</dt><dd><?= $profile['last_login'] ? e(date('j M Y, H:i', strtotime($profile['last_login']))) : 'Not recorded' ?></dd></div>
    <div><dt>Account created</dt><dd><?= e(date('j M Y', strtotime($profile['created_at']))) ?></dd></div>
  </dl>
</section>
<?php staff_footer();
