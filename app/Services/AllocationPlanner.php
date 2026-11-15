<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Helpers\Money;

/** Чистая логика распределения выплаты по начислениям (ТЗ 6.4) — без обращения к БД. */
final class AllocationPlanner
{
    /**
     * Автоматическое распределение: на самые ранние неоплаченные начисления.
     *
     * @param array<int,array{id:int,outstanding:string}> $outstanding упорядочены от раннего к позднему
     * @return array{plan:array<int,array{work_record_id:int,amount:string}>,remainder:string}
     */
    public static function auto(string $amount, array $outstanding): array
    {
        $left = Money::add($amount, '0');
        $plan = [];
        foreach ($outstanding as $o) {
            if (Money::cmp($left, '0') <= 0) {
                break;
            }
            if (Money::cmp($o['outstanding'], '0') <= 0) {
                continue;
            }
            $take = Money::cmp($left, $o['outstanding']) <= 0 ? $left : $o['outstanding'];
            $plan[] = ['work_record_id' => (int) $o['id'], 'amount' => Money::add($take, '0')];
            $left = Money::sub($left, $take);
        }
        return ['plan' => $plan, 'remainder' => $left];
    }

    /**
     * Проверка ручного распределения: каждое начисление доступно и не превышен его остаток, сумма равна сумме выплаты.
     *
     * @param array<int,array{work_record_id:int,amount:string}> $allocations
     * @param array<int,string> $outstandingById остатки доступных начислений
     */
    public static function validate(string $amount, array $allocations, array $outstandingById): void
    {
        $sum = '0.00';
        foreach ($allocations as $a) {
            $id = $a['work_record_id'];
            if (!isset($outstandingById[$id])) {
                throw new ApiException('ALLOCATION_INVALID',
                    "Начисление №{$id} недоступно: не найдено, не принадлежит получателю, аннулировано, полностью оплачено или не входит в расчёт", 422,
                    ['work_record_id' => $id]);
            }
            if (Money::cmp($a['amount'], $outstandingById[$id]) > 0) {
                throw new ApiException('ALLOCATION_EXCEEDS_OUTSTANDING',
                    "Сумма по начислению №{$id} превышает его неоплаченный остаток {$outstandingById[$id]}", 409,
                    ['work_record_id' => $id, 'outstanding' => $outstandingById[$id], 'requested' => $a['amount']]);
            }
            $sum = Money::add($sum, $a['amount']);
        }
        if (Money::cmp($sum, $amount) !== 0) {
            throw new ApiException('ALLOCATION_SUM_MISMATCH', 'Сумма распределения должна равняться сумме выплаты', 422,
                ['allocated' => $sum, 'amount' => $amount]);
        }
    }
}
