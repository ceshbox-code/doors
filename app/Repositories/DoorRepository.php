<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Door;

final class DoorRepository
{
    /**
     * Следующий номер D-NNNNNN. Вызывать ТОЛЬКО внутри транзакции: строка счётчика блокируется
     * до её конца, поэтому параллельные запросы получают разные номера, а при откате номер не теряется.
     */
    public function nextNumber(): string
    {
        $pdo = Database::pdo();
        $pdo->exec("INSERT IGNORE INTO door_sequences (name, last_value) VALUES ('door', 0)");
        $pdo->exec("UPDATE door_sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE name = 'door'");
        $n = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        if ($n < 1) {
            throw new \RuntimeException('Не удалось получить номер двери');
        }
        return Door::formatNumber($n);
    }

    public function insert(string $number, int $createdBy): int
    {
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO doors (number, created_by) VALUES (?, ?)')->execute([$number, $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM doors WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findByNumber(string $number): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM doors WHERE number = ?');
        $st->execute([$number]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array,1:int} */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if (isset($f['q']) && $f['q'] !== '') {
            $where[] = 'd.number LIKE ?';
            $args[] = '%' . addcslashes(strtoupper($f['q']), '%_\\') . '%';
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM doors d' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $st = $pdo->prepare(
            "SELECT d.*, (SELECT COUNT(*) FROM work_records wr WHERE wr.door_id = d.id AND wr.voided_at IS NULL) AS works_count
             FROM doors d{$w} ORDER BY d.id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    /** Виды работ, по которым у двери есть действующие записи (без исполнителей и сумм). */
    public function registeredWorkTypes(int $doorId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT DISTINCT wt.id, wt.name FROM work_records wr JOIN work_types wt ON wt.id = wr.work_type_id
             WHERE wr.door_id = ? AND wr.voided_at IS NULL ORDER BY wt.name'
        );
        $st->execute([$doorId]);
        return $st->fetchAll();
    }
}
