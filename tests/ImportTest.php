<?php
declare(strict_types=1);

final class ImportTest extends DbTestCase
{
    public function testFlexibleHeadersAndMissingCoordinates(): void
    {
        $csv = "SITE NAME,Street Address,ZIP Code,Latitude,Longitude,Precinct\n"
            . "Alpha School,\"1 Main St, Tucson, AZ 85701\",85701,32.22,-110.97,12\n"
            . "Beta Church,2 Oak Ave,85702,,,13\n";
        $r = SiteImporter::importString($csv, 'election_day');
        $this->assertSame(2, $r['inserted']);
        $this->assertSame(1, $r['missing_coords']);
        $a = Db::one("SELECT * FROM sites WHERE name = 'Alpha School'");
        $this->assertEquals(32.22, $a['lat']);
        $this->assertSame('12', $a['precinct']);
        $b = Db::one("SELECT * FROM sites WHERE name = 'Beta Church'");
        $this->assertNull($b['lat']);
        $this->assertNull($b['lng']);
    }

    public function testIdempotentUpsertKeepsCoordinates(): void
    {
        $csv = "Name,Address,Lat,Lng\nGamma,3 Elm,32.1,-110.9\n";
        SiteImporter::importString($csv, 'drop_box');
        $r = SiteImporter::importString("Name,Address\nGamma,3 Elm\n", 'drop_box');
        $this->assertSame(0, $r['inserted']);
        $this->assertSame(1, $r['updated']);
        $this->assertSame(0, $r['missing_coords']);
        $this->assertSame(1, (int) Db::val("SELECT COUNT(*) FROM sites WHERE name = 'Gamma'"));
        $this->assertNotNull(Db::val("SELECT lat FROM sites WHERE name = 'Gamma'"));
        // same name+address under another type is a different site
        SiteImporter::importString("Name,Address\nGamma,3 Elm\n", 'early_vote');
        $this->assertSame(2, (int) Db::val("SELECT COUNT(*) FROM sites WHERE name = 'Gamma'"));
    }

    public function testRepositoryCsvFiles(): void
    {
        $dir = dirname(__DIR__);
        foreach (['Ballot Drop Boxes.csv', 'Early Voting.csv', 'Polling Locations Election Day.csv'] as $f) {
            if (!is_file("$dir/$f")) {
                $this->markTestSkipped("$f not present");
            }
            $type = SiteImporter::guessType($f);
            $r = SiteImporter::importFile("$dir/$f", $type);
            $this->assertSame([], $r['errors'], $f);
            $this->assertGreaterThan(0, $r['inserted'], $f);
            $again = SiteImporter::importFile("$dir/$f", $type);
            $this->assertSame(0, $again['inserted'], "$f is idempotent");
        }
        $this->assertGreaterThan(0, (int) Db::val('SELECT COUNT(*) FROM site_hours'));
        // fixed + roaming sites exist
        $this->assertGreaterThan(0, $this->siteId("Pima County Recorder's Office"));
        $this->assertGreaterThan(0, $this->siteId('Pima County Elections Office'));
        $this->assertSame(2, (int) Db::val("SELECT COUNT(*) FROM sites WHERE type = 'roaming'"));
    }

    public function testParsers(): void
    {
        $this->assertSame('06:00', SiteImporter::parseTime('6:00'));
        $this->assertSame('17:30', SiteImporter::parseTime('5:30 PM'));
        $this->assertSame('00:00', SiteImporter::parseTime('12am'));
        $this->assertNull(SiteImporter::parseTime('soon'));
        $this->assertSame('2026-11-03', SiteImporter::parseDate('11/3/2026'));
        $this->assertSame('2026-11-03', SiteImporter::parseDate('2026-11-03'));
        $this->assertNull(SiteImporter::parseDate('13/45/2026'));
    }

    public function testMissingNameColumnIsReported(): void
    {
        $r = SiteImporter::importString("Foo,Bar\n1,2\n", 'drop_box');
        $this->assertNotEmpty($r['errors']);
        $this->assertSame(0, $r['inserted']);
    }
}
