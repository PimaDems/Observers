<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Each test gets a fresh in-memory SQLite database with the real schema.sql. */
abstract class DbTestCase extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
        Config::set([
            'site_url' => 'https://example.test/Observers',
            'mail' => ['transport' => 'log', 'from_email' => 'noreply@example.test'],
            'verification' => ['require_phone' => false, 'token_ttl_hours' => 24, 'max_code_attempts' => 5],
        ]);
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        Db::set($pdo);
        Db::installSchema();
        Catalog::seed();
        Util::$fixedNow = '2026-10-01 09:00:00';
        Mailer::$outbox = [];
        Verification::setSmsProvider(null);
    }

    protected function tearDown(): void
    {
        Util::$fixedNow = null;
        Mailer::$outbox = null;
        Db::set(null);
    }

    protected function siteId(string $name): int
    {
        return (int) Db::val('SELECT id FROM sites WHERE name = ?', [$name]);
    }

    protected function makeSite(string $type = 'election_day', string $name = 'Test School'): int
    {
        return Db::insert(
            'INSERT INTO sites (site_key, type, name, address, created_at, updated_at) VALUES (?,?,?,?,?,?)',
            [Catalog::siteKey($type, $name, '1 Main St'), $type, $name, '1 Main St', Util::now(), Util::now()]
        );
    }

    protected function makeShift(int $siteId, string $role, string $start, string $end, ?int $max = null): int
    {
        return Db::insert(
            'INSERT INTO shifts (site_id, role_id, starts_at, ends_at, max_volunteers, target_coverage, created_at) VALUES (?,?,?,?,?,2,?)',
            [$siteId, Catalog::roleId($role), $start, $end, $max, Util::now()]
        );
    }

    protected function person(string $n): array
    {
        return ['name' => "Person $n", 'email' => "p$n@example.test", 'phone' => '(520) 555-01' . str_pad($n, 2, '0', STR_PAD_LEFT)];
    }

    /** Last verification link token sent by email. */
    protected function lastToken(): string
    {
        $m = end(Mailer::$outbox);
        preg_match('/verify\.php\?t=([a-f0-9]{32})/', $m['body'], $x);
        return $x[1];
    }
}
