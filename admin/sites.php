<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$msg = null;
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requirePost();
    $act = (string) ($_POST['action'] ?? '');
    $num = static function (string $k, float $min, float $max): ?string {
        $v = trim((string) ($_POST[$k] ?? ''));
        return ($v !== '' && is_numeric($v) && (float) $v >= $min && (float) $v <= $max) ? number_format((float) $v, 6, '.', '') : null;
    };
    $time = static fn(string $k): ?string => SiteImporter::parseTime((string) ($_POST[$k] ?? ''));
    if ($act === 'save' || $act === 'add') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $type = (string) ($_POST['type'] ?? '');
        $lat = $num('lat', -90, 90);
        $lng = $num('lng', -180, 180);
        if ($lat === null || $lng === null) {
            $lat = $lng = null;
        }
        $ds = $time('default_start');
        $de = $time('default_end');
        if (($ds === null) !== ($de === null) || ($ds !== null && $ds >= $de)) {
            $errors[] = 'Default hours need both a start and an end time, start before end.';
        }
        if ($name === '' || !isset(Catalog::TYPES[$type])) {
            $errors[] = 'Name and a valid type are required.';
        }
        if (!$errors) {
            $addr = trim((string) ($_POST['address'] ?? ''));
            $vals = [$name, $addr, trim((string) ($_POST['city'] ?? '')) ?: null, trim((string) ($_POST['zip'] ?? '')) ?: null, $lat, $lng, $ds, $de,
                     trim((string) ($_POST['notes'] ?? '')) ?: null, empty($_POST['is_active']) ? 0 : 1, Util::now()];
            try {
                if ($act === 'add') {
                    $id = Db::insert(
                        'INSERT INTO sites (name, address, city, zip, lat, lng, default_start_time, default_end_time, notes, is_active, updated_at, type, site_key, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        array_merge($vals, [$type, Catalog::siteKey($type, $name, $addr), Util::now()])
                    );
                } else {
                    $id = (int) $_POST['id'];
                    Db::exec('UPDATE sites SET name=?, address=?, city=?, zip=?, lat=?, lng=?, default_start_time=?, default_end_time=?, notes=?, is_active=?, updated_at=? WHERE id=?', array_merge($vals, [$id]));
                }
                Util::audit((int) $admin['id'], 'site_' . $act, "site $id $name");
                $msg = 'Site saved.';
            } catch (PDOException $e) {
                $errors[] = 'Could not save (duplicate type/name/address?).';
            }
        }
    }
}

AdminUi::header('Sites', $admin);
AdminUi::flash($msg, $errors);
$edit = isset($_GET['edit']) ? Db::one('SELECT * FROM sites WHERE id = ?', [(int) $_GET['edit']]) : null;
$form = static function (?array $s) {
    $v = static fn(string $k) => View::h((string) ($s[$k] ?? ''));
    echo '<form method="post" class="card">' . Security::csrfField() . '<input type="hidden" name="action" value="' . ($s ? 'save' : 'add') . '">'
        . ($s ? '<input type="hidden" name="id" value="' . (int) $s['id'] . '">' : '')
        . '<label>Name <input name="name" required value="' . $v('name') . '"></label>';
    if ($s) {
        echo '<input type="hidden" name="type" value="' . $v('type') . '"><p class="muted">Type: ' . View::h(Catalog::TYPES[$s['type']] ?? '') . '</p>';
    } else {
        echo '<label>Type <select name="type">';
        foreach (Catalog::TYPES as $k => $label) {
            echo '<option value="' . $k . '">' . View::h($label) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<label>Address <input name="address" value="' . $v('address') . '"></label><label>City <input name="city" value="' . $v('city') . '"></label>'
        . '<label>ZIP <input name="zip" value="' . $v('zip') . '"></label>'
        . '<label>Latitude <input name="lat" value="' . $v('lat') . '"></label><label>Longitude <input name="lng" value="' . $v('lng') . '"></label>'
        . '<label>Default shift start (e.g. 07:00) <input name="default_start" value="' . $v('default_start_time') . '"></label>'
        . '<label>Default shift end (e.g. 19:00) <input name="default_end" value="' . $v('default_end_time') . '"></label>'
        . '<label>Notes <input name="notes" value="' . $v('notes') . '"></label>'
        . '<label><input type="checkbox" name="is_active" value="1"' . (!$s || $s['is_active'] ? ' checked' : '') . '> Active</label>'
        . '<button class="primary">Save</button></form>';
};
if ($edit) {
    echo '<h3>Edit site</h3>';
    $form($edit);
    echo '<p><a href="' . View::h(Util::url('admin/sites.php')) . '">Back to list</a></p>';
} else {
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = (string) ($_GET['type'] ?? '');
    $missing = !empty($_GET['missing_coords']);
    echo '<form method="get" class="inline"><label>Search <input name="q" value="' . View::h($q) . '" style="display:inline-block;width:auto"></label>'
        . '<label>Type <select name="type" style="display:inline-block;width:auto"><option value="">All</option>';
    foreach (Catalog::TYPES as $k => $label) {
        echo '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . View::h($label) . '</option>';
    }
    echo '</select></label><label><input type="checkbox" name="missing_coords" value="1"' . ($missing ? ' checked' : '') . '> Missing coordinates</label> <button>Filter</button></form>';
    $sql = 'SELECT * FROM sites WHERE 1=1';
    $p = [];
    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR address LIKE ?)';
        $p[] = $p[] = '%' . addcslashes($q, '%_\\') . '%';
    }
    if (isset(Catalog::TYPES[$type])) {
        $sql .= ' AND type = ?';
        $p[] = $type;
    }
    if ($missing) {
        $sql .= " AND (lat IS NULL OR lng IS NULL) AND type <> 'roaming'";
    }
    $sites = Db::all($sql . ' ORDER BY type, name LIMIT 500', $p);
    echo '<div class="scroll"><table><tr><th>Name</th><th>Type</th><th>Address</th><th>Lat/Lng</th><th></th></tr>';
    foreach ($sites as $s) {
        echo '<tr><td>' . View::h($s['name']) . ($s['is_active'] ? '' : ' <span class="tag">inactive</span>') . '</td><td>' . View::h(Catalog::TYPES[$s['type']] ?? '') . '</td><td>' . View::h($s['address'])
            . '</td><td>' . ($s['lat'] === null ? '<span class="badge cov-low">needs geocoding</span>' : View::h($s['lat'] . ', ' . $s['lng']))
            . '</td><td><a href="' . View::h(Util::url('admin/sites.php?edit=' . (int) $s['id'])) . '">Edit</a></td></tr>';
    }
    echo '</table></div><h3>Add site</h3>';
    $form(null);
}
AdminUi::footer();
