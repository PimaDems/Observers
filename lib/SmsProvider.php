<?php
declare(strict_types=1);

interface SmsProvider
{
    /** Short machine name, e.g. "null" or "twilio". */
    public function name(): string;

    /** True if the provider can actually deliver messages. */
    public function canSend(): bool;

    /** Send a message to an E.164 number. Returns true on success. */
    public function send(string $e164, string $message): bool;
}
