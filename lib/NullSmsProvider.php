<?php
declare(strict_types=1);

/** Placeholder provider: nothing is delivered; messages are appended to data/sms.log. */
final class NullSmsProvider implements SmsProvider
{
    public function __construct(private ?string $logFile = null)
    {
    }

    public function name(): string
    {
        return 'null';
    }

    public function canSend(): bool
    {
        return false;
    }

    public function send(string $e164, string $message): bool
    {
        $file = $this->logFile ?? OBS_ROOT . '/data/sms.log';
        if (is_dir(dirname($file)) && is_writable(dirname($file))) {
            @file_put_contents($file, date('c') . " [not sent] {$e164}: {$message}\n", FILE_APPEND | LOCK_EX);
        }
        return false;
    }

    public static function make(): SmsProvider
    {
        // Add real providers here, selected by config sms.provider.
        return new self();
    }
}
