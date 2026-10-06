<?php
declare(strict_types=1);

/** Email (link) and phone (code, via SmsProvider) verification; confirms signups. */
final class Verification
{
    private static ?SmsProvider $sms = null;

    public static function smsProvider(): SmsProvider
    {
        return self::$sms ??= NullSmsProvider::make();
    }

    public static function setSmsProvider(?SmsProvider $p): void
    {
        self::$sms = $p;
    }

    public static function requirePhone(): bool
    {
        return (bool) Config::get('verification.require_phone', false);
    }

    public static function sendEmailVerification(int $volunteerId): bool
    {
        $v = Db::one('SELECT * FROM volunteers WHERE id = ?', [$volunteerId]);
        if (!$v) {
            return false;
        }
        $raw = Util::token();
        $ttl = (int) Config::get('verification.token_ttl_hours', 24);
        Db::exec(
            "INSERT INTO verification_tokens (volunteer_id, type, token_hash, expires_at, created_at) VALUES (?, 'email', ?, ?, ?)",
            [$volunteerId, Util::hashToken($raw), Util::nowPlus($ttl * 3600), Util::now()]
        );
        $link = Util::absUrl('verify.php?t=' . $raw);
        $body = "Hi {$v['name']},\n\nPlease confirm your email address to finish signing up as an election volunteer/observer:\n\n$link\n\n"
            . "This link expires in $ttl hours; shifts you selected are held for you until then.\n"
            . "If you did not request this, you can ignore this message.\n";
        return Mailer::instance()->send($v['email'], 'Confirm your email - Pima County Observers', $body, 'verify_email', $volunteerId);
    }

