<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$msg = null;
$errors = [];
$newLink = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requirePost();
    $act = (string) ($_POST['action'] ?? '');
    $cid = (int) ($_POST['id'] ?? 0);
    if ($act === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Name and a valid email are required.';
        } else {
            [$cid, $raw] = Coordinators::create($name, $email);
            $newLink = Util::absUrl('coordinator/?t=' . $raw);
            Util::audit((int) $admin['id'], 'coordinator_create', "coordinator $cid");
        }
    } elseif ($act === 'rotate') {
        $newLink = Util::absUrl('coordinator/?t=' . Coordinators::rotateToken($cid));
        Util::audit((int) $admin['id'], 'coordinator_rotate', "coordinator $cid");
    } elseif ($act === 'toggle') {
        Db::exec('UPDATE coordinators SET is_active = 1 - is_active WHERE id = ?', [$cid]);
    } elseif ($act === 'assign') {
        $site = (int) ($_POST['site_id'] ?? 0);
        $role = Catalog::roleId((string) ($_POST['role'] ?? ''));
        if ($cid && $site && $role) {
            Coordinators::assign($cid, $site, $role);
            Util::audit((int) $admin['id'], 'coordinator_assign', "coordinator $cid site $site role $role");
            $msg = 'Assigned. Signups for this site and role now go to the coordinator.';
        }
    } elseif ($act === 'unassign') {
        Db::exec('DELETE FROM coordinator_sites WHERE site_id = ? AND role_id = ? AND coordinator_id = ?', [(int) ($_POST['site_id'] ?? 0), (int) ($_POST['role_id'] ?? 0), $cid]);
    } elseif ($act === 'notify') {
        $msg = Coordinators::notifyPending() . ' pending signup(s) forwarded to coordinators.';
    }
}
AdminUi::header('Coordinators', $admin);
AdminUi::flash($msg, $errors);
if ($newLink) {
    echo '<p class="alert ok">Private coordinator link (shown only once - copy it now):<br><code>' . View::h($newLink) . '</code></p>';
}
$unnotified = (int) Db::val("SELECT COUNT(*) FROM signups WHERE status = 'interest' AND coordinator_notified_at IS NULL");
echo '<form method="post" class="card">' . Security::csrfField() . '<input type="hidden" name="action" value="notify"><p>' . $unnotified . ' verified signup(s) not yet emailed to a coordinator.</p><button>Send pending coordinator emails</button></form>';
echo '<h3>Add coordinator</h3><form method="post" class="card">' . Security::csrfField() . '<input type="hidden" name="action" value="create">'
    . '<label>Name <input name="name" required></label><label>Email <input type="email" name="email" required></label><button class="primary">Create</button></form>';
foreach (Db::all('SELECT * FROM coordinators ORDER BY name') as $c) {
    $id = (int) $c['id'];
    echo '<section class="card"><h3>' . View::h($c['name']) . ' <span class="muted">' . View::h($c['email']) . '</span>' . ($c['is_active'] ? '' : ' <span class="tag">inactive</span>') . '</h3>';
    echo '<form method="post" class="inline">' . Security::csrfField() . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button name="action" value="rotate">New private link</button> <button name="action" value="toggle">' . ($c['is_active'] ? 'Deactivate' : 'Activate') . '</button></form>';
    echo '<ul>';
    foreach (Coordinators::sites($id) as $s) {
        echo '<li>' . View::h($s['name'] . ' - ' . $s['role_name']) . ' <form method="post" class="inline">' . Security::csrfField() . '<input type="hidden" name="action" value="unassign"><input type="hidden" name="id" value="' . $id
            . '"><input type="hidden" name="site_id" value="' . (int) $s['site_id'] . '"><input type="hidden" name="role_id" value="' . (int) $s['role_id'] . '"><button>Remove</button></form></li>';
    }
    echo '</ul><form method="post" class="inline">' . Security::csrfField() . '<input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="' . $id . '">'
        . '<label>Site <select name="site_id">' . AdminUi::siteOptions(0, 'Choose…') . '</select></label><label>Role <select name="role">';
    foreach (Catalog::ROLES as $code => [$rname]) {
        echo '<option value="' . $code . '">' . View::h($rname) . '</option>';
    }
    echo '</select></label> <button>Assign</button></form>';
    $reports = Coordinators::reports($id);
    echo '<p class="muted">' . count($reports) . ' coverage report row(s) uploaded; ' . count(Coordinators::interested($id)) . ' interested volunteer(s).</p></section>';
}
AdminUi::footer();
