<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;
use App\Helpers\PasswordHasher;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Validators\UserValidator;
use PDOException;
use Throwable;

final class UserService
{
    private UserRepository $users;
    private AuditService $audit;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->audit = new AuditService();
    }

    /** @return array{items:array,page:int,per_page:int,total:int} */
    public function list(array $query): array
    {
        $filters = [];
        if (isset($query['role']) && $query['role'] !== '') {
            if (!in_array($query['role'], User::ROLES, true)) {
                throw new ApiException('VALIDATION_ERROR', 'Неизвестная роль', 422, ['role' => 'owner, master или director']);
            }
            $filters['role'] = $query['role'];
        }
        if (isset($query['is_active']) && in_array((string) $query['is_active'], ['0', '1'], true)) {
            $filters['is_active'] = (int) $query['is_active'];
        }
        if (isset($query['q']) && is_string($query['q'])) {
            $filters['q'] = trim($query['q']);
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $per  = min(200, max(1, (int) ($query['per_page'] ?? 50)));

        [$rows, $total] = $this->users->search($filters, $per, ($page - 1) * $per);
        return [
            'items'    => array_map([User::class, 'present'], $rows),
            'page'     => $page,
            'per_page' => $per,
            'total'    => $total,
        ];
    }

    public function get(int $id): array
    {
        return User::present($this->require($id));
    }

    public function create(array $actor, array $input, string $ip): array
    {
        $d = UserValidator::create($input);
        if ($this->users->findByUsername($d['username']) !== null) {
            throw new ApiException('USERNAME_TAKEN', 'Такой логин уже существует', 409);
        }

        return $this->transaction(function () use ($actor, $d, $ip) {
            try {
                $id = $this->users->insert($d['full_name'], $d['username'], PasswordHasher::hash($d['password']), $d['role']);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {     // гонка: тот же логин успели создать параллельно
                    throw new ApiException('USERNAME_TAKEN', 'Такой логин уже существует', 409);
                }
                throw $e;
            }
            $row = $this->users->find($id);
            $this->audit->log((int) $actor['id'], 'user.create', 'user', $id, null, [
                'full_name' => $d['full_name'], 'username' => $d['username'], 'role' => $d['role'],
            ], null, $ip);
            return User::present($row);
        });
    }

    public function update(array $actor, int $id, array $input, string $ip): array
    {
        $d = UserValidator::update($input);

        return $this->transaction(function () use ($actor, $id, $d, $ip) {
            $row = $this->users->find($id, true);
            if ($row === null) {
                throw new ApiException('NOT_FOUND', 'Пользователь не найден', 404);
            }

            $old = [];
            $new = [];
            $fields = [];

            if (isset($d['full_name']) && $d['full_name'] !== $row['full_name']) {
                $old['full_name'] = $row['full_name'];
                $new['full_name'] = $fields['full_name'] = $d['full_name'];
            }
            if (isset($d['role']) && $d['role'] !== $row['role']) {
                if ((int) $actor['id'] === $id) {
                    throw new ApiException('SELF_ROLE_CHANGE', 'Нельзя изменить собственную роль', 409);
                }
                if ($row['role'] === 'owner' && (int) $row['is_active']) {
                    $this->assertNotLastOwner($id);
                }
                $old['role'] = $row['role'];
                $new['role'] = $fields['role'] = $d['role'];
            }
            if (isset($d['password'])) {
                $fields['password_hash'] = PasswordHasher::hash($d['password']);
                $new['password_changed'] = true;
            }

            if ($fields) {
                $this->users->update($id, $fields);
                $this->audit->log((int) $actor['id'], 'user.update', 'user', $id, $old ?: null, $new, null, $ip);
            }
            return User::present($this->users->find($id));
        });
    }

    public function setActive(array $actor, int $id, bool $active, string $ip): array
    {
        return $this->transaction(function () use ($actor, $id, $active, $ip) {
            $row = $this->users->find($id, true);
            if ($row === null) {
                throw new ApiException('NOT_FOUND', 'Пользователь не найден', 404);
            }
            if ((bool) (int) $row['is_active'] === $active) {
                return User::present($row);          // уже в нужном состоянии
            }
            if (!$active) {
                if ((int) $actor['id'] === $id) {
                    throw new ApiException('SELF_DEACTIVATE', 'Нельзя деактивировать самого себя', 409);
                }
                if ($row['role'] === 'owner') {
                    $this->assertNotLastOwner($id);
                }
            }
            $this->users->update($id, ['is_active' => $active ? 1 : 0]);
            $this->audit->log(
                (int) $actor['id'], $active ? 'user.activate' : 'user.deactivate', 'user', $id,
                ['is_active' => !$active], ['is_active' => $active], null, $ip
            );
            return User::present($this->users->find($id));
        });
    }

    private function require(int $id): array
    {
        $row = $id > 0 ? $this->users->find($id) : null;
        if ($row === null) {
            throw new ApiException('NOT_FOUND', 'Пользователь не найден', 404);
        }
        return $row;
    }

    /** Должен остаться хотя бы один действующий администратор (проверка под блокировкой строк). */
    private function assertNotLastOwner(int $id): void
    {
        $ids = array_map('intval', $this->users->lockActiveOwnerIds());
        $others = array_filter($ids, static fn(int $x) => $x !== $id);
        if (!$others) {
            throw new ApiException('LAST_OWNER', 'Нельзя оставить систему без действующего администратора', 409);
        }
    }

    private function transaction(callable $fn)
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
