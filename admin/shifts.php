<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$msg = null;
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requirePost();
    $act = (string) ($_POST['action'] ?? '');
    try {
        if ($act === 'generate') {
            $types = array_values(array_intersect((array) ($_POST['types'] ?? []), array_keys(Catalog::TYPES)));
            $roles = array_values(array_intersect((array) ($_POST['roles'] ?? []), array_keys(Catalog::ROLES)));
            $opt = [
                'site_id' => (int) ($_POST['site_id'] ?? 0), 'role_codes' => $roles, 'length_hours' => max(1, min(12, (int) ($_POST['length'] ?? 2))),
                'use_imported_hours' => !empty($_POST['use_imported']), 'max_volunteers' => $_POST['max'] ?? '',
                'target_coverage' => max(1, (int) ($_POST['target'] ?? 2)),
            ];
            $r = Shifts::generate($types, (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''), $opt);
            Util::audit((int) $admin['id'], 'shifts_generate', json_encode([$types, $_POST['from'] ?? '', $_POST['to'] ?? '', $r]));
            $msg = "Created {$r['created']} shift(s); {$r['existing']} already existed.";
        } elseif ($act === 'update') {
            $n = 0;
            foreach ((array) ($_POST['row'] ?? []) as $id => $row) {
                $max = trim((string) ($row['max'] ?? ''));
                Db::exec('UPDATE shifts SET max_volunteers = ?, target_coverage = ?, is_active = ? WHERE id = ?', [
                    $max === '' ? null : max(1, (int) $max), max(1, (int) ($row['target'] ?? 1)), empty($row['active']) ? 0 : 1, (int) $id,
                ]);
                $n++;
            }
            Util::audit((int) $admin['id'], 'shifts_update', "$n rows");
            $msg = "Updated $n shift(s).";
        } elseif ($act === 'hours') {
            $site = (int) ($_POST['site_id'] ?? 0);
            $day = (string) ($_POST['day'] ?? '');
            $s = SiteImporter::parseTime((string) ($_POST['start'] ?? ''));
            $e = SiteImporter::parseTime((string) ($_POST['end'] ?? ''));
            if (!$site || !Util::validDate($day) || !$s || !$e || $s >= $e) {
                throw new InvalidArgumentException('Provide a site, a date and valid start < end times.');
            }
            Db::exec("DELETE FROM site_hours WHERE site_id = ? AND day = ? AND source = 'manual'", [$site, $day]);
            Db::exec("INSERT INTO site_hours (site_id, day, start_time, end_time, source) VALUES (?,?,?,?, 'manual')", [$site, $day, $s, $e]);
            $msg = 'Hours override saved; it applies to shifts generated from now on.';
        }
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
}

AdminUi::header('Shifts', $admin);
AdminUi::flash($msg, $errors);
$d = static fn(string $k, string $def = '') => View::h((string) ($_POST[$k] ?? $def));
echo '<h3>Generate shifts</h3><form method="post" class="card">' . Security::csrfField() . '<input type="hidden" name="action" value="generate">'
    . '<fieldset><legend>Site types</legend>';
foreach (Catalog::TYPES as $k => $label) {
    echo '<label><input type="checkbox" name="types[]" value="' . $k . '"> ' . View::h($label) . '</label>';
}
echo '</fieldset><label>Or just one site <select name="site_id">' . AdminUi::siteOptions(0, '(use types above)') . '</select></label><fieldset><legend>Roles (blank = all roles valid for the site)</legend>';
foreach (Catalog::ROLES as $k => [$name]) {
    echo '<label><input type="checkbox" name="roles[]" value="' . $k . '"> ' . View::h($name) . '</label>';
}
echo '</fieldset><label>From <input type="date" name="from" required value="' . $d('from') . '"></label><label>To <input type="date" name="to" required value="' . $d('to') . '"></label>'
    . '<label>Shift length (hours) <input type="number" name="length" min="1" max="12" value="' . $d('length', (string) Config::get('shifts.length_hours', 2)) . '"></label>'
    . '<label>Max volunteers per shift (blank = unlimited) <input type="number" name="max" min="1" value="' . $d('max') . '"></label>'
    . '<label>Target coverage (green at or above) <input type="number" name="target" min="1" value="' . $d('target', (string) Config::get('shifts.default_target_coverage', 2)) . '"></label>'
    . '<label><input type="checkbox" name="use_imported" value="1"> Use site open hours from imported CSVs where available (otherwise ' . View::h(Config::get('shifts.default_start', '07:00') . '–' . Config::get('shifts.default_end', '19:00')) . ')</label>'
    . '<button class="primary">Generate</button></form>';

echo '<h3>Hours override for one site/date</h3><form method="post" class="card">' . Security::csrfField() . '<input type="hidden" name="action" value="hours">'
    . '<label>Site <select name="site_id">' . AdminUi::siteOptions(0, 'Choose…') . '</select></label><label>Date <input type="date" name="day" required></label>'
    . '<label>Start <input name="start" placeholder="07:00" required></label><label>End <input name="end" placeholder="19:00" required></label><button>Save override</button></form>';

$f = AdminUi::filters();
echo '<h3>Existing shifts</h3>';
AdminUi::filterForm($f);
$shifts = Shifts::query($f + ['include_inactive' => true]);
if ($f['day'] || $f['site_id'] || $f['affiliation']) {
    echo '<form method="post">' . Security::csrfField() . '<input type="hidden" name="action" value="update"><div class="scroll"><table><tr><th>When</th><th>Site</th><th>Role</th><th>Confirmed</th><th>Cap</th><th>Target</th><th>Active</th></tr>';
    foreach (array_slice($shifts, 0, 200) as $s) {
        $i = (int) $s['id'];
        echo '<tr><td>' . View::h(Util::fmtRange($s['starts_at'], $s['ends_at'])) . '</td><td>' . View::h($s['site_name']) . '</td><td>' . View::h($s['role_name'])
            . '</td><td>' . (int) $s['confirmed'] . '</td><td><input type="number" min="1" name="row[' . $i . '][max]" value="' . View::h((string) ($s['max_volunteers'] ?? '')) . '" style="width:5rem"></td>'
            . '<td><input type="number" min="1" name="row[' . $i . '][target]" value="' . (int) $s['target_coverage'] . '" style="width:5rem"></td>'
            . '<td><input type="checkbox" name="row[' . $i . '][active]" value="1"' . ($s['is_active'] ? ' checked' : '') . '></td></tr>';
    }
    echo '</table></div>' . (count($shifts) > 200 ? '<p class="muted">Showing 200 of ' . count($shifts) . '.</p>' : '') . '<button class="primary">Save changes</button></form>';
} else {
    echo '<p class="muted">Pick a group, site or date to list shifts.</p>';
}
AdminUi::footer();
