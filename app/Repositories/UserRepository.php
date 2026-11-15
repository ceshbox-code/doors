<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class UserRepository
{
    private const UPDATABLE = ['full_name', 'role', 'password_hash', 'is_active'];

    private function pdo(): PDO
    {
        return Database::pdo();
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        $st = $this->pdo()->prepare('SELECT * FROM users WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $st = $this->pdo()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        return $st->fetch() ?: null;
    }

    /** @return array{0:array,1:int} [строки, всего] */
    public function search(array $f, int $limit, int $offset): array
    {
        $where = [];
        $args = [];
        if (isset($f['role'])) {
            $where[] = 'role = ?';
            $args[] = $f['role'];
        }
        if (isset($f['is_active'])) {
            $where[] = 'is_active = ?';
            $args[] = (int) $f['is_active'];
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(full_name LIKE ? OR username LIKE ?)';
            $args[] = $like;
            $args[] = $like;
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $st = $this->pdo()->prepare('SELECT COUNT(*) FROM users' . $w);
        $st->execute($args);
        $total = (int) $st->fetchColumn();

        $limit  = max(1, $limit);
        $offset = max(0, $offset);
        $st = $this->pdo()->prepare("SELECT * FROM users{$w} ORDER BY full_name, id LIMIT {$limit} OFFSET {$offset}");
        $st->execute($args);
        return [$st->fetchAll(), $total];
    }

    public function insert(string $fullName, string $username, string $passwordHash, string $role): int
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$fullName, $username, $passwordHash, $role]);
        return (int) $this->pdo()->lastInsertId();
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
            $args[] = $val;
        }
        if (!$sets) {
            return;
        }
        $args[] = $id;
        $this->pdo()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    }

    /** Блокирует и возвращает id действующих администраторов (защита от удаления последнего). */
    public function lockActiveOwnerIds(): array
    {
        return $this->pdo()->query("SELECT id FROM users WHERE role = 'owner' AND is_active = 1 FOR UPDATE")
            ->fetchAll(PDO::FETCH_COLUMN);
    }
}
