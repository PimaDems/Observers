<?php
declare(strict_types=1);

/** Shift generation and shift/coverage queries. */
final class Shifts
{
    /** Open/close for a site on a day: manual override > imported hours (optional) > site default > config. */
    public static function hoursFor(array $site, string $day, bool $useImported = false): array
    {
        $sources = $useImported ? ['manual', 'import'] : ['manual'];
        foreach ($sources as $src) {
            $h = Db::one('SELECT start_time, end_time FROM site_hours WHERE site_id = ? AND day = ? AND source = ?', [$site['id'], $day, $src]);
            if ($h) {
                return [$h['start_time'], $h['end_time']];
            }
        }
        if (!empty($site['default_start_time']) && !empty($site['default_end_time'])) {
            return [$site['default_start_time'], $site['default_end_time']];
        }
        return [(string) Config::get('shifts.default_start', '07:00'), (string) Config::get('shifts.default_end', '19:00')];
    }

    /** @return array<int,array{0:string,1:string}> list of [start,end] 'H:i' windows */
    public static function windows(string $start, string $end, int $lengthHours): array
    {
        $toMin = static fn(string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        $s = $toMin($start);
        $e = $toMin($end);
        $len = max(1, $lengthHours) * 60;
        $out = [];
        for ($cur = $s; $cur < $e; $cur += $len) {
            $out[] = [$cur, min($cur + $len, $e)];
        }
        if (count($out) > 1 && $out[count($out) - 1][1] - $out[count($out) - 1][0] < 60) {
            $last = array_pop($out);
            $out[count($out) - 1][1] = $last[1];
        }
        $fmt = static fn(int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        return array_map(static fn($w) => [$fmt($w[0]), $fmt($w[1])], $out);
    }

    /**
     * Generate shifts for sites of the given types over a date range. Idempotent.
     * @param string[] $types site types
     * @param array $opt site_id?, role_codes?, length_hours?, use_imported_hours?, max_volunteers?, target_coverage?
     * @return array{created:int,existing:int}
     */
    public static function generate(array $types, string $from, string $to, array $opt = []): array
    {
        if (!Util::validDate($from) || !Util::validDate($to) || $from > $to) {
            throw new InvalidArgumentException('Invalid date range');
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 120) {
            throw new InvalidArgumentException('Date range too long (max 120 days)');
        }
        $length = (int) ($opt['length_hours'] ?? Config::get('shifts.length_hours', 2));
        $target = (int) ($opt['target_coverage'] ?? Config::get('shifts.default_target_coverage', 2));
        $max = isset($opt['max_volunteers']) && $opt['max_volunteers'] !== '' ? max(1, (int) $opt['max_volunteers']) : null;
        $sql = 'SELECT * FROM sites WHERE is_active = 1';
        $params = [];
        if (!empty($opt['site_id'])) {
            $sql .= ' AND id = ?';
            $params[] = (int) $opt['site_id'];
        } elseif ($types) {
            $sql .= ' AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            array_push($params, ...array_values($types));
        } else {
            return ['created' => 0, 'existing' => 0];
        }
        $created = $existing = 0;
        Db::transaction(function () use ($sql, $params, $from, $to, $opt, $length, $target, $max, &$created, &$existing) {
            foreach (Db::all($sql, $params) as $site) {
                $roles = Catalog::rolesForSite($site);
                if (!empty($opt['role_codes'])) {
                    $roles = array_values(array_intersect($roles, $opt['role_codes']));
                }
                for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
                    [$open, $close] = self::hoursFor($site, $day, !empty($opt['use_imported_hours']));
                    foreach (self::windows($open, $close, $length) as [$s, $e]) {
                        foreach ($roles as $code) {
                            $roleId = Catalog::roleId($code);
                            $startsAt = "$day $s:00";
                            if (Db::val('SELECT id FROM shifts WHERE site_id = ? AND role_id = ? AND starts_at = ?', [$site['id'], $roleId, $startsAt])) {
                                $existing++;
                                continue;
                            }
                            Db::exec(
                                'INSERT INTO shifts (site_id, role_id, starts_at, ends_at, max_volunteers, target_coverage, created_at) VALUES (?,?,?,?,?,?,?)',
                                [$site['id'], $roleId, $startsAt, "$day $e:00", $max, $target, Util::now()]
                            );
                            $created++;
                        }
                    }
                }
            }
        });
        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * Shifts with occupancy and coverage. Filters: affiliation, site_id, day, site_type, role_code, future_only, include_inactive.
     * Public callers never get volunteer details, only counts.
     */
    public static function query(array $f = []): array
    {
        $now = Util::now();
        $sql = "SELECT sh.id, sh.site_id, sh.role_id, sh.starts_at, sh.ends_at, sh.max_volunteers, sh.target_coverage, sh.is_active,
                si.name AS site_name, si.type AS site_type, si.address, si.lat, si.lng,
                r.code AS role_code, r.name AS role_name, r.affiliation,
                CASE WHEN r.external_only = 1 OR EXISTS (SELECT 1 FROM coordinator_sites cs WHERE cs.site_id = sh.site_id AND cs.role_id = sh.role_id)
                     THEN 1 ELSE 0 END AS is_external,
                (SELECT COUNT(*) FROM signups sg WHERE sg.shift_id = sh.id AND sg.status = 'confirmed') AS confirmed,
                (SELECT COUNT(*) FROM signups sg WHERE sg.shift_id = sh.id AND sg.status = 'pending' AND sg.hold_expires_at > ?) AS held,
                (SELECT COUNT(*) FROM signups sg WHERE sg.shift_id = sh.id AND sg.status = 'interest') AS interested,
                (SELECT COALESCE(SUM(cr.volunteer_count), 0) FROM coordinator_reports cr
                  WHERE cr.site_id = sh.site_id AND cr.role_id = sh.role_id AND cr.starts_at <= sh.starts_at AND cr.ends_at >= sh.ends_at) AS reported
                FROM shifts sh JOIN sites si ON si.id = sh.site_id JOIN roles r ON r.id = sh.role_id
                WHERE si.is_active = 1";
        $p = [$now];
        if (empty($f['include_inactive'])) {
            $sql .= ' AND sh.is_active = 1';
        }
        if (!empty($f['affiliation'])) {
            $sql .= ' AND r.affiliation = ?';
            $p[] = $f['affiliation'];
        }
        if (!empty($f['site_id'])) {
            $sql .= ' AND sh.site_id = ?';
            $p[] = (int) $f['site_id'];
        }
        if (!empty($f['site_type'])) {
            $sql .= ' AND si.type = ?';
            $p[] = $f['site_type'];
        }
        if (!empty($f['role_code'])) {
            $sql .= ' AND r.code = ?';
            $p[] = $f['role_code'];
        }
        if (!empty($f['day'])) {
            $sql .= ' AND sh.starts_at >= ? AND sh.starts_at < ?';
            $p[] = $f['day'] . ' 00:00:00';
            $p[] = date('Y-m-d', strtotime($f['day'] . ' +1 day')) . ' 00:00:00';
        }
        if (!empty($f['future_only'])) {
            $sql .= ' AND sh.starts_at > ?';
            $p[] = $now;
        }
        $sql .= ' ORDER BY sh.starts_at, si.name, r.id';
        return array_map([self::class, 'decorate'], Db::all($sql, $p));
    }

    public static function decorate(array $s): array
    {
        $s['is_external'] = (int) $s['is_external'] === 1;
        $s['covered'] = (int) $s['confirmed'] + (int) $s['reported'];
        $target = max(1, (int) $s['target_coverage']);
        $s['level'] = $s['covered'] <= 0 ? 'none' : ($s['covered'] < $target ? 'low' : 'ok');
        $s['full'] = !$s['is_external'] && $s['max_volunteers'] !== null
            && (int) $s['confirmed'] + (int) $s['held'] >= (int) $s['max_volunteers'];
        $s['remaining'] = ($s['is_external'] || $s['max_volunteers'] === null) ? null
            : max(0, (int) $s['max_volunteers'] - (int) $s['confirmed'] - (int) $s['held']);
        return $s;
    }

    public static function levelLabel(string $level): string
    {
        return ['none' => 'No coverage', 'low' => 'Low coverage', 'ok' => 'Covered'][$level] ?? $level;
    }
}
