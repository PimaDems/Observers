<?php
declare(strict_types=1);

final class Admin
{
    public static function create(string $username, string $password): int
    {
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/', $username)) {
            throw new InvalidArgumentException('Username must be 3-80 chars: letters, digits, _ . @ -');
        }
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('Password must be at least 10 characters');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = Db::val('SELECT id FROM admins WHERE username = ?', [$username]);
        if ($existing) {
            Db::exec('UPDATE admins SET password_hash = ?, is_active = 1 WHERE id = ?', [$hash, $existing]);
            return (int) $existing;
        }
        return Db::insert('INSERT INTO admins (username, password_hash, created_at) VALUES (?,?,?)', [$username, $hash, Util::now()]);
    }

    /** Verify credentials; always runs a hash check to keep timing similar. */
    public static function attempt(string $username, string $password): ?array
    {
        $row = Db::one('SELECT * FROM admins WHERE username = ? AND is_active = 1', [$username]);
        $hash = $row['password_hash'] ?? '$2y$10$AlVD4UPCileFjCty3E7TYuTffMOisaCKd6k1A54k4SV1Wln1T5gB.';
        $ok = password_verify($password, $hash);
        if (!$row || !$ok) {
            return null;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Db::exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        Db::exec('UPDATE admins SET last_login_at = ? WHERE id = ?', [Util::now(), $row['id']]);
        return $row;
    }

    public static function current(): ?array
    {
        Security::startSession('obs_admin');
        $id = $_SESSION['admin_id'] ?? null;
        if (!$id) {
            return null;
        }
        $timeout = (int) Config::get('admin.session_timeout', 3600);
        if (time() - (int) ($_SESSION['last_seen'] ?? 0) > $timeout
            || ($_SESSION['ua'] ?? '') !== hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
            self::logout();
            return null;
        }
        $_SESSION['last_seen'] = time();
        return Db::one('SELECT id, username FROM admins WHERE id = ? AND is_active = 1', [$id]);
    }

    public static function loginSession(array $admin): void
    {
        Security::startSession('obs_admin');
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['last_seen'] = time();
        $_SESSION['ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        unset($_SESSION['csrf']);
    }

    public static function logout(): void
    {
        Security::startSession('obs_admin');
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** Require login for admin pages. Returns the admin row. */
    public static function require(): array
    {
        Security::headers();
        header('Cache-Control: no-store');
        $a = self::current();
        if (!$a) {
            header('Location: ' . Util::url('admin/login.php'));
            exit;
        }
        return $a;
    }
}
