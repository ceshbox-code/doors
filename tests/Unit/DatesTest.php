<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\Dates;
use PHPUnit\Framework\TestCase;

final class DatesTest extends TestCase
{
    public function testParseToUtc(): void
    {
        $this->assertSame('2026-11-30 22:00:00', Dates::parseToUtc('2026-12-01T00:00:00+02:00'));
        $this->assertSame('2026-12-01 00:00:00', Dates::parseToUtc('2026-12-01T00:00:00Z'));
        $this->assertSame('2026-12-01 10:30:00', Dates::parseToUtc('2026-12-01 10:30:00Z'));
        $this->assertNull(Dates::parseToUtc('2026-12-01T00:00:00'));      // без часового пояса — отказ
        $this->assertNull(Dates::parseToUtc('вчера'));
    }

    public function testLocalDayToUtc(): void
    {
        $this->assertSame('2026-11-12 22:00:00', Dates::localDayToUtc('2026-11-13', 'Europe/Kaliningrad'));
        $this->assertSame('2026-11-13 22:00:00', Dates::localDayToUtc('2026-11-13', 'Europe/Kaliningrad', true));
        $this->assertNull(Dates::localDayToUtc('2026-02-30', 'UTC'));
        $this->assertNull(Dates::localDayToUtc('13.11.2026', 'UTC'));
    }

    public function testToIso(): void
    {
        $this->assertSame('2026-11-13T05:06:52Z', Dates::toIso('2026-11-13 05:06:52'));
        $this->assertNull(Dates::toIso(null));
    }
}
