<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ApiException;
use App\Helpers\Periods;
use PHPUnit\Framework\TestCase;

final class PeriodsTest extends TestCase
{
    private const TZ = 'Europe/Kaliningrad';

    private function utc(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    /** @dataProvider presets */
    public function testPresetsOnSaturday14Nov2026(string $preset, string $from, string $to): void
    {
        $p = Periods::resolve(['period' => $preset], self::TZ, $this->utc('2026-11-14 10:00:00'));
        $this->assertSame([$from, $to], [$p['from'], $p['to']]);
        $this->assertSame($preset, $p['preset']);
    }

    public function presets(): array
    {
        return [
            'сегодня'               => ['today', '2026-11-14', '2026-11-14'],
            'вчера'                 => ['yesterday', '2026-11-13', '2026-11-13'],
            'текущая неделя (пн-вс)' => ['this_week', '2026-11-09', '2026-11-15'],
            'предыдущая неделя'     => ['prev_week', '2026-11-02', '2026-11-08'],
            'текущий месяц'         => ['this_month', '2026-11-01', '2026-11-30'],
            'предыдущий месяц'      => ['prev_month', '2026-10-01', '2026-10-31'],
        ];
    }

    public function testWeekStartsOnMondayEvenOnSunday(): void
    {
        $p = Periods::resolve(['period' => 'this_week'], self::TZ, $this->utc('2026-11-15 10:00:00'));   // воскресенье
        $this->assertSame(['2026-11-09', '2026-11-15'], [$p['from'], $p['to']]);
        $p = Periods::resolve(['period' => 'this_week'], self::TZ, $this->utc('2026-11-09 06:00:00'));   // понедельник
        $this->assertSame(['2026-11-09', '2026-11-15'], [$p['from'], $p['to']]);
    }

    public function testDayIsDeterminedInBusinessTimezone(): void
    {
        // 23:30 UTC 13 ноября = 01:30 14 ноября в Калининграде (UTC+2)
        $p = Periods::resolve(['period' => 'today'], self::TZ, $this->utc('2026-11-13 23:30:00'));
        $this->assertSame('2026-11-14', $p['from']);
    }

    public function testMonthBoundariesAcrossYearEnd(): void
    {
        $p = Periods::resolve(['period' => 'prev_month'], self::TZ, $this->utc('2027-01-10 10:00:00'));
        $this->assertSame(['2026-12-01', '2026-12-31'], [$p['from'], $p['to']]);
        $p = Periods::resolve(['period' => 'this_month'], self::TZ, $this->utc('2028-02-10 10:00:00'));      // високосный год
        $this->assertSame(['2028-02-01', '2028-02-29'], [$p['from'], $p['to']]);
    }

    public function testExplicitDatesAndLabels(): void
    {
        $p = Periods::resolve(['date_from' => '2026-11-01', 'date_to' => '2026-11-14'], self::TZ);
        $this->assertSame(['2026-11-01', '2026-11-14', null], [$p['from'], $p['to'], $p['preset']]);
        $this->assertSame('с 01.11.2026 по 14.11.2026', $p['label']);
        $this->assertSame('За всё время', Periods::resolve([], self::TZ)['label']);
        $this->assertSame('14.11.2026', Periods::resolve(['date_from' => '2026-11-14', 'date_to' => '2026-11-14'], self::TZ)['label']);
        $this->assertSame('с 01.11.2026', Periods::resolve(['date_from' => '2026-11-01'], self::TZ)['label']);
        $this->assertSame('по 14.11.2026', Periods::resolve(['date_to' => '2026-11-14'], self::TZ)['label']);
    }

    public function testPresetLabelContainsDates(): void
    {
        $p = Periods::resolve(['period' => 'today'], self::TZ, $this->utc('2026-11-14 10:00:00'));
        $this->assertSame('Сегодня (14.11.2026)', $p['label']);
    }

    public function testRejectsUnknownPresetAndBadDates(): void
    {
        foreach ([['period' => 'next_year'], ['date_from' => '2026-02-30'], ['date_from' => '2026-11-14', 'date_to' => '2026-11-01']] as $q) {
            try {
                Periods::resolve($q, self::TZ);
                $this->fail('Ожидалась ApiException: ' . json_encode($q));
            } catch (ApiException $e) {
                $this->assertSame(422, $e->httpStatus);
            }
        }
    }
}
