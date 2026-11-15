<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ApiException;
use App\Validators\WorkRecordValidator;
use PHPUnit\Framework\TestCase;

final class WorkRecordValidatorTest extends TestCase
{
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode);
            return $e->details;
        }
        $this->fail('Ожидалась ApiException');
    }

    public function testMinimalValidRequestUsesDefaults(): void
    {
        $d = WorkRecordValidator::register(['work_type_id' => 1, 'new_door' => true]);
        $this->assertSame(1, $d['work_type_id']);
        $this->assertTrue($d['new_door']);
        $this->assertNull($d['door_number']);
        $this->assertSame('1.000', $d['quantity']);
        $this->assertNull($d['comment']);
        $this->assertNull($d['user_id']);
    }

    public function testDoorNumberIsNormalized(): void
    {
        $d = WorkRecordValidator::register(['work_type_id' => '2', 'door_number' => ' d-000012 ', 'quantity' => '2.5']);
        $this->assertSame('D-000012', $d['door_number']);
        $this->assertSame('2.500', $d['quantity']);
        $this->assertSame(2, $d['work_type_id']);
    }

    public function testDoorNumberAndNewDoorAreMutuallyExclusive(): void
    {
        $e = $this->errors(fn() => WorkRecordValidator::register(['work_type_id' => 1, 'door_number' => 'D-000001', 'new_door' => true]));
        $this->assertArrayHasKey('door_number', $e);
    }

    public function testRejectsBadFields(): void
    {
        $e = $this->errors(fn() => WorkRecordValidator::register([
            'door_number' => 'X-1', 'quantity' => 0, 'comment' => str_repeat('я', 2001), 'user_id' => 'abc',
        ]));
        $this->assertEqualsCanonicalizing(['work_type_id', 'door_number', 'quantity', 'comment', 'user_id'], array_keys($e));
    }

    public function testVoidReason(): void
    {
        $this->assertSame('Ошибочная запись', WorkRecordValidator::reason(['reason' => ' Ошибочная запись ']));
        $this->assertArrayHasKey('reason', $this->errors(fn() => WorkRecordValidator::reason(['reason' => 'ок'])));
        $this->assertArrayHasKey('reason', $this->errors(fn() => WorkRecordValidator::reason([])));
    }
}
