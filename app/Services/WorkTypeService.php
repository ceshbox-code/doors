<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Permissions;
use App\Core\Tx;
use App\Helpers\Input;
use App\Models\WorkType;
use App\Repositories\WorkTypeRepository;
use App\Validators\WorkTypeValidator;
use PDOException;

final class WorkTypeService
{
    private WorkTypeRepository $types;
    private AuditService $audit;

    public function __construct()
    {
        $this->types = new WorkTypeRepository();
        $this->audit = new AuditService();
    }

    private static function seesInactive(array $actor): bool
    {
        return Permissions::can($actor['role'], 'work_types.view_all');
    }

    public function list(array $actor, array $query): array
    {
        $f = [];
        if (!self::seesInactive($actor)) {
            $f['is_active'] = 1;                       // исполнителям — только действующие виды работ
        } elseif (isset($query['is_active']) && in_array((string) $query['is_active'], ['0', '1'], true)) {
            $f['is_active'] = (int) $query['is_active'];
        }
        if (isset($query['q']) && is_string($query['q'])) {
            $f['q'] = trim($query['q']);
        }
        [$page, $per] = Input::paging($query, 100);
        [$rows, $total] = $this->types->search($f, $per, ($page - 1) * $per);
        return ['items' => array_map([WorkType::class, 'present'], $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public function get(array $actor, int $id): array
    {
        $row = $id > 0 ? $this->types->find($id) : null;
        if ($row === null || (!(int) $row['is_active'] && !self::seesInactive($actor))) {
            throw new ApiException('NOT_FOUND', 'Вид работы не найден', 404);
        }
        return WorkType::present($row);
    }

    public function create(array $actor, array $input, string $ip): array
    {
        $d = WorkTypeValidator::create($input);
        if ($this->types->findByName($d['name']) !== null) {
            throw new ApiException('WORK_TYPE_EXISTS', 'Вид работы с таким названием уже существует', 409);
        }
        return Tx::run(function () use ($actor, $d, $ip) {
            try {
                $id = $this->types->insert($d);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ApiException('WORK_TYPE_EXISTS', 'Вид работы с таким названием уже существует', 409);
                }
                throw $e;
            }
            $this->audit->log((int) $actor['id'], 'work_type.create', 'work_type', $id, null, $d, null, $ip);
            return WorkType::present($this->types->find($id));
        });
    }

    public function update(array $actor, int $id, array $input, string $ip): array
    {
        $d = WorkTypeValidator::update($input);
        return Tx::run(function () use ($actor, $id, $d, $ip) {
            $row = $id > 0 ? $this->types->find($id, true) : null;
            if ($row === null) {
                throw new ApiException('NOT_FOUND', 'Вид работы не найден', 404);
            }
            if (isset($d['name']) && $d['name'] !== $row['name']) {
                $other = $this->types->findByName($d['name']);
                if ($other !== null && (int) $other['id'] !== $id) {
                    throw new ApiException('WORK_TYPE_EXISTS', 'Вид работы с таким названием уже существует', 409);
                }
            }
            $old = [];
            $new = [];
            foreach ($d as $k => $v) {
                $cur = in_array($k, ['door_required', 'allow_multiple_per_door'], true) ? (bool) (int) $row[$k] : $row[$k];
                if ($cur !== $v) {
                    $old[$k] = $cur;
                    $new[$k] = $v;
                }
            }
            if ($new) {
                $this->types->update($id, $new);
                $this->audit->log((int) $actor['id'], 'work_type.update', 'work_type', $id, $old, $new, null, $ip);
            }
            return WorkType::present($this->types->find($id));
        });
    }

    public function setActive(array $actor, int $id, bool $active, string $ip): array
    {
        return Tx::run(function () use ($actor, $id, $active, $ip) {
            $row = $id > 0 ? $this->types->find($id, true) : null;
            if ($row === null) {
                throw new ApiException('NOT_FOUND', 'Вид работы не найден', 404);
            }
            if ((bool) (int) $row['is_active'] !== $active) {
                $this->types->update($id, ['is_active' => $active]);
                $this->audit->log((int) $actor['id'], $active ? 'work_type.activate' : 'work_type.deactivate', 'work_type', $id,
                    ['is_active' => !$active], ['is_active' => $active], null, $ip);
            }
            return WorkType::present($this->types->find($id));
        });
    }
}
