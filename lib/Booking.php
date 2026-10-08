<?php
declare(strict_types=1);

final class BookingException extends RuntimeException
{
}

/** Transactional sign-ups: capacity and overlap are enforced under row locks. */
final class Booking
{
    /** SQL fragment: signup alias $a counts as "active" (needs one ? param: now). */
    public static function activeSql(string $a = 'sg'): string
    {
        return "($a.status IN ('confirmed','interest') OR ($a.status = 'pending' AND $a.hold_expires_at > ?))";
    }

    public static function validate(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = Phone::normalize($in['phone'] ?? '');
        if ($name === '' || mb_strlen($name) > 150) {
            $errors[] = 'Please enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($phone === null) {
            $errors[] = 'Please enter a valid US phone number (10 digits).';
        }
        return [$errors, $name, $email, $phone];
    }

    /**
     * Book shifts for a volunteer (all or nothing). Public sign-up path.
     * @return array{volunteer_id:int,signup_ids:int[]}
     * @throws BookingException with a user-presentable message
     */
    public static function book(array $in, array $shiftIds, string $group): array
    {
        [$errors, $name, $email, $phone] = self::validate($in);
        $shiftIds = array_values(array_unique(array_map('intval', $shiftIds)));
        sort($shiftIds);
        if (!$shiftIds) {
            $errors[] = 'Please select at least one shift.';
        }
        if (count($shiftIds) > 30) {
            $errors[] = 'Too many shifts selected at once.';
        }
        if ($errors) {
            throw new BookingException(implode(' ', $errors));
        }

        $result = Db::transaction(function () use ($name, $email, $phone, $shiftIds, $group) {
            $now = Util::now();
            $vol = self::upsertVolunteer($name, $email, $phone);
            $volId = (int) $vol['id'];
            // Serialise this volunteer's concurrent bookings.
            Db::one('SELECT id FROM volunteers WHERE id = ?' . Db::forUpdate(), [$volId]);

            $shifts = [];
            foreach ($shiftIds as $id) { // ascending ids => consistent lock order
                $s = Db::one(
                    'SELECT sh.*, r.affiliation, si.is_active AS site_active FROM shifts sh
                     JOIN roles r ON r.id = sh.role_id JOIN sites si ON si.id = sh.site_id WHERE sh.id = ?' . Db::forUpdate(),
                    [$id]
                );
                if (!$s || !(int) $s['is_active'] || !(int) $s['site_active'] || $s['affiliation'] !== $group || $s['starts_at'] <= $now) {
                    throw new BookingException('One of the selected shifts is no longer available.');
                }
                $shifts[] = $s;
            }
            // Overlap among the selected shifts themselves
            foreach ($shifts as $i => $a) {
                foreach ($shifts as $j => $b) {
                    if ($i < $j && $a['starts_at'] < $b['ends_at'] && $b['starts_at'] < $a['ends_at']) {
                        throw new BookingException('You selected shifts that overlap in time. Pick one at a time slot.');
                    }
                }
            }

            $ttl = (int) Config::get('verification.token_ttl_hours', 24) * 3600;
            $ids = [];
            foreach ($shifts as $s) {
                $external = Catalog::isExternal((int) $s['site_id'], (int) $s['role_id']);
                $existing = Db::one('SELECT * FROM signups WHERE shift_id = ? AND volunteer_id = ?', [$s['id'], $volId]);
                if ($existing && (in_array($existing['status'], ['confirmed', 'interest'], true)
                    || ($existing['status'] === 'pending' && $existing['hold_expires_at'] > $now))) {
                    throw new BookingException('You are already signed up for one of those shifts.');
                }
                $overlap = Db::val(
                    'SELECT COUNT(*) FROM signups sg JOIN shifts o ON o.id = sg.shift_id
                     WHERE sg.volunteer_id = ? AND sg.shift_id <> ? AND ' . self::activeSql('sg') . ' AND o.starts_at < ? AND o.ends_at > ?',
                    [$volId, $s['id'], $now, $s['ends_at'], $s['starts_at']]
                );
                if ((int) $overlap > 0) {
                    throw new BookingException('You already have a shift that overlaps ' . Util::fmtRange($s['starts_at'], $s['ends_at']) . '.');
                }
                if (!$external && $s['max_volunteers'] !== null) {
                    $taken = (int) Db::val(
                        "SELECT COUNT(*) FROM signups sg WHERE sg.shift_id = ? AND (sg.status = 'confirmed' OR (sg.status = 'pending' AND sg.hold_expires_at > ?))",
                        [$s['id'], $now]
                    );
                    if ($taken >= (int) $s['max_volunteers']) {
                        throw new BookingException('Sorry, a selected shift (' . Util::fmtRange($s['starts_at'], $s['ends_at']) . ') just filled up.');
                    }
                }
                $hold = date('Y-m-d H:i:s', strtotime($now) + $ttl);
                if ($existing) {
                    Db::exec(
                        "UPDATE signups SET status = 'pending', hold_expires_at = ?, cancel_token = ?, created_at = ?, confirmed_at = NULL, cancelled_at = NULL, coordinator_notified_at = NULL WHERE id = ?",
                        [$hold, Util::token(), $now, $existing['id']]
                    );
                    $ids[] = (int) $existing['id'];
                } else {
                    $ids[] = Db::insert(
                        "INSERT INTO signups (shift_id, volunteer_id, status, hold_expires_at, cancel_token, created_at) VALUES (?, ?, 'pending', ?, ?, ?)",
                        [$s['id'], $volId, $hold, Util::token(), $now]
                    );
                }
            }
            return ['volunteer_id' => $volId, 'signup_ids' => $ids];
        });

        Verification::sendEmailVerification($result['volunteer_id']);
        return $result;
    }

