<?php
declare(strict_types=1);

final class BookingTest extends DbTestCase
{
    public function testCapacityIsEnforced(): void
    {
        $site = $this->makeSite();
        $shift = $this->makeShift($site, 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00', 2);
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        Booking::book($this->person('2'), [$shift], 'nonpartisan');
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('filled up');
        Booking::book($this->person('3'), [$shift], 'nonpartisan');
    }

    public function testFailedBookingIsAllOrNothing(): void
    {
        $site = $this->makeSite();
        $a = $this->makeShift($site, 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00', 1);
        $b = $this->makeShift($site, 'np_observer', '2026-11-03 09:00:00', '2026-11-03 11:00:00', 5);
        Booking::book($this->person('1'), [$a], 'nonpartisan');
        try {
            Booking::book($this->person('2'), [$b, $a], 'nonpartisan');
            $this->fail('expected full shift');
        } catch (BookingException) {
        }
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM signups WHERE shift_id = ?', [$b]));
    }

    public function testExpiredHoldFreesSeat(): void
    {
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00', 1);
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        Util::$fixedNow = '2026-10-03 09:00:00'; // after the 24h hold
        Booking::book($this->person('2'), [$shift], 'nonpartisan');
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM signups'));
    }

    public function testCancelFreesSeat(): void
    {
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00', 1);
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $token = Db::val('SELECT cancel_token FROM signups');
        $this->assertTrue(Booking::cancel($token)['was_active']);
        Booking::book($this->person('2'), [$shift], 'nonpartisan');
        $this->assertFalse(Booking::cancel($token)['was_active']);
    }

    public function testOverlapPreventedAcrossSites(): void
    {
        $a = $this->makeShift($this->makeSite('election_day', 'A'), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        $b = $this->makeShift($this->makeSite('drop_box', 'B'), 'np_observer', '2026-11-03 08:00:00', '2026-11-03 10:00:00');
        $c = $this->makeShift($this->makeSite('drop_box', 'C'), 'np_observer', '2026-11-03 09:00:00', '2026-11-03 11:00:00');
        Booking::book($this->person('1'), [$a], 'nonpartisan');
        try {
            Booking::book($this->person('1'), [$b], 'nonpartisan');
            $this->fail('overlap allowed');
        } catch (BookingException $e) {
            $this->assertStringContainsString('overlaps', $e->getMessage());
        }
        Booking::book($this->person('1'), [$c], 'nonpartisan'); // back-to-back is fine
        $this->assertSame(2, (int) Db::val('SELECT COUNT(*) FROM signups'));
    }

    public function testOverlapWithinSameRequest(): void
    {
        $site = $this->makeSite();
        $a = $this->makeShift($site, 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        $b = $this->makeShift($site, 'p_electioneer', '2026-11-03 08:00:00', '2026-11-03 10:00:00');
        $this->expectException(BookingException::class);
        Booking::book($this->person('1'), [$a, $b], 'nonpartisan');
    }

    public function testGroupIsolation(): void
    {
        $partisan = $this->makeShift($this->makeSite(), 'p_electioneer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        $this->expectException(BookingException::class);
        Booking::book($this->person('1'), [$partisan], 'nonpartisan');
    }

    public function testValidationAndPhoneNormalization(): void
    {
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        $this->assertSame('+15205550123', Phone::normalize('1 (520) 555-0123'));
        $this->assertNull(Phone::normalize('123'));
        $this->assertNull(Phone::normalize('(020) 555-0123'));
        $this->expectException(BookingException::class);
        Booking::book(['name' => 'x', 'email' => 'bad', 'phone' => '1'], [$shift], 'nonpartisan');
    }

    public function testPastShiftRejected(): void
    {
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-09-01 07:00:00', '2026-09-01 09:00:00');
        $this->expectException(BookingException::class);
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
    }

    public function testEmailVerificationConfirmsAndSendsCancelLink(): void
    {
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $this->assertSame('pending', Db::val('SELECT status FROM signups'));
        $res = Verification::verifyEmail($this->lastToken());
        $this->assertSame('ok', $res['status']);
        $this->assertSame('confirmed', Db::val('SELECT status FROM signups'));
        $this->assertSame('unverified_pending_provider', Db::val('SELECT phone_status FROM volunteers'));
        $this->assertStringContainsString('cancel.php?t=', end(Mailer::$outbox)['body']);
        $this->assertSame('already', Verification::verifyEmail($this->lastTokenOf(0))['status']);
    }

    private function lastTokenOf(int $i): string
    {
        preg_match('/verify\.php\?t=([a-f0-9]{32})/', Mailer::$outbox[$i]['body'], $x);
        return $x[1];
    }

    public function testExpiredTokenDoesNotConfirm(): void
    {
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $token = $this->lastToken();
        Util::$fixedNow = '2026-10-05 09:00:00';
        $this->assertSame('expired', Verification::verifyEmail($token)['status']);
        $this->assertSame('pending', Db::val('SELECT status FROM signups'));
        $this->assertSame('invalid', Verification::verifyEmail('zz')['status']);
    }

    public function testRequirePhoneWithNullProviderKeepsPending(): void
    {
        Config::set(['verification' => ['require_phone' => true]]);
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $res = Verification::verifyEmail($this->lastToken());
        $this->assertNull($res['phone_token']);
        $this->assertSame('pending', Db::val('SELECT status FROM signups'));
    }

    public function testPhoneCodeFlowWithRealProvider(): void
    {
        $sent = [];
        Verification::setSmsProvider(new class($sent) implements SmsProvider {
            public function __construct(public array &$sent) {}
            public function name(): string { return 'fake'; }
            public function canSend(): bool { return true; }
            public function send(string $e164, string $message): bool { $this->sent[] = $message; return true; }
        });
        Config::set(['verification' => ['require_phone' => true]]);
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $res = Verification::verifyEmail($this->lastToken());
        $this->assertNotNull($res['phone_token']);
        preg_match('/(\d{6})/', $sent[0], $m);
        $this->assertSame('wrong', Verification::checkPhoneCode($res['phone_token'], $m[1] === '000000' ? '111111' : '000000'));
        $this->assertSame('ok', Verification::checkPhoneCode($res['phone_token'], $m[1]));
        $this->assertSame('confirmed', Db::val('SELECT status FROM signups'));
        $this->assertSame('verified', Db::val('SELECT phone_status FROM volunteers'));
    }

    public function testPhoneCodeAttemptsLocked(): void
    {
        $sent = [];
        Verification::setSmsProvider(new class($sent) implements SmsProvider {
            public function __construct(public array &$sent) {}
            public function name(): string { return 'fake'; }
            public function canSend(): bool { return true; }
            public function send(string $e164, string $message): bool { $this->sent[] = $message; return true; }
        });
        Config::set(['verification' => ['require_phone' => true, 'max_code_attempts' => 3]]);
        $shift = $this->makeShift($this->makeSite(), 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $tok = Verification::verifyEmail($this->lastToken())['phone_token'];
        preg_match('/(\d{6})/', $sent[0], $m);
        $bad = $m[1] === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('wrong', Verification::checkPhoneCode($tok, $bad));
        }
        $this->assertSame('locked', Verification::checkPhoneCode($tok, $m[1]));
    }

    public function testRateLimiter(): void
    {
        $this->assertTrue(RateLimiter::hit('t', 'a', 2, 60));
        $this->assertTrue(RateLimiter::hit('t', 'a', 2, 60));
        $this->assertFalse(RateLimiter::hit('t', 'a', 2, 60));
        $this->assertTrue(RateLimiter::hit('t', 'b', 2, 60));
    }
}
