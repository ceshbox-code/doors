<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class Door
{
    public static function formatNumber(int $n): string
    {
        return 'D-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /** 'd-000012' -> 'D-000012'; неверный формат -> null. */
    public static function normalizeNumber(string $raw): ?string
    {
        $n = strtoupper(trim($raw));
        return preg_match('/^D-\d{6,12}$/', $n) ? $n : null;
    }

    public static function present(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'number'     => $r['number'],
            'status'     => $r['status'],
            'created_at' => Dates::toIso($r['created_at']),
            'created_by' => (int) $r['created_by'],
        ];
    }
}
