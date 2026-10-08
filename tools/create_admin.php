<?php
declare(strict_types=1);
/** php tools/create_admin.php USERNAME   (password prompted, or OBS_ADMIN_PASSWORD env) */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';
if (empty($argv[1])) {
    fwrite(STDERR, "Usage: php tools/create_admin.php USERNAME\n");
    exit(1);
}
$pw = getenv('OBS_ADMIN_PASSWORD') ?: null;
if ($pw === null) {
    fwrite(STDOUT, "Password for {$argv[1]}: ");
    @system('stty -echo 2>/dev/null');
    $pw = trim((string) fgets(STDIN));
    @system('stty echo 2>/dev/null');
    echo "\n";
}
Admin::create($argv[1], $pw);
echo "Admin '{$argv[1]}' saved.\n";
