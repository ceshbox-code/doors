<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Door;
use PHPUnit\Framework\TestCase;

final class DoorModelTest extends TestCase
{
    public function testFormatNumber(): void
    {
        $this->assertSame('D-000001', Door::formatNumber(1));
        $this->assertSame('D-000123', Door::formatNumber(123));
        $this->assertSame('D-1234567', Door::formatNumber(1234567));
    }

    public function testNormalizeNumber(): void
    {
        $this->assertSame('D-000012', Door::normalizeNumber(' d-000012 '));
        foreach (['D-12', 'DOOR1', '', 'D-00001A', "D-000001\nX"] as $bad) {
            $this->assertNull(Door::normalizeNumber($bad), $bad);
        }
    }
}
