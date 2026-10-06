<?php
declare(strict_types=1);
/**
 * Installer / migration (safe to re-run): creates tables, roles and fixed sites.
 *   php tools/install.php [--admin=USERNAME] [--import]
 * --admin    create/reset an admin (password prompted, or OBS_ADMIN_PASSWORD env var)
 * --import   also import the CSVs found in the repository root
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';

$opts = getopt('', ['admin::', 'import']);
$n = Db::installSchema();
echo "Schema applied ($n statements).\n";
Catalog::seed();
echo "Roles and fixed sites seeded.\n";

if (isset($opts['admin']) && $opts['admin'] !== false) {
    $pw = getenv('OBS_ADMIN_PASSWORD') ?: null;
    if ($pw === null) {
        fwrite(STDOUT, 'Password for ' . $opts['admin'] . ': ');
        @system('stty -echo 2>/dev/null');
        $pw = trim((string) fgets(STDIN));
        @system('stty echo 2>/dev/null');
        echo "\n";
    }
    Admin::create((string) $opts['admin'], $pw);
    echo "Admin '{$opts['admin']}' saved.\n";
}
if (isset($opts['import'])) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/import_sites.php'));
}
