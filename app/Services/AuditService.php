<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditRepository;

final class AuditService
{
    private AuditRepository $repo;

    public function __construct(?AuditRepository $repo = null)
    {
        $this->repo = $repo ?? new AuditRepository();
    }

    public function log(
        ?int $userId, string $action, string $entityType, ?int $entityId = null,
        ?array $old = null, ?array $new = null, ?string $reason = null, ?string $ip = null
    ): void {
        $this->repo->insert($userId, $action, $entityType, $entityId, $old, $new, $reason, $ip);
    }
}
