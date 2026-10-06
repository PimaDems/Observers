<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
Security::startSession('obs_admin');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    if (!Security::csrfOk()) {
        $error = 'Session expired. Please try again.';
    } elseif (!RateLimiter::check('login_ip', Util::ip()) || !RateLimiter::hit('login_user', mb_strtolower($user), 10, 900)) {
        http_response_code(429);
        $error = 'Too many login attempts. Please wait 15 minutes.';
    } elseif ($admin = Admin::attempt($user, (string) ($_POST['password'] ?? ''))) {
        Admin::loginSession($admin);
        Util::audit((int) $admin['id'], 'login');
        header('Location: ' . Util::url('admin/'));
        exit;
    } else {
        Util::audit(null, 'login_failed', $user);
        $error = 'Invalid username or password.';
    }
}
View::header('Admin login');
echo '<h1>Admin login</h1>';
View::errors($error ? [$error] : []);
echo '<form method="post">' . Security::csrfField()
    . '<label>Username <input name="username" required autocomplete="username"></label>'
    . '<label>Password <input type="password" name="password" required autocomplete="current-password"></label>'
    . '<button class="primary">Log in</button></form>';
View::footer();
