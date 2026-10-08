<?php
declare(strict_types=1);

/** Volunteer exports (PII; admin only). */
final class Exports
{
    /** Confirmed/interest signups with volunteer details. Filters: site_id, day, affiliation, status */
    public static function signups(array $f = []): array
    {
        $sql = "SELECT sg.id AS signup_id, sg.status, v.name, v.email, v.phone, v.phone_e164, v.phone_status, v.email_verified_at,
                       si.id AS site_id, si.name AS site_name, si.address, si.type AS site_type,
                       sh.starts_at, sh.ends_at, r.name AS role_name, r.affiliation
                FROM signups sg JOIN volunteers v ON v.id = sg.volunteer_id JOIN shifts sh ON sh.id = sg.shift_id
                JOIN sites si ON si.id = sh.site_id JOIN roles r ON r.id = sh.role_id WHERE 1=1";
        $p = [];
        if (!empty($f['status'])) {
            if ($f['status'] === 'all') {
                // everything
            } else {
                $sql .= ' AND sg.status = ?';
                $p[] = $f['status'];
            }
        } else {
            $sql .= " AND sg.status IN ('confirmed','interest')";
        }
        if (!empty($f['site_id'])) {
            $sql .= ' AND si.id = ?';
            $p[] = (int) $f['site_id'];
        }
        if (!empty($f['affiliation'])) {
            $sql .= ' AND r.affiliation = ?';
            $p[] = $f['affiliation'];
        }
        if (!empty($f['day'])) {
            $sql .= ' AND sh.starts_at >= ? AND sh.starts_at < ?';
            $p[] = $f['day'] . ' 00:00:00';
            $p[] = date('Y-m-d', strtotime($f['day'] . ' +1 day')) . ' 00:00:00';
        }
        return Db::all($sql . ' ORDER BY sh.starts_at, si.name, v.name', $p);
    }

    /** Stream rows as CSV to $out. */
    public static function writeCsv($out, array $headers, array $rows): void
    {
        fputcsv($out, $headers, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map([Util::class, 'csvSafe'], $r), ',', '"', '');
        }
    }

    public static function volunteerRows(array $signups): array
    {
        return array_map(static fn($r) => [
            $r['name'], $r['email'], $r['phone_e164'] ?: $r['phone'], $r['phone_status'], $r['site_name'], $r['address'],
            date('Y-m-d', strtotime($r['starts_at'])), date('H:i', strtotime($r['starts_at'])), date('H:i', strtotime($r['ends_at'])),
            $r['role_name'], $r['status'],
        ], $signups);
    }

    public const VOLUNTEER_HEADERS = ['name', 'email', 'phone', 'phone_status', 'site', 'address', 'date', 'start', 'end', 'role', 'status'];

    /** Blast rows: email, phone, name, site, time, role (confirmed with verified email only). */
    public static function blastRows(array $f): array
    {
        $f['status'] = 'all';
        $rows = array_filter(self::signups($f), static fn($r) => in_array($r['status'], ['confirmed', 'interest'], true) && $r['email_verified_at']);
        return array_values($rows);
    }

    public const BLAST_HEADERS = ['email', 'phone', 'name', 'site', 'start', 'end', 'role'];

    public static function blastCsvRows(array $rows): array
    {
        return array_map(static fn($r) => [
            $r['email'], $r['phone_e164'], $r['name'], $r['site_name'], $r['starts_at'], $r['ends_at'], $r['role_name'],
        ], $rows);
    }

    /** One entry per unique email, with that volunteer's shifts for the blast filter. */
    public static function groupByEmail(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            $g[$r['email']]['name'] = $r['name'];
            $g[$r['email']]['shifts'][] = $r;
        }
        return $g;
    }
}
