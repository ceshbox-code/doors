<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class PayrollRun
{
    public static function present(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'user'         => ['id' => (int) $r['user_id'], 'full_name' => $r['user_name'] ?? null],
            'period_from'  => $r['period_from'],
            'period_to'    => $r['period_to'],
            'total_amount' => $r['total_amount'],
            'status'       => $r['status'],
            'created_by'   => ['id' => (int) $r['created_by'], 'full_name' => $r['created_by_name'] ?? null],
            'confirmed_by' => $r['confirmed_by'] === null ? null : ['id' => (int) $r['confirmed_by'], 'full_name' => $r['confirmed_by_name'] ?? null],
            'confirmed_at' => Dates::toIso($r['confirmed_at']),
            'created_at'   => Dates::toIso($r['created_at']),
        ];
    }
}