    private static function upsertVolunteer(string $name, string $email, string $phone): array
    {
        $now = Util::now();
        $v = Db::one('SELECT * FROM volunteers WHERE email = ?' . Db::forUpdate(), [$email]);
        $status = Verification::smsProvider()->canSend() ? 'pending_code' : 'unverified_pending_provider';
        if (!$v) {
            $id = Db::insert(
                'INSERT INTO volunteers (name, email, phone, phone_e164, phone_status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)',
                [$name, $email, $phone, $phone, $status, $now, $now]
            );
            return Db::one('SELECT * FROM volunteers WHERE id = ?', [$id]);
        }
        // Verified volunteers keep their stored contact details (prevents overwriting via someone else's email).
        if (!$v['email_verified_at']) {
            $phoneChanged = $v['phone_e164'] !== $phone;
            Db::exec(
                'UPDATE volunteers SET name = ?, phone = ?, phone_e164 = ?, phone_status = ?, phone_verified_at = ?, updated_at = ? WHERE id = ?',
                [$name, $phone, $phone, $phoneChanged ? $status : $v['phone_status'], $phoneChanged ? null : $v['phone_verified_at'], $now, $v['id']]
            );
        }
        return Db::one('SELECT * FROM volunteers WHERE id = ?', [$v['id']]);
    }

    /** Cancel by the secret cancel token. Returns the signup details or null. */
    public static function cancel(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return Db::transaction(function () use ($token) {
            $row = Db::one(
                'SELECT sg.id, sg.status, sh.starts_at, sh.ends_at, si.name AS site_name
                 FROM signups sg JOIN shifts sh ON sh.id = sg.shift_id JOIN sites si ON si.id = sh.site_id WHERE sg.cancel_token = ?',
                [$token]
            );
            if (!$row) {
                return null;
            }
            if (in_array($row['status'], ['pending', 'confirmed', 'interest'], true)) {
                Db::exec("UPDATE signups SET status = 'cancelled', cancelled_at = ? WHERE id = ?", [Util::now(), $row['id']]);
                $row['was_active'] = true;
            } else {
                $row['was_active'] = false;
            }
            return $row;
        });
    }
}
