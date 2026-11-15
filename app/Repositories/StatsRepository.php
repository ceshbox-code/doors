<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Агрегаты по действующим (не аннулированным) работам. Деньги и количества возвращаются DECIMAL-строками. */
final class StatsRepository
{
    /** @return array{0:string,1:array} */
    private function where(array $f, string $extra = ''): array
    {
        if (!empty($f['door_missing'])) {
            return [' WHERE 1 = 0', []];
        }
        $w = ['wr.voided_at IS NULL'];
        $a = [];
        if (!empty($f['from_utc'])) {
            $w[] = 'wr.completed_at >= ?';
            $a[] = $f['from_utc'];
        }
        if (!empty($f['to_utc'])) {
            $w[] = 'wr.completed_at < ?';
            $a[] = $f['to_utc'];
        }
        foreach (['user_id' => 'wr.user_id', 'work_type_id' => 'wr.work_type_id', 'door_id' => 'wr.door_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $w[] = "{$col} = ?";
                $a[] = (int) $f[$k];
            }
        }
        if ($extra !== '') {
            $w[] = $extra;
        }
        return [' WHERE ' . implode(' AND ', $w), $a];
    }

    private function all(string $sql, array $args): array
    {
        $st = Database::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** По категориям видов работ. @return array<int,array{category:string,works:int|string,amount:string}> */
    public function countsByCategory(array $f): array
    {
        [$w, $a] = $this->where($f);
        return $this->all(
            "SELECT wt.category AS category, COUNT(*) AS works, COALESCE(SUM(wr.total_amount), 0) AS amount
             FROM work_records wr JOIN work_types wt ON wt.id = wr.work_type_id{$w} GROUP BY wt.category", $a
        );
    }

    public function distinctDoors(array $f): int
    {
        [$w, $a] = $this->where($f, 'wr.door_id IS NOT NULL');
        $st = Database::pdo()->prepare("SELECT COUNT(DISTINCT wr.door_id) FROM work_records wr{$w}");
        $st->execute($a);
        return (int) $st->fetchColumn();
    }

    public function totals(array $f): array
    {
        [$w, $a] = $this->where($f);
        $st = Database::pdo()->prepare("SELECT COUNT(*) AS works, COALESCE(SUM(wr.total_amount), 0) AS amount FROM work_records wr{$w}");
        $st->execute($a);
        return $st->fetch();
    }

    public function byWorkType(array $f): array
    {
        [$w, $a] = $this->where($f);
        return $this->all(
            "SELECT wt.id, wt.name, wt.unit, wt.category, COUNT(*) AS works, COUNT(DISTINCT wr.door_id) AS doors,
                    COALESCE(SUM(wr.quantity), 0) AS quantity, COALESCE(SUM(wr.total_amount), 0) AS amount
             FROM work_records wr JOIN work_types wt ON wt.id = wr.work_type_id{$w}
             GROUP BY wt.id, wt.name, wt.unit, wt.category ORDER BY works DESC, wt.name", $a
        );
    }

    public function byUserAndType(array $f): array
    {
        [$w, $a] = $this->where($f);
        return $this->all(
            "SELECT u.id AS user_id, u.full_name, u.role, u.is_active, wt.id AS work_type_id, wt.name AS work_type_name, wt.unit,
                    COUNT(*) AS works, COALESCE(SUM(wr.quantity), 0) AS quantity, COALESCE(SUM(wr.total_amount), 0) AS amount
             FROM work_records wr JOIN users u ON u.id = wr.user_id JOIN work_types wt ON wt.id = wr.work_type_id{$w}
             GROUP BY u.id, u.full_name, u.role, u.is_active, wt.id, wt.name, wt.unit ORDER BY u.full_name, u.id, wt.name", $a
        );
    }

    /** @return array<int,int> user_id => число обработанных дверей */
    public function doorsByUser(array $f): array
    {
        [$w, $a] = $this->where($f, 'wr.door_id IS NOT NULL');
        $out = [];
        foreach ($this->all("SELECT wr.user_id, COUNT(DISTINCT wr.door_id) AS doors FROM work_records wr{$w} GROUP BY wr.user_id", $a) as $r) {
            $out[(int) $r['user_id']] = (int) $r['doors'];
        }
        return $out;
    }

    /** Сырые строки для группировки по дням в часовом поясе предприятия (учёт перехода на летнее время — в PHP). */
    public function dailyRows(array $f): array
    {
        [$w, $a] = $this->where($f);
        return $this->all(
            "SELECT wr.completed_at, wr.door_id, wt.category, wr.total_amount
             FROM work_records wr JOIN work_types wt ON wt.id = wr.work_type_id{$w} ORDER BY wr.completed_at, wr.id", $a
        );
    }

    /** @return array{0:array,1:int} */
    public function doors(array $f, int $limit, int $offset): array
    {
        [$w, $a] = $this->where($f, 'wr.door_id IS NOT NULL');
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT COUNT(DISTINCT wr.door_id) FROM work_records wr{$w}");
        $st->execute($a);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $rows = $this->all(
            "SELECT d.id, d.number, d.status, COUNT(wr.id) AS works, COALESCE(SUM(wr.total_amount), 0) AS amount,
                    MIN(wr.completed_at) AS first_at, MAX(wr.completed_at) AS last_at,
                    GROUP_CONCAT(DISTINCT wt.name ORDER BY wt.name SEPARATOR ', ') AS work_types,
                    (SELECT COUNT(*) FROM photos ph JOIN work_records w2 ON w2.id = ph.work_record_id
                     WHERE w2.door_id = d.id AND ph.deleted_at IS NULL AND w2.voided_at IS NULL) AS photos_count
             FROM work_records wr JOIN doors d ON d.id = wr.door_id JOIN work_types wt ON wt.id = wr.work_type_id{$w}
             GROUP BY d.id, d.number, d.status ORDER BY last_at DESC, d.id DESC LIMIT {$limit} OFFSET {$offset}", $a
        );
        return [$rows, $total];
    }
}
