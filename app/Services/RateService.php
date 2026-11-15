<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Tx;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Models\Rate;
use App\Repositories\RateRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkTypeRepository;
use App\Validators\RateValidator;

/**
 * Расценки (ТЗ раздел 5). Прошлые записи не изменяются и не удаляются: новая расценка
 * только закрывает предыдущую открытую (valid_to) и начинается не раньше неё.
 * Уже зарегистрированные работы хранят собственный снимок цены и не пересчитываются.
 */
final class RateService
{
    /** Допуск на рассинхрон часов: расценку нельзя назначить «задним числом» глубже этого. */
    private const PAST_TOLERANCE_SECONDS = 60;

    private RateRepository $rates;
    private UserRepository $users;
    private WorkTypeRepository $types;
    private AuditService $audit;

    public function __construct()
    {
        $this->rates = new RateRepository();
        $this->users = new UserRepository();
        $this->types = new WorkTypeRepository();
        $this->audit = new AuditService();
    }

    public function create(array $actor, array $input, string $ip): array
    {
        $d = RateValidator::create($input);

        return Tx::run(function () use ($actor, $d, $ip) {
            $user = $this->users->find($d['user_id']);
            if ($user === null) {
                throw new ApiException('USER_NOT_FOUND', 'Исполнитель не найден', 404);
            }
            if (!(int) $user['is_active']) {
                throw new ApiException('USER_INACTIVE', 'Исполнитель деактивирован', 409);
            }
            if (!in_array($user['role'], ['master', 'owner'], true)) {
                throw new ApiException('INVALID_EXECUTOR', 'Расценки назначаются только исполнителям работ', 422);
            }
            if ($this->types->find($d['work_type_id']) === null) {
                throw new ApiException('WORK_TYPE_NOT_FOUND', 'Вид работы не найден', 404);
            }

            $from = $d['valid_from'] ?? Dates::nowUtc();
            if ($from < gmdate('Y-m-d H:i:s', time() - self::PAST_TOLERANCE_SECONDS)) {
                throw new ApiException('RATE_IN_PAST', 'Расценку нельзя назначить прошедшей датой. Укажите текущий момент или будущую дату.', 422);
            }

            // Блокировка пары «исполнитель — вид работы»: интервалы не пересекаются даже при параллельных запросах
            $existing = $this->rates->lockPair($d['user_id'], $d['work_type_id']);
            $latest = $existing[0] ?? null;
            $closedId = null;
            if ($latest !== null) {
                if ($latest['valid_to'] === null) {
                    if ($from <= $latest['valid_from']) {
                        throw new ApiException('RATE_OVERLAP', 'Дата начала должна быть позже начала действующей расценки', 409);
                    }
                    $this->rates->setValidTo((int) $latest['id'], $from);
                    $closedId = (int) $latest['id'];
                } elseif ($from < $latest['valid_to']) {
                    throw new ApiException('RATE_OVERLAP', 'Период пересекается с предыдущей расценкой', 409);
                }
            }

            $id = $this->rates->insert($d['user_id'], $d['work_type_id'], $d['unit_price'], $from, (int) $actor['id']);
            $this->audit->log((int) $actor['id'], 'rate.create', 'rate', $id, null, [
                'user_id' => $d['user_id'], 'work_type_id' => $d['work_type_id'],
                'unit_price' => $d['unit_price'], 'valid_from' => $from, 'closed_previous_rate_id' => $closedId,
            ], null, $ip);
            return Rate::present($this->rates->find($id));
        });
    }

    /** Завершить действие расценки без назначения новой (после этого регистрация по виду работы блокируется). */
    public function close(array $actor, int $id, array $input, string $ip): array
    {
        $to = RateValidator::close($input);

        return Tx::run(function () use ($actor, $id, $to, $ip) {
            $rate = $id > 0 ? $this->rates->find($id, true) : null;
            if ($rate === null) {
                throw new ApiException('NOT_FOUND', 'Расценка не найдена', 404);
            }
            if ($rate['valid_to'] !== null) {
                throw new ApiException('RATE_ALREADY_CLOSED', 'Действие этой расценки уже завершено', 409);
            }
            $to = $to ?? Dates::nowUtc();
            if ($to <= $rate['valid_from']) {
                throw new ApiException('VALIDATION_ERROR', 'Дата окончания должна быть позже начала действия', 422);
            }
            if ($to < gmdate('Y-m-d H:i:s', time() - self::PAST_TOLERANCE_SECONDS)) {
                throw new ApiException('RATE_IN_PAST', 'Завершить расценку прошедшей датой нельзя', 422);
            }
            $this->rates->setValidTo($id, $to);
            $this->audit->log((int) $actor['id'], 'rate.close', 'rate', $id, ['valid_to' => null], ['valid_to' => $to], null, $ip);
            return Rate::present($this->rates->find($id));
        });
    }

    public function get(int $id): array
    {
        $row = $id > 0 ? $this->rates->find($id) : null;
        if ($row === null) {
            throw new ApiException('NOT_FOUND', 'Расценка не найдена', 404);
        }
        return Rate::present($row);
    }

    /** @param bool $history true — вся история, новые изменения первыми */
    public function list(array $query, ?int $forUserId = null, bool $history = false): array
    {
        $f = [];
        $uid = $forUserId ?? (isset($query['user_id']) ? Input::id($query['user_id']) : null);
        if ($uid !== null) {
            $f['user_id'] = $uid;
        }
        if (isset($query['work_type_id']) && ($t = Input::id($query['work_type_id'])) !== null) {
            $f['work_type_id'] = $t;
        }
        if (!$history && isset($query['current']) && (string) $query['current'] === '1') {
            $f['current_at'] = Dates::nowUtc();
        }
        [$page, $per] = Input::paging($query, 100);
        [$rows, $total] = $this->rates->search($f, $per, ($page - 1) * $per, $history);
        return ['items' => array_map([Rate::class, 'present'], $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public function forUser(int $userId, array $query): array
    {
        if ($userId <= 0 || $this->users->find($userId) === null) {
            throw new ApiException('USER_NOT_FOUND', 'Пользователь не найден', 404);
        }
        return $this->list($query, $userId);
    }
}
