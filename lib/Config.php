<?php
declare(strict_types=1);

final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        if (is_file($file)) {
            $v = require $file;
            if (is_array($v)) {
                self::$data = $v;
            }
        }
    }

    /** Replace/merge settings (used by tests). */
    public static function set(array $data): void
    {
        self::$data = array_replace_recursive(self::$data, $data);
    }

    public static function reset(): void
    {
        self::$data = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cur = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }
}
