<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class WorkType
{
    public const CATEGORIES = ['repair', 'packing', 'other'];

    public static function present(array $r): array
    {
        return [
            'id'                      => (int) $r['id'],
            'name'                    => $r['name'],
            'description'             => $r['description'],
            'unit'                    => $r['unit'],
            'category'                => $r['category'] ?? 'other',
            'door_required'           => (bool) (int) $r['door_required'],
            'allow_multiple_per_door' => (bool) (int) $r['allow_multiple_per_door'],
            'is_active'               => (bool) (int) $r['is_active'],
            'created_at'              => Dates::toIso($r['created_at']),
            'updated_at'              => Dates::toIso($r['updated_at']),
        ];
    }
}
