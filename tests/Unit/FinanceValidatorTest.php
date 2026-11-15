<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ApiException;
use App\Services\FinanceService;
use App\Validators\FinanceValidator;
use PHPUnit\Framework\TestCase;

final class FinanceValidatorTest extends TestCase
{
    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $this->assertSame(422, $e->httpStatus);
            return $e->details;
        }
        $this->fail('Ожидалась ApiException');
    }

    public function testPeriod(): void
    {
        $this->assertSame(['from' => null, 'to' => null], FinanceValidator::period([]));
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-30'], FinanceValidator::period(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']));
        $this->assertArrayHasKey('date_to', $this->errors(fn() => FinanceValidator::period(['date_from' => '2026-11-30', 'date_to' => '2026-11-01'])));
        $this->assertArrayHasKey('date_from', $this->errors(fn() => FinanceValidator::period(['date_from' => '30.11.2026'])));
        $this->assertArrayHasKey('date_to', $this->errors(fn() => FinanceValidator::period(['date_from' => '2000-01-01', 'date_to' => '2026-01-01'])));
    }

    public function testPayrollRun(): void
    {
        $d = FinanceValidator::payrollRun(['user_id' => '7', 'period_from' => '2026-11-01', 'period_to' => '2026-11-14']);
        $this->assertSame(7, $d['user_id']);
        $e = $this->errors(fn() => FinanceValidator::payrollRun(['period_from' => '2026-02-30', 'period_to' => 'x']));
        $this->assertEqualsCanonicalizing(['user_id', 'period_from', 'period_to'], array_keys($e));
        $this->assertArrayHasKey('period_to', $this->errors(fn() => FinanceValidator::payrollRun(['user_id' => 1, 'period_from' => '2026-11-14', 'period_to' => '2026-11-01'])));
    }

    public function testPaymentDefaultsAndNormalization(): void
    {
        $d = FinanceValidator::payment(['user_id' => 3, 'amount' => '300', 'payment_method' => ' наличные ']);
        $this->assertSame('300.00', $d['amount']);
        $this->assertSame('наличные', $d['payment_method']);
        $this->assertNull($d['allocations']);
        $this->assertNull($d['payroll_run_id']);
        $this->assertFalse($d['post']);
        $this->assertNull($d['payment_date']);      // дата по умолчанию подставляется в сервисе, а не в хэше идемпотентности
    }

    public function testPaymentDateIsParsedToUtcWhenGiven(): void
    {
        $d = FinanceValidator::payment(['user_id' => 3, 'amount' => '10', 'payment_method' => 'cash', 'payment_date' => '2026-11-14T10:00:00+02:00']);
        $this->assertSame('2026-11-14 08:00:00', $d['payment_date']);
    }

    public function testIdenticalPaymentRequestsGiveIdenticalIdempotencyHash(): void
    {
        $in = ['user_id' => 3, 'amount' => '300', 'payment_method' => 'cash', 'comment' => 'аванс'];
        $a = \App\Services\IdempotencyService::hash(FinanceValidator::payment($in));
        sleep(1);                                    // раньше менялась подставленная «текущая» дата
        $b = \App\Services\IdempotencyService::hash(FinanceValidator::payment($in));
        $this->assertSame($a, $b);
    }

    public function testPaymentWithAllocationsAndPost(): void
    {
        $d = FinanceValidator::payment([
            'user_id' => 3, 'amount' => 300, 'payment_method' => 'card', 'post' => true, 'payroll_run_id' => '5',
            'allocations' => [['work_record_id' => 1, 'amount' => '100'], ['work_record_id' => '2', 'amount' => 200]],
        ]);
        $this->assertTrue($d['post']);
        $this->assertSame(5, $d['payroll_run_id']);
        $this->assertSame([['work_record_id' => 1, 'amount' => '100.00'], ['work_record_id' => 2, 'amount' => '200.00']], $d['allocations']);
    }

    public function testPaymentRejections(): void
    {
        $e = $this->errors(fn() => FinanceValidator::payment(['amount' => 0, 'payment_method' => '', 'payment_date' => '2999-01-01T00:00:00Z']));
        $this->assertEqualsCanonicalizing(['user_id', 'amount', 'payment_method', 'payment_date'], array_keys($e));
        $e = $this->errors(fn() => FinanceValidator::payment([
            'user_id' => 1, 'amount' => 10, 'payment_method' => 'x',
            'allocations' => [['work_record_id' => 1, 'amount' => '5'], ['work_record_id' => 1, 'amount' => '5']],
        ]));
        $this->assertNotEmpty($e);
        $this->assertNotEmpty($this->errors(fn() => FinanceValidator::payment(['user_id' => 1, 'amount' => 10, 'payment_method' => 'x', 'allocations' => []])));
    }

    public function testAdjustment(): void
    {
        $d = FinanceValidator::adjustment(['user_id' => 2, 'type' => 'manual', 'amount' => '-350', 'reason' => 'Начальный остаток']);
        $this->assertSame('-350.00', $d['amount']);
        $e = $this->errors(fn() => FinanceValidator::adjustment(['type' => 'cancel_payment', 'amount' => 0, 'reason' => 'ок']));
        $this->assertEqualsCanonicalizing(['user_id', 'type', 'amount', 'reason'], array_keys($e));
    }

    public function testBalanceFigures(): void
    {
        $f = FinanceService::figures(['accrued' => '770.00', 'paid' => '750.00', 'adjustments' => '-350.00', 'works_count' => 4]);
        $this->assertSame('-330.00', $f['balance']);
        $this->assertSame('0.00', $f['debt']);
        $this->assertSame('330.00', $f['overpayment']);
        $g = FinanceService::figures(['accrued' => '1000', 'paid' => '300', 'adjustments' => '0']);
        $this->assertSame('700.00', $g['balance']);
        $this->assertSame('700.00', $g['debt']);
        $this->assertSame('0.00', $g['overpayment']);
    }
}
