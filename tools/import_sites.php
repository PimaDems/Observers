<?php
declare(strict_types=1);
/**
 * CLI: php tools/import_sites.php [--type=drop_box|early_vote|election_day] file.csv [file2.csv ...]
 * With no files, imports the three CSVs in the repository root.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';

$files = [];
$forced = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--type=')) {
        $forced = substr($a, 7);
    } else {
        $files[] = $a;
    }
}
if (!$files) {
    foreach (['Ballot Drop Boxes.csv', 'Early Voting.csv', 'Polling Locations Election Day.csv'] as $f) {
        if (is_file(OBS_ROOT . '/' . $f)) {
            $files[] = OBS_ROOT . '/' . $f;
        }
    }
}
if (!$files) {
    fwrite(STDERR, "No CSV files given or found.\n");
    exit(1);
}
Catalog::seed();
$status = 0;
foreach ($files as $file) {
    $type = $forced ?? SiteImporter::guessType(basename($file));
    if ($type === null) {
        fwrite(STDERR, "Cannot guess site type for $file; use --type=\n");
        $status = 1;
        continue;
    }
    $r = SiteImporter::importFile($file, $type);
    printf(
        "%s [%s]: %d inserted, %d updated, %d skipped, %d without lat/lng\n",
        basename($file), $type, $r['inserted'], $r['updated'], $r['skipped'], $r['missing_coords']
    );
    foreach ($r['errors'] as $e) {
        echo "  ! $e\n";
    }
    if ($r['errors'] && $r['inserted'] + $r['updated'] === 0) {
        $status = 1;
    }
}
exit($status);
