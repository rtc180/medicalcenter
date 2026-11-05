<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    redirect('/staff/dashboard.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (rate_limited('login', 10, 600)) {
        $error = 'Too many attempts. Please wait a few minutes.';
    } else {
        $error = attempt_login(mb_strtolower(post('email')), (string)($_POST['password'] ?? ''));
        if ($error === null) {
            redirect('/staff/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff Login | <?= e(APP_NAME) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="<?= e(app_asset('/assets/img/logo.png')) ?>">
<link rel="stylesheet" href="<?= e(app_asset('/assets/css/style.css')) ?>">
</head>
<body>
<main class="login-wrap">
  <form class="login-card" method="post" action="<?= e(app_url('/login.php')) ?>" autocomplete="on">
    <img class="logo" src="<?= e(app_asset('/assets/img/logo.png')) ?>" alt="<?= e(APP_NAME) ?> logo">
    <h1 class="login-title">Staff sign in</h1>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <p><label for="email">Email</label><input id="email" name="email" type="email" required autofocus autocomplete="username"></p>
    <p><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></p>
    <p><button class="btn btn-dark login-submit" type="submit">Sign in</button></p>
    <p class="login-back"><a href="<?= e(app_url('/')) ?>">&larr; Back to website</a></p>
  </form>
</main>
</body>
</html>
