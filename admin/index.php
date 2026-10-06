<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$f = AdminUi::filters();
AdminUi::header('Dashboard', $admin);
AdminUi::filterForm($f);

$shifts = Shifts::query($f + ['include_inactive' => false]);
$sum = [];
foreach ($shifts as $s) {
    $k = $s['affiliation'];
    $sum[$k]['shifts'] = ($sum[$k]['shifts'] ?? 0) + 1;
    $sum[$k][$s['level']] = ($sum[$k][$s['level']] ?? 0) + 1;
    $sum[$k]['confirmed'] = ($sum[$k]['confirmed'] ?? 0) + (int) $s['confirmed'];
    $sum[$k]['reported'] = ($sum[$k]['reported'] ?? 0) + (int) $s['reported'];
}
echo '<h3>Coverage summary</h3><table><tr><th>Group</th><th>Shifts</th><th>No coverage</th><th>Low</th><th>Covered</th><th>Confirmed volunteers</th><th>Externally reported</th></tr>';
foreach (['partisan' => 'Partisan', 'nonpartisan' => 'Non-Partisan'] as $k => $label) {
    $r = $sum[$k] ?? [];
    echo '<tr><td>' . $label . '</td>';
    foreach (['shifts', 'none', 'low', 'ok', 'confirmed', 'reported'] as $c) {
        echo '<td>' . (int) ($r[$c] ?? 0) . '</td>';
    }
    echo '</tr>';
}
echo '</table>';

$noGeo = (int) Db::val("SELECT COUNT(*) FROM sites WHERE is_active = 1 AND lat IS NULL AND type <> 'roaming'");
$unnotified = (int) Db::val("SELECT COUNT(*) FROM signups WHERE status = 'interest' AND coordinator_notified_at IS NULL");
echo '<h3>To do</h3><ul>'
    . '<li><a href="' . View::h(Util::url('admin/sites.php?missing_coords=1')) . '">' . $noGeo . ' site(s) missing coordinates</a> (geocode or enter manually)</li>'
    . '<li><a href="' . View::h(Util::url('admin/coordinators.php')) . '">' . $unnotified . ' interested volunteer signup(s) not yet forwarded to a coordinator</a></li></ul>';

echo '<h3>Shifts (' . count($shifts) . ')</h3><div class="scroll"><table><tr><th>When</th><th>Site</th><th>Role</th><th>Confirmed</th><th>Held</th><th>Reported</th><th>Cap</th><th>Coverage</th></tr>';
foreach (array_slice($shifts, 0, 300) as $s) {
    echo '<tr><td>' . View::h(Util::fmtRange($s['starts_at'], $s['ends_at'])) . '</td><td>' . View::h($s['site_name']) . '</td><td>' . View::h($s['role_name'])
        . '</td><td>' . (int) $s['confirmed'] . '</td><td>' . (int) $s['held'] . '</td><td>' . (int) $s['reported'] . '</td><td>' . ($s['max_volunteers'] ?? '&infin;')
        . '</td><td>' . View::badge($s['level']) . '</td></tr>';
}
echo '</table></div>' . (count($shifts) > 300 ? '<p class="muted">Showing first 300; narrow the filter.</p>' : '');
AdminUi::footer();
