<?php
declare(strict_types=1);

/** Flexible CSV importer for sites. Idempotent: upserts by type + name + address. */
final class SiteImporter
{
    private const ALIASES = [
        'name' => ['name', 'sitename', 'location', 'locationname', 'site', 'pollingplace', 'pollingplacename', 'facility', 'facilityname', 'title'],
        'address' => ['address', 'streetaddress', 'street', 'address1', 'physicaladdress', 'locationaddress', 'addr'],
        'city' => ['city', 'town'],
        'zip' => ['zip', 'zipcode', 'postalcode', 'postal'],
        'lat' => ['lat', 'latitude', 'y'],
        'lng' => ['lng', 'lon', 'long', 'longitude', 'x'],
        'precinct' => ['precinct', 'precincts', 'precinctname', 'precinctnumber', 'precinctno'],
        'hours' => ['hours', 'hoursofoperation', 'openhours', 'schedule', 'operatinghours'],
        'notes' => ['notes', 'description', 'locationdescription', 'comments'],
        'box_type' => ['boxtype', 'type'],
        'date' => ['date', 'electiondate', 'day'],
        'start_date' => ['startdate', 'begindate', 'firstday', 'datestart', 'opendate'],
        'end_date' => ['enddate', 'lastday', 'dateend', 'closedate'],
        'start_hour' => ['starthour', 'hourstart', 'starttime', 'timestart', 'open', 'opens', 'opentime', 'openinghour'],
        'end_hour' => ['endhour', 'hourend', 'endtime', 'timeend', 'close', 'closes', 'closetime', 'closinghour'],
    ];

    public static function guessType(string $filename): ?string
    {
        $f = strtolower($filename);
        if (str_contains($f, 'drop') || str_contains($f, 'ballot')) {
            return 'drop_box';
        }
        if (str_contains($f, 'early')) {
            return 'early_vote';
        }
        if (str_contains($f, 'poll') || str_contains($f, 'election day') || str_contains($f, 'election_day')) {
            return 'election_day';
        }
        return null;
    }

