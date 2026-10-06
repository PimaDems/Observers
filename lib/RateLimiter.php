<?php
declare(strict_types=1);

/** Fixed-window rate limiter backed by the rate_limits table. */
final class RateLimiter
{
    /** Returns true if the action is allowed (and counts it). */
    public static function hit(string $bucket, string $subject, int $max, int $windowSeconds): bool
    {
        $key = substr($bucket . ':' . hash('sha256', $subject), 0, 100);
        $now = time();
        return (bool) Db::transaction(function () use ($key, $max, $windowSeconds, $now) {
            $row = Db::one('SELECT window_start, hits FROM rate_limits WHERE rl_key = ?' . Db::forUpdate(), [$key]);
            if ($row === null) {
                Db::exec('INSERT INTO rate_limits (rl_key, window_start, hits) VALUES (?,?,1)', [$key, $now]);
                return true;
            }
            if ((int) $row['window_start'] + $windowSeconds <= $now) {
                Db::exec('UPDATE rate_limits SET window_start = ?, hits = 1 WHERE rl_key = ?', [$now, $key]);
                return true;
            }
            if ((int) $row['hits'] >= $max) {
                return false;
            }
            Db::exec('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
            return true;
        });
    }

    /** Check a configured limit (config rate_limits.<name> = [max, window]). */
    public static function check(string $name, string $subject): bool
    {
        $cfg = Config::get('rate_limits.' . $name, [10, 3600]);
        return self::hit($name, $subject, (int) $cfg[0], (int) $cfg[1]);
    }
}
