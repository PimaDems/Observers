<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
Security::startSession('obs_admin');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && Security::csrfOk()) {
    Admin::logout();
}
header('Location: ' . Util::url('admin/login.php'));
