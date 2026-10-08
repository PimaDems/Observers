<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$admin = Admin::require();
$result = null;
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Security::requirePost();
    $file = $_FILES['csv'] ?? null;
    $name = (string) ($file['name'] ?? '');
    $type = (string) ($_POST['type'] ?? '') ?: (SiteImporter::guessType($name) ?? '');
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > 2 * 1024 * 1024) {
        $errors[] = 'Please choose a CSV file under 2 MB.';
    } elseif (!isset(Catalog::TYPES[$type]) || $type === 'roaming') {
        $errors[] = 'Choose the site type for this file.';
    } else {
        $result = SiteImporter::importFile($file['tmp_name'], $type);
        Util::audit((int) $admin['id'], 'import_sites', "$name type=$type " . json_encode(array_diff_key($result, ['errors' => 1, 'columns' => 1])));
    }
}
AdminUi::header('Import sites from CSV', $admin);
AdminUi::flash(null, $errors);
echo '<p>Headers are matched flexibly (name, address, city, zip, lat/lng, precinct, hours, date/start/end columns). Re-importing is safe: sites are matched on type + name + address. Rows without lat/lng are flagged for geocoding.</p>'
    . '<form method="post" enctype="multipart/form-data" class="card">' . Security::csrfField()
    . '<label>CSV file <input type="file" name="csv" accept=".csv,text/csv" required></label><label>Site type <select name="type"><option value="">Guess from file name</option>';
foreach (Catalog::TYPES as $k => $label) {
    if ($k !== 'roaming') {
        echo '<option value="' . $k . '">' . View::h($label) . '</option>';
    }
}
echo '</select></label><button class="primary">Import</button></form>';
if ($result) {
    echo '<div class="card"><p>' . (int) $result['inserted'] . ' inserted, ' . (int) $result['updated'] . ' updated, ' . (int) $result['skipped'] . ' skipped, '
        . (int) $result['missing_coords'] . ' without coordinates.</p>';
    echo '<p class="muted">Columns recognised: ' . View::h(implode(', ', array_keys($result['columns']))) . '</p>';
    View::errors(array_slice($result['errors'], 0, 50));
    echo '</div>';
}
AdminUi::footer();
