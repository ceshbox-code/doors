<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class Payment
{
    public static function present(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'user'           => ['id' => (int) $r['user_id'], 'full_name' => $r['user_name'] ?? null],
            'payroll_run_id' => $r['payroll_run_id'] === null ? null : (int) $r['payroll_run_id'],
            'amount'         => $r['amount'],
            'payment_method' => $r['payment_method'],
            'payment_date'   => Dates::toIso($r['payment_date']),
            'reference'      => $r['reference'],
            'comment'        => $r['comment'],
            'status'         => $r['status'],
            'created_by'     => ['id' => (int) $r['created_by'], 'full_name' => $r['created_by_name'] ?? null],
            'created_at'     => Dates::toIso($r['created_at']),
            'posted_at'      => Dates::toIso($r['posted_at']),
        ];
    }
}
