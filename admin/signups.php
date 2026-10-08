<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$msg = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requirePost();
    if (($_POST['action'] ?? '') === 'cancel') {
        Db::exec("UPDATE signups SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status IN ('pending','confirmed','interest')", [Util::now(), (int) ($_POST['id'] ?? 0)]);
        Util::audit((int) $admin['id'], 'signup_cancel', 'signup ' . (int) ($_POST['id'] ?? 0));
        $msg = 'Signup cancelled.';
    }
}
$f = AdminUi::filters();
$status = (string) ($_GET['status'] ?? 'all');
AdminUi::header('Signups', $admin);
AdminUi::flash($msg);
AdminUi::filterForm($f);
$rows = Exports::signups($f + ['status' => in_array($status, ['pending', 'confirmed', 'interest', 'cancelled', 'expired'], true) ? $status : 'all']);
echo '<p>' . count($rows) . ' signup(s). Contains personal information.</p><div class="scroll"><table><tr><th>Volunteer</th><th>Email</th><th>Phone</th><th>Site</th><th>When</th><th>Role</th><th>Status</th><th></th></tr>';
foreach (array_slice($rows, 0, 500) as $r) {
    echo '<tr><td>' . View::h($r['name']) . '</td><td>' . View::h($r['email']) . ($r['email_verified_at'] ? '' : ' <span class="tag">unverified</span>') . '</td><td>' . View::h($r['phone_e164'] ?: $r['phone'])
        . ' <span class="tag">' . View::h($r['phone_status']) . '</span></td><td>' . View::h($r['site_name']) . '</td><td>' . View::h(Util::fmtRange($r['starts_at'], $r['ends_at']))
        . '</td><td>' . View::h($r['role_name']) . '</td><td>' . View::h($r['status']) . '</td><td>';
    if (in_array($r['status'], ['pending', 'confirmed', 'interest'], true)) {
        echo '<form method="post" class="inline">' . Security::csrfField() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="' . (int) $r['signup_id'] . '"><button>Cancel</button></form>';
    }
    echo '</td></tr>';
}
echo '</table></div>';
AdminUi::footer();
