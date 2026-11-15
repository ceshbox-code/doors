<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class Adjustment
{
    public static function present(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'user'            => ['id' => (int) $r['user_id'], 'full_name' => $r['user_name'] ?? null],
            'type'            => $r['type'],
            'amount'          => $r['amount'],
            'affects_balance' => (bool) (int) $r['affects_balance'],
            'work_record_id'  => $r['work_record_id'] === null ? null : (int) $r['work_record_id'],
            'payment_id'      => $r['payment_id'] === null ? null : (int) $r['payment_id'],
            'payroll_run_id'  => $r['payroll_run_id'] === null ? null : (int) $r['payroll_run_id'],
            'reason'          => $r['reason'],
            'created_by'      => ['id' => (int) $r['created_by'], 'full_name' => $r['created_by_name'] ?? null],
            'created_at'      => Dates::toIso($r['created_at']),
        ];
    }
}
