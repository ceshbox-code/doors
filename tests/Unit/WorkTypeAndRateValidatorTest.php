<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ApiException;
use App\Validators\RateValidator;
use App\Validators\WorkTypeValidator;
use PHPUnit\Framework\TestCase;

final class WorkTypeAndRateValidatorTest extends TestCase
{
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ApiException $e) {
            return $e->details;
        }
        $this->fail('Ожидалась ApiException');
    }

    public function testWorkTypeCreateDefaults(): void
    {
        $d = WorkTypeValidator::create(['name' => ' Установка наличников ']);
        $this->assertSame('Установка наличников', $d['name']);
        $this->assertSame('шт', $d['unit']);
        $this->assertTrue($d['door_required']);
        $this->assertFalse($d['allow_multiple_per_door']);
    }

    public function testWorkTypeCreateFlagsAndUnit(): void
    {
        $d = WorkTypeValidator::create(['name' => 'Монтаж', 'unit' => 'пог.м', 'door_required' => '0', 'allow_multiple_per_door' => true]);
        $this->assertSame('пог.м', $d['unit']);
        $this->assertFalse($d['door_required']);
        $this->assertTrue($d['allow_multiple_per_door']);
    }

    public function testWorkTypeRejectsBadInput(): void
    {
        $e = $this->errors(fn() => WorkTypeValidator::create(['name' => 'а', 'unit' => '', 'door_required' => 'maybe']));
        $this->assertEqualsCanonicalizing(['name', 'unit', 'door_required'], array_keys($e));
        $this->assertArrayHasKey('is_active', $this->errors(fn() => WorkTypeValidator::update(['is_active' => false])));
        $this->assertArrayHasKey('_', $this->errors(fn() => WorkTypeValidator::update([])));
        $this->assertSame(['allow_multiple_per_door' => true], WorkTypeValidator::update(['allow_multiple_per_door' => 1]));
    }

    public function testRateCreate(): void
    {
        $d = RateValidator::create(['user_id' => '5', 'work_type_id' => 2, 'unit_price' => '550.5', 'valid_from' => '2026-12-01T00:00:00+02:00']);
        $this->assertSame(5, $d['user_id']);
        $this->assertSame('550.50', $d['unit_price']);
        $this->assertSame('2026-11-30 22:00:00', $d['valid_from']);
        $this->assertNull(RateValidator::create(['user_id' => 1, 'work_type_id' => 1, 'unit_price' => 0])['valid_from']);
    }

    public function testRateRejectsBadInput(): void
    {
        $e = $this->errors(fn() => RateValidator::create(['unit_price' => -5, 'valid_from' => '2026-12-01']));
        $this->assertEqualsCanonicalizing(['user_id', 'work_type_id', 'unit_price', 'valid_from'], array_keys($e));
    }

    public function testWorkTypeCategory(): void
    {
        $this->assertSame('other', WorkTypeValidator::create(['name' => 'Доп. работа'])['category']);
        $this->assertSame('repair', WorkTypeValidator::create(['name' => 'Ремонт', 'category' => 'repair'])['category']);
        $this->assertSame(['category' => 'packing'], WorkTypeValidator::update(['category' => 'packing']));
        $this->assertArrayHasKey('category', $this->errors(fn() => WorkTypeValidator::create(['name' => 'Тест', 'category' => 'xyz'])));
        $this->assertArrayHasKey('category', $this->errors(fn() => WorkTypeValidator::update(['category' => ''])));
    }
}