    /** @return array{status:string,volunteer_id?:int,phone_token?:string} status: ok|already|invalid|expired */
    public static function verifyEmail(string $raw): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $raw)) {
            return ['status' => 'invalid'];
        }
        $res = Db::transaction(function () use ($raw) {
            $t = Db::one("SELECT * FROM verification_tokens WHERE token_hash = ? AND type = 'email'" . Db::forUpdate(), [Util::hashToken($raw)]);
            if (!$t) {
                return ['status' => 'invalid'];
            }
            if ($t['used_at'] !== null) {
                return ['status' => 'already', 'volunteer_id' => (int) $t['volunteer_id']];
            }
            if ($t['expires_at'] <= Util::now()) {
                return ['status' => 'expired'];
            }
            Db::exec('UPDATE verification_tokens SET used_at = ? WHERE id = ?', [Util::now(), $t['id']]);
            Db::exec('UPDATE volunteers SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [Util::now(), Util::now(), $t['volunteer_id']]);
            return ['status' => 'ok', 'volunteer_id' => (int) $t['volunteer_id']];
        });
        if ($res['status'] === 'ok') {
            $res['phone_token'] = self::afterEmailVerified($res['volunteer_id']);
        }
        return $res;
    }

    /** Confirm signups now, or start phone verification when required. Returns a phone token if a code was sent. */
    private static function afterEmailVerified(int $volunteerId): ?string
    {
        $v = Db::one('SELECT * FROM volunteers WHERE id = ?', [$volunteerId]);
        if (self::requirePhone() && !$v['phone_verified_at']) {
            return self::startPhoneVerification($volunteerId);
        }
        self::confirmPending($volunteerId);
        return null;
    }

    /** Sends an SMS code if a real provider exists. Returns the page token, or null when the provider cannot send. */
    public static function startPhoneVerification(int $volunteerId): ?string
    {
        $v = Db::one('SELECT * FROM volunteers WHERE id = ?', [$volunteerId]);
        $sms = self::smsProvider();
        if (!$v || !$v['phone_e164'] || !$sms->canSend()) {
            Db::exec("UPDATE volunteers SET phone_status = 'unverified_pending_provider' WHERE id = ? AND phone_verified_at IS NULL", [$volunteerId]);
            return null;
        }
        $code = (string) random_int(100000, 999999);
        $raw = Util::token();
        Db::exec(
            "INSERT INTO verification_tokens (volunteer_id, type, token_hash, code_hash, expires_at, created_at) VALUES (?, 'phone', ?, ?, ?, ?)",
            [$volunteerId, Util::hashToken($raw), Util::hashToken($code), Util::nowPlus(1800), Util::now()]
        );
        $sms->send($v['phone_e164'], "Your Pima County Observers code is $code");
        Db::exec("UPDATE volunteers SET phone_status = 'pending_code' WHERE id = ?", [$volunteerId]);
        return $raw;
    }

    /** @return string ok|invalid|expired|locked|wrong */
    public static function checkPhoneCode(string $raw, string $code): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $raw)) {
            return 'invalid';
        }
        $status = Db::transaction(function () use ($raw, $code) {
            $t = Db::one("SELECT * FROM verification_tokens WHERE token_hash = ? AND type = 'phone'" . Db::forUpdate(), [Util::hashToken($raw)]);
            if (!$t || $t['used_at'] !== null) {
                return ['invalid', 0];
            }
            if ($t['expires_at'] <= Util::now()) {
                return ['expired', 0];
            }
            if ((int) $t['attempts'] >= (int) Config::get('verification.max_code_attempts', 5)) {
                return ['locked', 0];
            }
            Db::exec('UPDATE verification_tokens SET attempts = attempts + 1 WHERE id = ?', [$t['id']]);
            if (!hash_equals((string) $t['code_hash'], Util::hashToken(trim($code)))) {
                return ['wrong', 0];
            }
            Db::exec('UPDATE verification_tokens SET used_at = ? WHERE id = ?', [Util::now(), $t['id']]);
            Db::exec("UPDATE volunteers SET phone_verified_at = ?, phone_status = 'verified' WHERE id = ?", [Util::now(), $t['volunteer_id']]);
            return ['ok', (int) $t['volunteer_id']];
        });
        if ($status[0] === 'ok') {
            self::confirmPending($status[1]);
        }
        return $status[0];
    }

    /** Move held signups to confirmed/interest and send emails. Returns the number confirmed. */
    public static function confirmPending(int $volunteerId): int
    {
        $now = Util::now();
        $confirmed = Db::transaction(function () use ($volunteerId, $now) {
            Db::exec("UPDATE signups SET status = 'expired' WHERE volunteer_id = ? AND status = 'pending' AND hold_expires_at <= ?", [$volunteerId, $now]);
            $rows = Db::all(
                "SELECT sg.id, sh.site_id, sh.role_id FROM signups sg JOIN shifts sh ON sh.id = sg.shift_id
                 WHERE sg.volunteer_id = ? AND sg.status = 'pending'",
                [$volunteerId]
            );
            $out = [];
            foreach ($rows as $r) {
                $ext = Catalog::isExternal((int) $r['site_id'], (int) $r['role_id']);
                Db::exec('UPDATE signups SET status = ?, confirmed_at = ?, hold_expires_at = NULL WHERE id = ?', [$ext ? 'interest' : 'confirmed', $now, $r['id']]);
                $out[] = ['id' => (int) $r['id'], 'external' => $ext];
            }
            return $out;
        });
        if (!$confirmed) {
            return 0;
        }
        $ids = array_column($confirmed, 'id');
        self::sendConfirmation($volunteerId, $ids);
        if (array_filter(array_column($confirmed, 'external'))) {
            Coordinators::notifyPending($volunteerId);
        }
        return count($confirmed);
    }

    private static function sendConfirmation(int $volunteerId, array $signupIds): void
    {
        $v = Db::one('SELECT * FROM volunteers WHERE id = ?', [$volunteerId]);
        $in = implode(',', array_fill(0, count($signupIds), '?'));
        $rows = Db::all(
            "SELECT sg.status, sg.cancel_token, sh.starts_at, sh.ends_at, si.name AS site_name, si.address, r.name AS role_name
             FROM signups sg JOIN shifts sh ON sh.id = sg.shift_id JOIN sites si ON si.id = sh.site_id JOIN roles r ON r.id = sh.role_id
             WHERE sg.id IN ($in) ORDER BY sh.starts_at",
            $signupIds
        );
        $body = "Hi {$v['name']},\n\nThank you for volunteering! Your email is verified. Here are your shifts:\n\n";
        foreach ($rows as $r) {
            $body .= '* ' . Util::fmtRange($r['starts_at'], $r['ends_at']) . "\n  {$r['site_name']}" . ($r['address'] ? " ({$r['address']})" : '') . "\n  Role: {$r['role_name']}\n";
            $body .= $r['status'] === 'interest'
                ? "  Your details were passed to the coordinator for this location; they will contact you.\n"
                : "  Status: confirmed\n";
            $body .= '  Cancel: ' . Util::absUrl('cancel.php?t=' . $r['cancel_token']) . "\n\n";
        }
        Mailer::instance()->send($v['email'], 'Your volunteer shifts are confirmed', $body, 'confirmation', $volunteerId);
    }
}
