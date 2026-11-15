<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class WorkTypeRepository
{
    private const UPDATABLE = ['name', 'description', 'unit', 'category', 'door_required', 'allow_multiple_per_door', 'is_active'];

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM work_types WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findByName(string $name): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM work_types WHERE name = ?');
        $st->execute([$name]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array,1:int} */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if (isset($f['is_active'])) {
            $where[] = 'is_active = ?';
            $args[] = (int) $f['is_active'];
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $where[] = 'name LIKE ?';
            $args[] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM work_types' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $st = $pdo->prepare("SELECT * FROM work_types{$w} ORDER BY is_active DESC, name, id LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    public function insert(array $d): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO work_types (name, description, unit, category, door_required, allow_multiple_per_door, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([$d['name'], $d['description'], $d['unit'], $d['category'], (int) $d['door_required'], (int) $d['allow_multiple_per_door']]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $sets = [];
        $args = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, self::UPDATABLE, true)) {
                throw new \InvalidArgumentException("Недопустимое поле: {$col}");
            }
            $sets[] = "{$col} = ?";
            $args[] = is_bool($val) ? (int) $val : $val;
        }
        if ($sets) {
            $args[] = $id;
            Database::pdo()->prepare('UPDATE work_types SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        }
    }
}
