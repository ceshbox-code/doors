<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AuditRepository
{
    private static function json(?array $v): ?string
    {
        return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function insert(
        ?int $userId, string $action, string $entityType, ?int $entityId,
        ?array $old, ?array $new, ?string $reason, ?string $ip
    ): void {
        Database::pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, reason, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $action, $entityType, $entityId, self::json($old), self::json($new), $reason, $ip]);
    }
}
