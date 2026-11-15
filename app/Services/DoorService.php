<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Permissions;
use App\Core\Tx;
use App\Helpers\Input;
use App\Models\Door;
use App\Models\WorkRecord;
use App\Repositories\DoorRepository;
use App\Repositories\WorkRecordRepository;

final class DoorService
{
    private DoorRepository $doors;
    private WorkRecordRepository $records;
    private AuditService $audit;

    public function __construct()
    {
        $this->doors = new DoorRepository();
        $this->records = new WorkRecordRepository();
        $this->audit = new AuditService();
    }

    /** Создание двери вручную (администратор). Обычно дверь создаётся автоматически при регистрации работы. */
    public function create(array $actor, string $ip): array
    {
        return Tx::run(function () use ($actor, $ip) {
            $number = $this->doors->nextNumber();
            $id = $this->doors->insert($number, (int) $actor['id']);
            $this->audit->log((int) $actor['id'], 'door.create', 'door', $id, null, ['number' => $number, 'source' => 'manual'], null, $ip);
            return Door::present($this->doors->find($id));
        });
    }

    public function list(array $query): array
    {
        $f = [];
        if (isset($query['q']) && is_string($query['q'])) {
            $f['q'] = trim($query['q']);
        }
        [$page, $per] = Input::paging($query, 50);
        [$rows, $total] = $this->doors->search($f, $per, ($page - 1) * $per);
        $items = array_map(static function (array $r): array {
            return Door::present($r) + ['works_count' => (int) $r['works_count']];
        }, $rows);
        return ['items' => $items, 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    /** Полная карточка: дверь + история операций (для администратора и директора). */
    public function card(int $id): array
    {
        $door = $id > 0 ? $this->doors->find($id) : null;
        if ($door === null) {
            throw new ApiException('DOOR_NOT_FOUND', 'Дверь не найдена', 404);
        }
        return $this->fullCard($door);
    }

    public function history(int $id): array
    {
        $door = $id > 0 ? $this->doors->find($id) : null;
        if ($door === null) {
            throw new ApiException('DOOR_NOT_FOUND', 'Дверь не найдена', 404);
        }
        return $this->historyItems((int) $door['id']);
    }

    /** Поиск по номеру. Исполнитель получает краткую карточку без чужих имён и сумм. */
    public function byNumber(array $actor, string $rawNumber): array
    {
        $number = Door::normalizeNumber($rawNumber);
        $door = $number !== null ? $this->doors->findByNumber($number) : null;
        if ($door === null) {
            throw new ApiException('DOOR_NOT_FOUND', 'Дверь с таким номером не найдена', 404);
        }
        if (Permissions::can($actor['role'], 'doors.view')) {
            return $this->fullCard($door);
        }
        return Door::present($door) + [
            'registered_work_types' => array_map(
                static fn(array $t): array => ['id' => (int) $t['id'], 'name' => $t['name']],
                $this->doors->registeredWorkTypes((int) $door['id'])
            ),
        ];
    }

    private function fullCard(array $door): array
    {
        return Door::present($door) + ['history' => $this->historyItems((int) $door['id'])];
    }

    private function historyItems(int $doorId): array
    {
        [$rows] = $this->records->search(['door_id' => $doorId, 'status' => 'all', 'order' => 'asc'], 500, 0);
        return array_map([WorkRecord::class, 'present'], $rows);
    }
}
