<?php
declare(strict_types=1);

/** Static knowledge about site types, roles and who may sign up where. */
final class Catalog
{
    public const TYPES = [
        'election_day' => 'Election Day Polling Location',
        'early_vote' => 'Early Vote Center',
        'drop_box' => 'Ballot Drop Box',
        'recorder_office' => "Recorder's Office",
        'elections_office' => 'Elections Office',
        'roaming' => 'Roaming',
    ];

    /** role code => [name, affiliation, external_only] */
    public const ROLES = [
        'np_observer' => ['Non-Partisan Observer', 'nonpartisan', 0],
        'p_electioneer' => ['Partisan Electioneering Volunteer', 'partisan', 0],
        'p_observer' => ['Partisan Observer (coordinated externally)', 'partisan', 1],
    ];

    /** Roles that exist at each site type. */
    public const TYPE_ROLES = [
        'election_day' => ['np_observer', 'p_electioneer', 'p_observer'],
        'early_vote' => ['np_observer', 'p_electioneer', 'p_observer'],
        'drop_box' => ['np_observer'],
        'recorder_office' => ['p_observer'],
        'elections_office' => ['p_observer'],
        'roaming' => ['np_observer', 'p_electioneer'],
    ];

    /** Public entry point => role affiliation shown there. */
    public const GROUPS = ['partisan' => 'partisan', 'nonpartisan' => 'nonpartisan'];

    public static function siteKey(string $type, string $name, string $address): string
    {
        $n = static fn(string $s): string => mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));
        return sha1($type . '|' . $n($name) . '|' . $n($address));
    }

    public static function rolesForSite(array $site): array
    {
        if (!empty($site['role_codes'])) {
            return array_values(array_intersect(array_map('trim', explode(',', $site['role_codes'])), array_keys(self::ROLES)));
        }
        return self::TYPE_ROLES[$site['type']] ?? [];
    }

    public static function roleId(string $code): int
    {
        return (int) Db::val('SELECT id FROM roles WHERE code = ?', [$code]);
    }

    /** Insert roles and fixed/virtual sites. Idempotent. */
    public static function seed(): void
    {
        foreach (self::ROLES as $code => [$name, $aff, $ext]) {
            if (!Db::val('SELECT id FROM roles WHERE code = ?', [$code])) {
                Db::exec('INSERT INTO roles (code, name, affiliation, external_only) VALUES (?,?,?,?)', [$code, $name, $aff, $ext]);
            }
        }
        $fixed = [
            ['recorder_office', "Pima County Recorder's Office", '240 N Stone Ave, Tucson, AZ 85701', 'p_observer'],
            ['elections_office', 'Pima County Elections Office', '6550 S Country Club Rd, Tucson, AZ 85706', 'p_observer'],
            ['roaming', 'Roaming Observers (Non-Partisan)', '', 'np_observer'],
            ['roaming', 'Roaming Volunteers (Partisan)', '', 'p_electioneer'],
        ];
        foreach ($fixed as [$type, $name, $addr, $roles]) {
            $key = self::siteKey($type, $name, $addr);
            if (!Db::val('SELECT id FROM sites WHERE site_key = ?', [$key])) {
                Db::exec(
                    'INSERT INTO sites (site_key, type, name, address, role_codes, created_at, updated_at) VALUES (?,?,?,?,?,?,?)',
                    [$key, $type, $name, $addr, $roles, Util::now(), Util::now()]
                );
            }
        }
    }

    /** Coordinator row for a site+role, or null. */
    public static function coordinatorFor(int $siteId, int $roleId): ?array
    {
        return Db::one(
            'SELECT c.* FROM coordinator_sites cs JOIN coordinators c ON c.id = cs.coordinator_id
             WHERE cs.site_id = ? AND cs.role_id = ? AND c.is_active = 1',
            [$siteId, $roleId]
        );
    }

    /** External = signups are forwarded to a coordinator instead of booked here. */
    public static function isExternal(int $siteId, int $roleId): bool
    {
        if ((int) Db::val('SELECT external_only FROM roles WHERE id = ?', [$roleId]) === 1) {
            return true;
        }
        return (bool) Db::val('SELECT 1 FROM coordinator_sites WHERE site_id = ? AND role_id = ?', [$siteId, $roleId]);
    }
}
