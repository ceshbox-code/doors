<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class WorkRecord
{
    /** Строка из WorkRecordRepository (с join-полями) -> представление для API. */
    public static function present(array $r): array
    {
        $voided = $r['voided_at'] !== null;
        return [
            'id'           => (int) $r['id'],
            'status'       => $voided ? 'voided' : 'active',
            'work_type'    => ['id' => (int) $r['work_type_id'], 'name' => $r['work_type_name']],
            'door'         => $r['door_id'] === null ? null : ['id' => (int) $r['door_id'], 'number' => $r['door_number']],
            'user'         => ['id' => (int) $r['user_id'], 'full_name' => $r['user_name']],
            'quantity'     => $r['quantity'],
            'unit'         => $r['unit'],
            'unit_price'   => $r['unit_price'],
            'total_amount' => $r['total_amount'],
            'rate_id'      => (int) $r['rate_id'],
            'completed_at' => Dates::toIso($r['completed_at']),
            'created_at'   => Dates::toIso($r['created_at']),
            'created_by'   => ['id' => (int) $r['created_by'], 'full_name' => $r['created_by_name']],
            'comment'      => $r['comment'],
            'photos_count' => (int) ($r['photos_count'] ?? 0),
            'voided_at'    => Dates::toIso($r['voided_at']),
            'voided_by'    => $r['voided_by'] === null ? null : ['id' => (int) $r['voided_by'], 'full_name' => $r['voided_by_name']],
            'void_reason'  => $r['void_reason'],
        ];
    }
}
