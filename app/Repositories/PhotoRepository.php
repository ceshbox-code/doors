<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PhotoRepository
{
    public function insert(int $recordId, array $p): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO photos (work_record_id, file_name, optimized_file_name, original_name, mime_type, file_size, sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$recordId, $p['file_name'], $p['optimized_file_name'], $p['original_name'], $p['mime'], $p['size'], $p['sha256']]);
        return (int) $pdo->lastInsertId();
    }

    /** Действующая (не удалённая) фотография вместе с владельцем и статусом работы — для проверки прав. */
    public function findWithOwner(int $id): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT p.*, wr.user_id AS owner_id, wr.voided_at AS record_voided_at
             FROM photos p JOIN work_records wr ON wr.id = p.work_record_id
             WHERE p.id = ? AND p.deleted_at IS NULL'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function listByRecord(int $recordId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM photos WHERE work_record_id = ? AND deleted_at IS NULL ORDER BY id');
        $st->execute([$recordId]);
        return $st->fetchAll();
    }

    public function countByRecord(int $recordId): int
    {
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM photos WHERE work_record_id = ? AND deleted_at IS NULL');
        $st->execute([$recordId]);
        return (int) $st->fetchColumn();
    }

    /** @param string[] $shas */
    public function existingShas(int $recordId, array $shas): array
    {
        if (!$shas) {
            return [];
        }
        $in = implode(',', array_fill(0, count($shas), '?'));
        $st = Database::pdo()->prepare("SELECT sha256 FROM photos WHERE work_record_id = ? AND deleted_at IS NULL AND sha256 IN ({$in})");
        $st->execute(array_merge([$recordId], array_values($shas)));
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function softDelete(int $id, int $by, string $reason, string $atUtc): void
    {
        Database::pdo()->prepare('UPDATE photos SET deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ?')
            ->execute([$atUtc, $by, $reason, $id]);
    }
}
