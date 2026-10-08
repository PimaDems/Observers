<?php
declare(strict_types=1);

/** External coordinators: notification emails, tokenised access and coverage reports. */
final class Coordinators
{
    /** Create a coordinator; returns [id, rawToken]. The raw token is shown once. */
    public static function create(string $name, string $email): array
    {
        $raw = Util::token(20);
        $id = Db::insert(
            'INSERT INTO coordinators (name, email, token_hash, created_at) VALUES (?,?,?,?)',
            [$name, $email, Util::hashToken($raw), Util::now()]
        );
        return [$id, $raw];
    }

    public static function rotateToken(int $id): string
    {
        $raw = Util::token(20);
        Db::exec('UPDATE coordinators SET token_hash = ? WHERE id = ?', [Util::hashToken($raw), $id]);
        return $raw;
    }

    public static function byToken(string $raw): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $raw)) {
            return null;
        }
        return Db::one('SELECT * FROM coordinators WHERE token_hash = ? AND is_active = 1', [Util::hashToken($raw)]);
    }

    public static function assign(int $coordinatorId, int $siteId, int $roleId): void
    {
        Db::transaction(function () use ($coordinatorId, $siteId, $roleId) {
            Db::exec('DELETE FROM coordinator_sites WHERE site_id = ? AND role_id = ?', [$siteId, $roleId]);
            Db::exec('INSERT INTO coordinator_sites (site_id, role_id, coordinator_id) VALUES (?,?,?)', [$siteId, $roleId, $coordinatorId]);
        });
    }

    public static function sites(int $coordinatorId): array
    {
        return Db::all(
            'SELECT si.id AS site_id, si.name, si.address, si.type, r.id AS role_id, r.code AS role_code, r.name AS role_name
             FROM coordinator_sites cs JOIN sites si ON si.id = cs.site_id JOIN roles r ON r.id = cs.role_id
             WHERE cs.coordinator_id = ? ORDER BY si.name, r.id',
            [$coordinatorId]
        );
    }

    /**
     * Email coordinators about confirmed-interest signups not yet forwarded. Optionally limited to one volunteer.
     * @return int number of signups forwarded
     */
    public static function notifyPending(?int $volunteerId = null): int
    {
        $sql = "SELECT sg.id, sg.volunteer_id, sh.starts_at, sh.ends_at, sh.site_id, sh.role_id, si.name AS site_name, si.address, r.name AS role_name
                FROM signups sg JOIN shifts sh ON sh.id = sg.shift_id JOIN sites si ON si.id = sh.site_id JOIN roles r ON r.id = sh.role_id
                WHERE sg.status = 'interest' AND sg.coordinator_notified_at IS NULL";
        $p = [];
        if ($volunteerId !== null) {
            $sql .= ' AND sg.volunteer_id = ?';
            $p[] = $volunteerId;
        }
        $groups = [];
        foreach (Db::all($sql . ' ORDER BY sh.starts_at', $p) as $row) {
            $c = Catalog::coordinatorFor((int) $row['site_id'], (int) $row['role_id']);
            if ($c) {
                $groups[$c['id'] . ':' . $row['volunteer_id']]['c'] = $c;
                $groups[$c['id'] . ':' . $row['volunteer_id']]['rows'][] = $row;
            }
        }
        $sent = 0;
        foreach ($groups as $g) {
            $c = $g['c'];
            $v = Db::one('SELECT * FROM volunteers WHERE id = ?', [$g['rows'][0]['volunteer_id']]);
            $body = "Hello {$c['name']},\n\nA volunteer is interested in helping at a location you coordinate:\n\n"
                . "Name:  {$v['name']}\nEmail: {$v['email']} (verified)\nPhone: {$v['phone_e164']}\n\nShifts:\n";
            foreach ($g['rows'] as $r) {
                $body .= '* ' . Util::fmtRange($r['starts_at'], $r['ends_at']) . ' - ' . $r['site_name']
                    . ($r['address'] ? " ({$r['address']})" : '') . " [{$r['role_name']}]\n";
            }
            $body .= "\nPlease contact them directly. When you know who is covering which shifts, report it on your private coordinator page (use the link you were given):\n"
                . Util::absUrl('coordinator/') . "\n";
            if (Mailer::instance()->send($c['email'], 'New volunteer interest: ' . $g['rows'][0]['site_name'], $body, 'coordinator', (int) $v['id'], (int) $c['id'])) {
                $in = implode(',', array_fill(0, count($g['rows']), '?'));
                Db::exec("UPDATE signups SET coordinator_notified_at = ? WHERE id IN ($in)", array_merge([Util::now()], array_column($g['rows'], 'id')));
                $sent += count($g['rows']);
            }
        }
        return $sent;
    }

    /** Volunteer interest (PII) for a coordinator's sites. Only for the token-holder and admins. */
    public static function interested(int $coordinatorId): array
    {
        return Db::all(
            "SELECT si.name AS site_name, sh.starts_at, sh.ends_at, v.name, v.email, v.phone_e164
             FROM signups sg JOIN shifts sh ON sh.id = sg.shift_id JOIN sites si ON si.id = sh.site_id
             JOIN coordinator_sites cs ON cs.site_id = sh.site_id AND cs.role_id = sh.role_id
             JOIN volunteers v ON v.id = sg.volunteer_id
             WHERE cs.coordinator_id = ? AND sg.status = 'interest' ORDER BY sh.starts_at, si.name",
            [$coordinatorId]
        );
    }

    public static function reports(int $coordinatorId): array
    {
        return Db::all(
            'SELECT cr.*, si.name AS site_name, r.name AS role_name FROM coordinator_reports cr
             JOIN sites si ON si.id = cr.site_id JOIN roles r ON r.id = cr.role_id
             WHERE cr.coordinator_id = ? ORDER BY cr.starts_at, si.name',
            [$coordinatorId]
        );
    }

    /**
     * Import a coordinator's coverage CSV. Columns (flexible): site_id or site (name), date, start, end, volunteers (default 1), role?, note?
     * Existing rows for the same coordinator/site/role/time are replaced; volunteers=0 removes it.
     * @return array{saved:int,errors:string[]}
     */
    public static function importReport(int $coordinatorId, string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = fgetcsv($fh, 0, ',', '"', '');
        $out = ['saved' => 0, 'errors' => []];
        if (!$header) {
            $out['errors'][] = 'Empty file';
            return $out;
        }
        $aliases = [
            'site_id' => ['siteid', 'id'],
            'site' => ['site', 'sitename', 'name', 'location'],
            'date' => ['date', 'day'],
            'start' => ['start', 'starttime', 'from', 'hourstart', 'starthour'],
            'end' => ['end', 'endtime', 'to', 'hourend', 'endhour'],
            'count' => ['volunteers', 'count', 'numvolunteers', 'observers', 'covered'],
            'role' => ['role', 'rolecode'],
            'note' => ['note', 'notes', 'comment'],
        ];
        $map = [];
        foreach ($header as $i => $h) {
            $n = preg_replace('/[^a-z0-9]/', '', strtolower((string) $h));
            foreach ($aliases as $f => $al) {
                if (!isset($map[$f]) && in_array($n, $al, true)) {
                    $map[$f] = $i;
                    break;
                }
            }
        }
        if ((!isset($map['site']) && !isset($map['site_id'])) || !isset($map['date']) || !isset($map['start']) || !isset($map['end'])) {
            $out['errors'][] = 'Required columns: site (or site_id), date, start, end. Optional: volunteers, role, note.';
            return $out;
        }
        $assigned = self::sites($coordinatorId);
        $line = 1;
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $g = static fn(string $f): string => isset($map[$f]) ? trim((string) ($row[$map[$f]] ?? '')) : '';
            $date = SiteImporter::parseDate($g('date'));
            $start = SiteImporter::parseTime($g('start'));
            $end = SiteImporter::parseTime($g('end'));
            $count = $g('count') === '' ? 1 : (ctype_digit($g('count')) ? (int) $g('count') : -1);
            if ($date === null || $start === null || $end === null || $start >= $end || $count < 0 || $count > 500) {
                $out['errors'][] = "Line $line: invalid date/time/volunteer count";
                continue;
            }
            $cands = array_filter($assigned, static function ($a) use ($g) {
                if ($g('site_id') !== '') {
                    return (string) $a['site_id'] === $g('site_id');
                }
                return mb_strtolower($a['name']) === mb_strtolower($g('site'));
            });
            if ($g('role') !== '') {
                $cands = array_filter($cands, static fn($a) => strcasecmp($a['role_code'], $g('role')) === 0 || strcasecmp($a['role_name'], $g('role')) === 0);
            }
            $siteIds = array_unique(array_column($cands, 'site_id'));
            if (!$cands) {
                $out['errors'][] = "Line $line: site not assigned to you";
                continue;
            }
            if (count($siteIds) > 1) {
                $out['errors'][] = "Line $line: site name is ambiguous; use the site_id column";
                continue;
            }
            foreach ($cands as $a) {
                $keys = [$coordinatorId, $a['site_id'], $a['role_id'], "$date $start:00", "$date $end:00"];
                Db::exec('DELETE FROM coordinator_reports WHERE coordinator_id = ? AND site_id = ? AND role_id = ? AND starts_at = ? AND ends_at = ?', $keys);
                if ($count > 0) {
                    Db::exec(
                        'INSERT INTO coordinator_reports (coordinator_id, site_id, role_id, starts_at, ends_at, volunteer_count, note, created_at) VALUES (?,?,?,?,?,?,?,?)',
                        array_merge($keys, [$count, mb_substr($g('note'), 0, 255) ?: null, Util::now()])
                    );
                }
                $out['saved']++;
            }
        }
        fclose($fh);
        return $out;
    }
}
