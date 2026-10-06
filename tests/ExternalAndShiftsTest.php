<?php
declare(strict_types=1);

final class ExternalAndShiftsTest extends DbTestCase
{
    public function testGenerateShiftsIsIdempotentAndTwoHours(): void
    {
        $this->makeSite('drop_box', 'Box 1');
        $r = Shifts::generate(['drop_box'], '2026-11-03', '2026-11-04');
        $this->assertSame(12, $r['created']); // 7am-7pm => 6 shifts/day x 1 role x 2 days
        $again = Shifts::generate(['drop_box'], '2026-11-03', '2026-11-04');
        $this->assertSame(0, $again['created']);
        $first = Db::one('SELECT * FROM shifts ORDER BY starts_at LIMIT 1');
        $this->assertSame('2026-11-03 07:00:00', $first['starts_at']);
        $this->assertSame('2026-11-03 09:00:00', $first['ends_at']);
    }

    public function testGenerateUsesPerSiteHoursAndRoles(): void
    {
        $site = $this->makeSite('election_day', 'School');
        Db::exec("INSERT INTO site_hours (site_id, day, start_time, end_time, source) VALUES (?, '2026-11-03', '06:00', '19:00', 'import')", [$site]);
        Shifts::generate(['election_day'], '2026-11-03', '2026-11-03', ['use_imported_hours' => true]);
        // 06:00-19:00 => 6 full 2h windows + 1h tail; 3 roles (np, electioneer, external partisan observer)
        $this->assertSame(7 * 3, (int) Db::val('SELECT COUNT(*) FROM shifts'));
        Shifts::generate(['roaming'], '2026-11-03', '2026-11-03');
        $roamRoles = Db::all("SELECT DISTINCT r.code FROM shifts sh JOIN sites s ON s.id=sh.site_id JOIN roles r ON r.id=sh.role_id WHERE s.type='roaming' ORDER BY 1");
        $this->assertSame(['np_observer', 'p_electioneer'], array_column($roamRoles, 'code'));
    }

    public function testCoordinatorFlowAndReportedCoverage(): void
    {
        $site = $this->makeSite('election_day', 'School');
        $shift = $this->makeShift($site, 'p_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        [$cid, $token] = Coordinators::create('Casey', 'casey@example.test');
        Coordinators::assign($cid, $site, Catalog::roleId('p_observer'));
        $this->assertSame($cid, (int) Coordinators::byToken($token)['id']);
        $this->assertNull(Coordinators::byToken(str_repeat('a', 40)));

        Booking::book($this->person('1'), [$shift], 'partisan');
        Verification::verifyEmail($this->lastToken());
        $this->assertSame('interest', Db::val('SELECT status FROM signups'));
        $mail = array_values(array_filter(Mailer::$outbox, fn($m) => $m['to'] === 'casey@example.test'));
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('Person 1', $mail[0]['body']);
        $this->assertNotNull(Db::val('SELECT coordinator_notified_at FROM signups'));

        $csv = "Site,Date,Start,End,Volunteers\nSchool,11/3/2026,7:00 AM,9:00 AM,2\nNope,11/3/2026,7:00,9:00,1\n";
        $r = Coordinators::importReport($cid, $csv);
        $this->assertSame(1, $r['saved']);
        $this->assertCount(1, $r['errors']);
        $s = Shifts::query(['site_id' => $site])[0];
        $this->assertSame(2, $s['reported']);
        $this->assertSame('ok', $s['level']);
        // re-import replaces, count 0 removes
        Coordinators::importReport($cid, "site_id,date,start,end,volunteers\n$site,2026-11-03,07:00,09:00,0\n");
        $this->assertSame('none', Shifts::query(['site_id' => $site])[0]['level']);
    }

    public function testNoCoordinatorLeavesNotificationPendingUntilAssigned(): void
    {
        $site = $this->siteId("Pima County Recorder's Office");
        $shift = $this->makeShift($site, 'p_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        Booking::book($this->person('1'), [$shift], 'partisan');
        Verification::verifyEmail($this->lastToken());
        $this->assertNull(Db::val('SELECT coordinator_notified_at FROM signups'));
        [$cid] = Coordinators::create('Pat', 'pat@example.test');
        Coordinators::assign($cid, $site, Catalog::roleId('p_observer'));
        $this->assertSame(1, Coordinators::notifyPending());
    }

    public function testCoverageLevels(): void
    {
        $site = $this->makeSite('drop_box', 'Box');
        $shift = $this->makeShift($site, 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        $this->assertSame('none', Shifts::query(['affiliation' => 'nonpartisan'])[0]['level']);
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $this->assertSame('none', Shifts::query([])[0]['level'], 'pending holds are not coverage');
        Verification::verifyEmail($this->lastToken());
        $this->assertSame('low', Shifts::query([])[0]['level']);
        Booking::book($this->person('2'), [$shift], 'nonpartisan');
        Verification::verifyEmail($this->lastToken());
        $this->assertSame('ok', Shifts::query([])[0]['level']);
        $this->assertSame([], Shifts::query(['affiliation' => 'partisan']));
    }

    public function testExports(): void
    {
        $site = $this->makeSite('drop_box', '=Evil Box');
        $shift = $this->makeShift($site, 'np_observer', '2026-11-03 07:00:00', '2026-11-03 09:00:00');
        Booking::book($this->person('1'), [$shift], 'nonpartisan');
        $this->assertCount(0, Exports::blastRows(['day' => '2026-11-03']), 'unverified excluded');
        Verification::verifyEmail($this->lastToken());
        $this->assertCount(1, Exports::blastRows(['day' => '2026-11-03']));
        $this->assertCount(0, Exports::blastRows(['day' => '2026-11-04']));
        $this->assertCount(1, Exports::signups(['site_id' => $site]));
        $out = fopen('php://temp', 'r+');
        Exports::writeCsv($out, Exports::BLAST_HEADERS, Exports::blastCsvRows(Exports::blastRows([])));
        rewind($out);
        $csv = stream_get_contents($out);
        $this->assertStringContainsString("'=Evil Box", $csv);
        $this->assertStringContainsString(',+15205550101,', $csv);
    }
}
