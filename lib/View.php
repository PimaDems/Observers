<?php
declare(strict_types=1);

final class View
{
    public static function h(?string $s): string
    {
        return Util::h($s);
    }

    public static function header(string $title, string $bodyClass = '', string $extraHead = ''): void
    {
        Security::headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . self::h($title) . '</title>'
            . '<link rel="stylesheet" href="' . self::h(Util::url('assets/style.css')) . '">' . $extraHead
            . '</head><body class="' . self::h($bodyClass) . '"><div class="wrap">';
    }

    public static function footer(string $extraScripts = ''): void
    {
        echo '</div>' . $extraScripts . '</body></html>';
    }

    public static function errors(array $errors): void
    {
        foreach ($errors as $e) {
            echo '<p class="alert error">' . self::h($e) . '</p>';
        }
    }

    public static function badge(string $level): string
    {
        return '<span class="badge cov-' . self::h($level) . '">' . self::h(Shifts::levelLabel($level)) . '</span>';
    }

    public static function message(string $title, string $html): void
    {
        self::header($title);
        echo '<h1>' . self::h($title) . '</h1>' . $html . '<p><a href="' . self::h(Util::url('')) . '">Home</a></p>';
        self::footer();
    }
}
