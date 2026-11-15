<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ApiException;
use App\Services\AllocationPlanner;
use PHPUnit\Framework\TestCase;

final class AllocationPlannerTest extends TestCase
{
    private function out(): array
    {
        return [
            ['id' => 1, 'outstanding' => '100.00'],
            ['id' => 2, 'outstanding' => '250.00'],
            ['id' => 3, 'outstanding' => '400.00'],
        ];
    }

    public function testAutoCoversEarliestFirstWithPartialLast(): void
    {
        $r = AllocationPlanner::auto('300.00', $this->out());
        $this->assertSame([['work_record_id' => 1, 'amount' => '100.00'], ['work_record_id' => 2, 'amount' => '200.00']], $r['plan']);
        $this->assertSame('0.00', $r['remainder']);
    }

    public function testAutoExactlyCoversWholeAccrual(): void
    {
        $r = AllocationPlanner::auto('350.00', $this->out());
        $this->assertSame([['work_record_id' => 1, 'amount' => '100.00'], ['work_record_id' => 2, 'amount' => '250.00']], $r['plan']);
        $this->assertSame('0.00', $r['remainder']);
    }

    public function testAutoReportsRemainderWhenNotEnoughAccruals(): void
    {
        $r = AllocationPlanner::auto('800.00', $this->out());
        $this->assertCount(3, $r['plan']);
        $this->assertSame('50.00', $r['remainder']);
    }

    public function testAutoSkipsFullyPaidAndSupportsKopecks(): void
    {
        $r = AllocationPlanner::auto('0.10', [['id' => 1, 'outstanding' => '0.00'], ['id' => 2, 'outstanding' => '0.07'], ['id' => 3, 'outstanding' => '5.00']]);
        $this->assertSame([['work_record_id' => 2, 'amount' => '0.07'], ['work_record_id' => 3, 'amount' => '0.03']], $r['plan']);
        $this->assertSame('0.00', $r['remainder']);
    }

    public function testSumOfPlanAlwaysEqualsAmountMinusRemainder(): void
    {
        foreach (['0.01', '99.99', '100.00', '100.01', '749.99', '750.00', '750.01'] as $amount) {
            $r = AllocationPlanner::auto($amount, $this->out());
            $sum = '0.00';
            foreach ($r['plan'] as $p) {
                $sum = bcadd($sum, $p['amount'], 2);
            }
            $this->assertSame(0, bccomp(bcadd($sum, $r['remainder'], 2), $amount, 2), $amount);
        }
    }

    public function testValidateAcceptsExactManualAllocation(): void
    {
        AllocationPlanner::validate('300.00', [
            ['work_record_id' => 1, 'amount' => '100.00'], ['work_record_id' => 3, 'amount' => '200.00'],
        ], [1 => '100.00', 2 => '250.00', 3 => '400.00']);
        $this->assertTrue(true);
    }

    /** @dataProvider badAllocations */
    public function testValidateRejects(string $amount, array $allocs, string $code, int $status): void
    {
        try {
            AllocationPlanner::validate($amount, $allocs, [1 => '100.00', 2 => '250.00']);
            $this->fail('Ожидалась ApiException');
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public function badAllocations(): array
    {
        return [
            'больше остатка начисления' => ['1000.00', [['work_record_id' => 1, 'amount' => '1000.00']], 'ALLOCATION_EXCEEDS_OUTSTANDING', 409],
            'неизвестное начисление'    => ['10.00', [['work_record_id' => 99, 'amount' => '10.00']], 'ALLOCATION_INVALID', 422],
            'сумма не сходится (меньше)' => ['100.00', [['work_record_id' => 1, 'amount' => '50.00']], 'ALLOCATION_SUM_MISMATCH', 422],
            'сумма не сходится (больше)' => ['100.00', [['work_record_id' => 1, 'amount' => '100.00'], ['work_record_id' => 2, 'amount' => '1.00']], 'ALLOCATION_SUM_MISMATCH', 422],
        ];
    }
}
