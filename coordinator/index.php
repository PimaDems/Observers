<?php
declare(strict_types=1);
/** Tokenised coordinator page (no login): interested volunteers, coverage CSV upload. */
require __DIR__ . '/../lib/bootstrap.php';
Security::startSession();
Security::headers();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$t = (string) ($_REQUEST['t'] ?? '');
if (!RateLimiter::check('verify_ip', Util::ip())) {
    http_response_code(429);
    View::message('Too many requests', '<p>Please wait a few minutes.</p>');
    exit;
}
$c = Coordinators::byToken($t);
if (!$c) {
    http_response_code(404);
    View::message('Link not valid', '<p>This coordinator link is not valid. Contact the administrator for a new one.</p>');
    exit;
}
$cid = (int) $c['id'];

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="coverage-template.csv"');
    $out = fopen('php://output', 'w');
    Exports::writeCsv($out, ['site_id', 'site', 'role', 'date', 'start', 'end', 'volunteers'], array_map(static fn($s) => [
        $s['site_id'], $s['name'], $s['role_code'], '', '07:00', '09:00', 1,
    ], Coordinators::sites($cid)));
    exit;
}

$result = null;
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Security::csrfOk()) {
        $errors[] = 'Session expired. Please try again.';
    } else {
        $file = $_FILES['csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > 1024 * 1024) {
            $errors[] = 'Please choose a CSV file under 1 MB.';
        } else {
            $result = Db::transaction(static fn() => Coordinators::importReport($cid, (string) file_get_contents($file['tmp_name'])));
            Util::audit(null, 'coordinator_report', "coordinator $cid saved={$result['saved']} errors=" . count($result['errors']));
        }
    }
}

View::header('Coordinator page');
echo '<h1>Coordinator: ' . View::h($c['name']) . '</h1><p class="muted">This page is private - do not share the link. It shows volunteer contact details for your locations only.</p>';
View::errors($errors);
if ($result) {
    echo '<p class="alert ok">Saved ' . (int) $result['saved'] . ' row(s).</p>';
    View::errors(array_slice($result['errors'], 0, 50));
}
echo '<h2>Your locations</h2><ul>';
foreach (Coordinators::sites($cid) as $s) {
    echo '<li>' . View::h($s['name'] . ' - ' . $s['role_name'] . ($s['address'] ? ' (' . $s['address'] . ')' : '')) . ' <span class="muted">site_id ' . (int) $s['site_id'] . '</span></li>';
}
echo '</ul><h2>Report coverage</h2><p>Upload a CSV listing where volunteers are scheduled: columns <code>site_id</code> (or <code>site</code>), <code>date</code>, <code>start</code>, <code>end</code>, <code>volunteers</code> (count; 0 removes an earlier row). '
    . 'Uploading the same site/time again replaces the earlier number. <a href="?t=' . View::h($t) . '&amp;template=1">Download template</a></p>'
    . '<form method="post" enctype="multipart/form-data" class="card">' . Security::csrfField() . '<input type="hidden" name="t" value="' . View::h($t) . '">'
    . '<label>CSV file <input type="file" name="csv" accept=".csv,text/csv" required></label><button class="primary">Upload</button></form>';

echo '<h2>Volunteers who want to help</h2><div class="scroll"><table><tr><th>Site</th><th>When</th><th>Name</th><th>Email</th><th>Phone</th></tr>';
foreach (Coordinators::interested($cid) as $r) {
    echo '<tr><td>' . View::h($r['site_name']) . '</td><td>' . View::h(Util::fmtRange($r['starts_at'], $r['ends_at'])) . '</td><td>' . View::h($r['name'])
        . '</td><td>' . View::h($r['email']) . '</td><td>' . View::h($r['phone_e164']) . '</td></tr>';
}
echo '</table></div><h2>Coverage you reported</h2><div class="scroll"><table><tr><th>Site</th><th>Role</th><th>When</th><th>Volunteers</th></tr>';
foreach (Coordinators::reports($cid) as $r) {
    echo '<tr><td>' . View::h($r['site_name']) . '</td><td>' . View::h($r['role_name']) . '</td><td>' . View::h(Util::fmtRange($r['starts_at'], $r['ends_at'])) . '</td><td>' . (int) $r['volunteer_count'] . '</td></tr>';
}
echo '</table></div>';
View::footer();
