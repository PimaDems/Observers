<?php
declare(strict_types=1);

define('OBS_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (preg_match('/^\w+$/', $class) && is_file($file)) {
        require $file;
    }
});

Config::load(OBS_ROOT . '/config.php');
date_default_timezone_set((string) Config::get('timezone', 'America/Phoenix'));