    /** @return array{inserted:int,updated:int,skipped:int,missing_coords:int,errors:string[],columns:array} */
    public static function importFile(string $path, string $type): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read $path");
        }
        return self::importString($raw, $type);
    }

    public static function importString(string $raw, string $type): array
    {
        if (!isset(Catalog::TYPES[$type]) || $type === 'roaming') {
            throw new InvalidArgumentException("Unsupported site type: $type");
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $header = fgetcsv($fh, 0, ',', '"', '');
        $res = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'missing_coords' => 0, 'errors' => [], 'columns' => []];
        if (!$header) {
            $res['errors'][] = 'Empty file';
            return $res;
        }
        $map = self::mapHeader($header);
        $res['columns'] = $map;
        if (!isset($map['name'])) {
            $res['errors'][] = 'No name column found. Headers: ' . implode(', ', $header);
            return $res;
        }
        $line = 1;
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if ($row === [null] || count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rec = [];
            foreach ($map as $field => $idx) {
                $rec[$field] = trim((string) ($row[$idx] ?? ''));
            }
            if (($rec['name'] ?? '') === '') {
                $res['skipped']++;
                $res['errors'][] = "Line $line: missing name";
                continue;
            }
            try {
                $r = Db::transaction(static fn() => self::upsert($type, $rec));
                $res[$r['action']]++;
                if ($r['missing_coords']) {
                    $res['missing_coords']++;
                }
            } catch (Throwable $e) {
                $res['skipped']++;
                $res['errors'][] = "Line $line: " . $e->getMessage();
            }
        }
        fclose($fh);
        return $res;
    }

    /** @return array<string,int> canonical field => column index */
    public static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $norm = preg_replace('/[^a-z0-9]/', '', strtolower((string) $h));
            foreach (self::ALIASES as $field => $aliases) {
                if (!isset($map[$field]) && in_array($norm, $aliases, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    public static function parseTime(?string $s): ?string
    {
        $s = strtolower(trim((string) $s));
        if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm|a|p)?$/', $s, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) ($m[2] ?? 0);
        if (!empty($m[3])) {
            if ($h < 1 || $h > 12) {
                return null;
            }
            $h = ($h % 12) + ($m[3][0] === 'p' ? 12 : 0);
        }
        return ($h > 23 || $min > 59) ? null : sprintf('%02d:%02d', $h, $min);
    }

    public static function parseDate(?string $s): ?string
    {
        $s = trim((string) $s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $s, $m)) {
            [$mo, $d, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y += $y < 100 ? 2000 : 0;
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private static function coord(string $v, float $min, float $max): ?string
    {
        if ($v === '' || !is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return ($f >= $min && $f <= $max && $f != 0.0) ? number_format($f, 6, '.', '') : null;
    }

    private static function upsert(string $type, array $rec): array
    {
        $name = $rec['name'];
        $address = $rec['address'] ?? '';
        $city = $rec['city'] ?? '';
        $zip = $rec['zip'] ?? '';
        if (($city === '' || $zip === '') && preg_match('/,\s*([^,]+),\s*[A-Za-z]{2}(?:\s+(\d{5})(?:-\d{4})?)?\s*$/', $address, $m)) {
            $city = $city ?: trim($m[1]);
            $zip = $zip ?: ($m[2] ?? '');
        }
        $lat = self::coord($rec['lat'] ?? '', -90, 90);
        $lng = self::coord($rec['lng'] ?? '', -180, 180);
        if ($lat === null || $lng === null) {
            $lat = $lng = null;
        }
        $notes = trim(implode(' — ', array_filter([$rec['box_type'] ?? '', $rec['notes'] ?? ''])));
        $hours = $rec['hours'] ?? '';
        $start = self::parseTime($rec['start_hour'] ?? '');
        $end = self::parseTime($rec['end_hour'] ?? '');

        $key = Catalog::siteKey($type, $name, $address);
        $existing = Db::one('SELECT * FROM sites WHERE site_key = ?', [$key]);
        $now = Util::now();
        if ($existing) {
            $siteId = (int) $existing['id'];
            Db::exec(
                'UPDATE sites SET city = ?, zip = ?, precinct = ?, hours_text = ?, notes = ?, lat = ?, lng = ?, updated_at = ? WHERE id = ?',
                [
                    $city !== '' ? $city : $existing['city'],
                    $zip !== '' ? $zip : $existing['zip'],
                    ($rec['precinct'] ?? '') !== '' ? $rec['precinct'] : $existing['precinct'],
                    $hours !== '' ? $hours : $existing['hours_text'],
                    $notes !== '' ? $notes : $existing['notes'],
                    $lat ?? $existing['lat'],
                    $lng ?? $existing['lng'],
                    $now,
                    $siteId,
                ]
            );
            $action = 'updated';
            $missing = ($lat ?? $existing['lat']) === null;
        } else {
            $siteId = Db::insert(
                'INSERT INTO sites (site_key, type, name, address, city, zip, precinct, hours_text, lat, lng, notes, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$key, $type, $name, $address, $city ?: null, $zip ?: null, ($rec['precinct'] ?? '') ?: null,
                 $hours ?: null, $lat, $lng, $notes ?: null, $now, $now]
            );
            $action = 'inserted';
            $missing = $lat === null;
        }

        if ($start !== null && $end !== null && $start < $end) {
            $single = self::parseDate($rec['date'] ?? '');
            $from = self::parseDate($rec['start_date'] ?? '') ?? $single;
            $to = self::parseDate($rec['end_date'] ?? '') ?? $single ?? $from;
            if ($from !== null && $to !== null && $from <= $to) {
                $day = $from;
                for ($i = 0; $i < 120 && $day <= $to; $i++) {
                    $row = Db::one("SELECT id FROM site_hours WHERE site_id = ? AND day = ? AND source = 'import'", [$siteId, $day]);
                    if ($row) {
                        Db::exec('UPDATE site_hours SET start_time = ?, end_time = ? WHERE id = ?', [$start, $end, $row['id']]);
                    } else {
                        Db::exec("INSERT INTO site_hours (site_id, day, start_time, end_time, source) VALUES (?,?,?,?, 'import')", [$siteId, $day, $start, $end]);
                    }
                    $day = date('Y-m-d', strtotime($day . ' +1 day'));
                }
            }
        }
        return ['action' => $action, 'missing_coords' => $missing];
    }
}
