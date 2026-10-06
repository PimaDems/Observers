<?php
declare(strict_types=1);

final class Phone
{
    /** Normalise a US phone number to E.164 (+1XXXXXXXXXX); null if invalid. */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        // NANP: area code and exchange cannot start with 0 or 1
        if (!preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits)) {
            return null;
        }
        return '+1' . $digits;
    }
}
