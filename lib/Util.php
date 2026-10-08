<?php
declare(strict_types=1);

final class Util
{
    /** Test hook: fixed "now" as 'Y-m-d H:i:s'. */
    public static ?string $fixedNow = null;

    public static function now(): string
    {
        return self::$fixedNow ?? date('Y-m-d H:i:s');
    }

    public static function nowPlus(int $seconds): string
    {
        return date('Y-m-d H:i:s', strtotime(self::now()) + $seconds);
    }

    public static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function token(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hashToken(string $t): string
    {
        return hash('sha256', $t);
    }

    public static function url(string $path = ''): string
    {
        return rtrim((string) Config::get('base_url', '/Observers'), '/') . '/' . ltrim($path, '/');
    }

    public static function absUrl(string $path = ''): string
    {
        return rtrim((string) Config::get('site_url', ''), '/') . '/' . ltrim($path, '/');
    }

    public static function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
    }

    public static function validDate(?string $d): bool
    {
        if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return false;
        }
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        return checkdate($m, $day, $y);
    }

    public static function fmtTime(string $dt): string
    {
        return date('g:i A', strtotime($dt));
    }

    public static function fmtDate(string $dt): string
    {
        return date('D, M j, Y', strtotime($dt));
    }

    public static function fmtRange(string $start, string $end): string
    {
        return self::fmtDate($start) . ' ' . self::fmtTime($start) . ' – ' . self::fmtTime($end);
    }

    /** Neutralise CSV/formula injection when exporting user-supplied text. */
    public static function csvSafe(mixed $v): string
    {
        $s = (string) $v;
        if (preg_match('/^\+\d{7,15}$/', $s)) {
            return $s; // E.164 phone number
        }
        return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
    }

    public static function audit(?int $adminId, string $action, string $detail = ''): void
    {
        Db::exec(
            'INSERT INTO audit_log (admin_id, action, detail, ip, created_at) VALUES (?,?,?,?,?)',
            [$adminId, $action, mb_substr($detail, 0, 2000), self::ip(), self::now()]
        );
    }
}
