<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$f = AdminUi::filters();
$rows = Exports::blastRows($f);

if (isset($_GET['download'])) {
    $kind = (string) $_GET['download'];
    Util::audit((int) $admin['id'], 'export_blast_' . $kind, json_encode($f) . ' rows=' . count($rows));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="blast-' . $kind . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($kind === 'text') { // one line per unique phone number, shifts joined
        $by = [];
        foreach ($rows as $r) {
            if ($r['phone_e164']) {
                $by[$r['phone_e164']][] = $r;
            }
        }
        Exports::writeCsv($out, ['phone', 'name', 'sites', 'times', 'roles'], array_map(static fn($p, $list) => [
            $p, $list[0]['name'], implode('; ', array_unique(array_column($list, 'site_name'))),
            implode('; ', array_map(static fn($r) => Util::fmtRange($r['starts_at'], $r['ends_at']), $list)),
            implode('; ', array_unique(array_column($list, 'role_name'))),
        ], array_keys($by), array_values($by)));
    } else {
        Exports::writeCsv($out, Exports::BLAST_HEADERS, Exports::blastCsvRows($rows));
    }
    exit;
}

$msg = null;
$errors = [];
$byEmail = Exports::groupByEmail($rows);
$batch = max(1, (int) Config::get('mail.blast_batch_size', 40));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requirePost();
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = (string) ($_POST['body'] ?? '');
    $offset = max(0, (int) ($_POST['offset'] ?? 0));
    if ($subject === '' || trim($body) === '') {
        $errors[] = 'Subject and message are required.';
    } else {
        $slice = array_slice($byEmail, $offset, $batch, true);
        $sent = $failed = 0;
        foreach ($slice as $email => $g) {
            $shiftText = implode("\n", array_map(static fn($r) => '- ' . Util::fmtRange($r['starts_at'], $r['ends_at']) . ' at ' . $r['site_name'] . ' (' . $r['role_name'] . ')', $g['shifts']));
            $text = str_replace(['{name}', '{shifts}'], [$g['name'], $shiftText], $body);
            Mailer::instance()->send((string) $email, $subject, $text, 'blast', null) ? $sent++ : $failed++;
        }
        Util::audit((int) $admin['id'], 'blast_email', json_encode($f) . " offset=$offset sent=$sent failed=$failed");
        $next = $offset + $batch;
        $msg = "Batch sent: $sent ok, $failed failed. " . ($next < count($byEmail) ? 'Use the form again with offset ' . $next . ' for the next batch.' : 'All recipients processed.');
        $_POST['offset'] = $next < count($byEmail) ? $next : 0;
    }
}

AdminUi::header('Blast export & email', $admin);
AdminUi::flash($msg, $errors);
AdminUi::filterForm($f);
$q = http_build_query(array_filter(['group' => $f['affiliation'], 'site_id' => $f['site_id'] ?: '', 'day' => $f['day']]));
echo '<p>' . count($rows) . ' shift assignment(s), ' . count($byEmail) . ' unique email(s) match (verified emails only).</p>'
    . '<p><a class="button" href="?download=email' . ($q ? '&' . View::h($q) : '') . '">Download email list (CSV)</a> '
    . '<a class="button" href="?download=text' . ($q ? '&' . View::h($q) : '') . '">Download text-blast list (CSV)</a></p>'
    . '<p class="muted">The text-blast list has one row per phone number (E.164) for use in a mass-texting tool. SMS sending from this app is disabled until a provider is configured.</p>';
echo '<h3>Send email blast via SMTP</h3><form method="post" action="?' . View::h($q) . '" class="card">' . Security::csrfField()
    . '<label>Subject <input name="subject" maxlength="200" required value="' . View::h((string) ($_POST['subject'] ?? '')) . '"></label>'
    . '<label>Message (use {name} and {shifts}) <textarea name="body" rows="8" style="max-width:40rem;width:100%" required>' . View::h((string) ($_POST['body'] ?? "Hi {name},\n\nReminder of your upcoming shifts:\n{shifts}\n\nThank you!")) . '</textarea></label>'
    . '<label>Start at recipient # (batch size ' . $batch . ') <input type="number" min="0" name="offset" value="' . (int) ($_POST['offset'] ?? 0) . '"></label>'
    . '<button class="primary">Send next batch</button></form>';
AdminUi::footer();
