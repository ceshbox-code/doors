<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class Rate
{
    public static function present(array $r): array
    {
        $out = [
            'id'           => (int) $r['id'],
            'user_id'      => (int) $r['user_id'],
            'work_type_id' => (int) $r['work_type_id'],
            'unit_price'   => $r['unit_price'],
            'valid_from'   => Dates::toIso($r['valid_from']),
            'valid_to'     => Dates::toIso($r['valid_to']),
            'created_by'   => (int) $r['created_by'],
            'created_at'   => Dates::toIso($r['created_at']),
        ];
        if (isset($r['user_name'])) {
            $out['user_name'] = $r['user_name'];
        }
        if (isset($r['work_type_name'])) {
            $out['work_type_name'] = $r['work_type_name'];
        }
        return $out;
    }
}
