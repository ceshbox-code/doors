<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testMoney(): void
    {
        $nb = "\u{00A0}";
        $this->assertSame("1{$nb}234{$nb}567,50", Format::money('1234567.5'));
        $this->assertSame("-1{$nb}234,50", Format::money('-1234.5'));
        $this->assertSame('0,00', Format::money('0'));
        $this->assertSame('0,00', Format::money(null));
        $this->assertSame('999,99', Format::money('999.99'));
        $this->assertSame("1{$nb}000,00", Format::money('1000'));
        $this->assertSame('0,12', Format::money('0.125'));        // отображение усекает; округление выполняется при расчёте суммы работ (Money::multiply)
    }

    public function testQty(): void
    {
        $this->assertSame('2,5', Format::qty('2.500'));
        $this->assertSame('1', Format::qty('1.000'));
        $this->assertSame('100', Format::qty('100.000'));
        $this->assertSame('10', Format::qty('10'));
        $this->assertSame('0', Format::qty('0.000'));
        $this->assertSame('0', Format::qty(null));
    }

    public function testDates(): void
    {
        $this->assertSame('14.11.2026 00:30', Format::dateTime('2026-11-13 22:30:00', 'Europe/Kaliningrad'));
        $this->assertSame('14.11.2026', Format::date('2026-11-13 22:30:00', 'Europe/Kaliningrad'));
        $this->assertSame('13.11.2026 22:30', Format::dateTime('2026-11-13 22:30:00', 'UTC'));
        $this->assertSame('', Format::dateTime(null, 'UTC'));
        $this->assertSame('14.11.2026', Format::dateIso('2026-11-14'));
    }
}
