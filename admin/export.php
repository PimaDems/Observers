<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$f = AdminUi::filters();
if (isset($_GET['download'])) {
    $rows = Exports::signups($f + ['status' => 'all']);
    $rows = array_values(array_filter($rows, static fn($r) => in_array($r['status'], ['confirmed', 'interest'], true)));
    Util::audit((int) $admin['id'], 'export_volunteers', json_encode($f) . ' rows=' . count($rows));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="volunteers-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    Exports::writeCsv($out, Exports::VOLUNTEER_HEADERS, Exports::volunteerRows($rows));
    exit;
}
AdminUi::header('Export volunteers (CSV)', $admin);
echo '<p>Download confirmed volunteers (and coordinator-forwarded interest) filtered by site and/or date. Leave filters blank for everything.</p><form method="get" class="inline"><input type="hidden" name="download" value="1">'
    . '<label>Group <select name="group"><option value="">Both</option><option value="partisan">Partisan</option><option value="nonpartisan">Non-Partisan</option></select></label>'
    . '<label>Site <select name="site_id">' . AdminUi::siteOptions() . '</select></label>'
    . '<label>Date <input type="date" name="day" style="display:inline-block;width:auto"></label> <button class="primary">Download CSV</button></form>';
AdminUi::footer();
