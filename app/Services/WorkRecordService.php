<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Permissions;
use App\Core\Tx;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Money;
use App\Models\Door;
use App\Models\WorkRecord;
use App\Repositories\AdjustmentRepository;
use App\Repositories\DoorRepository;
use App\Repositories\FinanceRepository;
use App\Repositories\PayrollRepository;
use App\Repositories\RateRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkRecordRepository;
use App\Repositories\WorkTypeRepository;
use App\Validators\WorkRecordValidator;
use PDOException;
use Throwable;

final class WorkRecordService
{
    private WorkRecordRepository $records;
    private DoorRepository $doors;
    private WorkTypeRepository $types;
    private RateRepository $rates;
    private UserRepository $users;
    private IdempotencyService $idem;
    private AuditService $audit;
    private PhotoStorage $storage;
    private PhotoService $photos;
    private PayrollRepository $payroll;
    private FinanceRepository $finance;
    private AdjustmentRepository $adjustments;

    public function __construct()
    {
        $this->records = new WorkRecordRepository();
        $this->doors   = new DoorRepository();
        $this->types   = new WorkTypeRepository();
        $this->rates   = new RateRepository();
        $this->users   = new UserRepository();
        $this->idem    = new IdempotencyService();
        $this->audit   = new AuditService();
        $this->storage = new PhotoStorage();
        $this->photos  = new PhotoService($this->storage);
        $this->payroll = new PayrollRepository();
        $this->finance = new FinanceRepository();
        $this->adjustments = new AdjustmentRepository();
    }

    /**
     * Регистрация выполненной работы с обязательной фотографией (ТЗ 4.1, 4.4).
     * Тяжёлая обработка изображений выполняется ДО транзакции, файлы на постоянное место копируются внутри неё;
     * при любой ошибке не остаётся ни записи, ни «лишней» двери, ни ключа идемпотентности, ни файлов.
     *
     * @param array<int,array{name:string,tmp_name:string,size:int,error:int}> $uploads
     * @return array{status:int,data:array,replayed:bool}
     */
    public function register(array $actor, array $input, array $uploads, string $idemKey, string $ip): array
    {
        if (!IdempotencyService::validKey($idemKey)) {
            throw new ApiException('IDEMPOTENCY_KEY_INVALID', 'Заголовок Idempotency-Key обязателен: 8–100 символов (буквы, цифры, . _ : -)', 400);
        }
        $d = WorkRecordValidator::register($input);

        $actorId = (int) $actor['id'];
        $executorId = $actorId;
        if ($d['user_id'] !== null && $d['user_id'] !== $actorId) {
            if (!Permissions::can($actor['role'], 'work.register_for_others')) {
                throw new ApiException('FORBIDDEN', 'Регистрировать работы за других исполнителей может только администратор', 403);
            }
            $executorId = $d['user_id'];
        }
        if (!$uploads) {
            throw new ApiException('PHOTO_REQUIRED', 'Без фотографии работу зарегистрировать нельзя. Приложите фото (поле photos).', 422);
        }

        // Дешёвая проверка файлов (ошибки загрузки, размер, SHA-256) и защита от повторной отправки
        $prepared = $this->storage->prepare($uploads);
        $shas = array_column($prepared, 'sha256');
        sort($shas);
        $hash = IdempotencyService::hash([
            'executor' => $executorId, 'work_type_id' => $d['work_type_id'], 'door_number' => $d['door_number'],
            'new_door' => $d['new_door'], 'quantity' => $d['quantity'], 'comment' => $d['comment'], 'photos' => $shas,
        ]);
        $done = $this->idem->peek($actorId, $idemKey, 'work_record.create', $hash);
        if ($done !== null) {
            return ['status' => (int) $done['status'], 'data' => $done['data'], 'replayed' => true];
        }

        // Полная проверка содержимого и оптимизированная копия — вне транзакции, чтобы не держать блокировки
        $staged = $this->storage->stage($prepared);
        $placed = [];
        try {
            return Tx::run(function () use ($actor, $actorId, $executorId, $d, $idemKey, $hash, $ip, $staged, &$placed) {
                $this->storage->discard($placed);              // следы прошлой попытки при повторе после взаимной блокировки
                $placed = [];

                $cached = $this->idem->begin($actorId, $idemKey, 'work_record.create', $hash);
                if ($cached !== null) {
                    return ['status' => (int) $cached['status'], 'data' => $cached['data'], 'replayed' => true];
                }

                // 2–3. Вид работы и исполнитель
                $type = $this->types->find($d['work_type_id']);
                if ($type === null) {
                    throw new ApiException('WORK_TYPE_NOT_FOUND', 'Вид работы не найден', 404);
                }
                if (!(int) $type['is_active']) {
                    throw new ApiException('WORK_TYPE_INACTIVE', 'Этот вид работы отключён', 409);
                }
                $executor = $this->users->find($executorId);
                if ($executor === null || !(int) $executor['is_active'] || !in_array($executor['role'], ['master', 'owner'], true)) {
                    throw new ApiException('INVALID_EXECUTOR', 'Исполнитель не найден, деактивирован или не может выполнять работы', 422);
                }

                // 4. Дверь: существующая по номеру, новая (создаётся автоматически) или не требуется
                $doorId = null;
                if ($d['door_number'] !== null) {
                    $door = $this->doors->findByNumber($d['door_number']);
                    if ($door === null) {
                        throw new ApiException('DOOR_NOT_FOUND', 'Дверь с таким номером не найдена. Для новой двери передайте new_door=true.', 404);
                    }
                    $doorId = (int) $door['id'];
                } elseif ($d['new_door']) {
                    $number = $this->doors->nextNumber();
                    $doorId = $this->doors->insert($number, $actorId);
                    $this->audit->log($actorId, 'door.create', 'door', $doorId, null, ['number' => $number, 'source' => 'auto_registration'], null, $ip);
                } elseif ((int) $type['door_required']) {
                    throw new ApiException('DOOR_REQUIRED', 'Для этого вида работы нужна дверь: укажите номер двери или создайте новую (new_door=true)', 422);
                }

                // 5. Допустимость повторной регистрации
                $unique = !(int) $type['allow_multiple_per_door'];
                if ($doorId !== null && $unique) {
                    $dup = $this->records->findActiveDuplicate((int) $type['id'], $doorId);
                    if ($dup !== null) {
                        throw $this->duplicate($dup);
                    }
                }

                // 6–7. Расценка исполнителя на момент выполнения и сумма начисления
                $now = Dates::nowUtc();
                $rate = $this->rates->activeAt($executorId, (int) $type['id'], $now);
                if ($rate === null) {
                    throw new ApiException('RATE_NOT_FOUND', 'Для исполнителя не назначена расценка на этот вид работы. Администратору нужно сначала назначить тариф.', 422);
                }
                $total = Money::multiply($d['quantity'], $rate['unit_price']);

                // 8. Сохранение записи (уникальный индекс — последняя линия защиты от гонки)
                try {
                    $id = $this->records->insert([
                        'work_type_id' => (int) $type['id'], 'door_id' => $doorId, 'user_id' => $executorId,
                        'rate_id' => (int) $rate['id'], 'quantity' => $d['quantity'], 'unit' => $type['unit'],
                        'unit_price' => $rate['unit_price'], 'total_amount' => $total,
                        'allow_unique_per_door' => $unique ? 1 : 0, 'completed_at' => $now,
                        'created_by' => $actorId, 'comment' => $d['comment'],
                    ]);
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && $doorId !== null) {
                        throw $this->duplicate($this->records->findActiveDuplicate((int) $type['id'], $doorId));
                    }
                    throw $e;
                }

                // 9. Фотографии: файлы + строки БД в той же транзакции
                $photos = $this->photos->attach($id, $staged, $placed);

                $row = $this->records->findDetailed($id);
                $presented = WorkRecord::present($row) + ['photos' => $photos];

                // 10. Аудит
                $this->audit->log($actorId, 'work_record.create', 'work_record', $id, null, [
                    'work_type_id' => (int) $type['id'], 'door_id' => $doorId, 'door_number' => $row['door_number'],
                    'user_id' => $executorId, 'quantity' => $d['quantity'], 'unit' => $type['unit'],
                    'rate_id' => (int) $rate['id'], 'unit_price' => $rate['unit_price'], 'total_amount' => $total,
                    'photos' => array_map(static fn(array $p): array => ['sha256' => $p['sha256'], 'size' => $p['size']], $staged),
                ], null, $ip);

                // 11. Подтверждение; ответ сохраняется для повторных запросов с тем же ключом
                $this->idem->complete($actorId, $idemKey, $id, 201, $presented);
                return ['status' => 201, 'data' => $presented, 'replayed' => false];
            });
        } catch (Throwable $e) {
            $this->storage->discard($placed);                  // откат БД уже выполнен — убираем и файлы
            throw $e;
        } finally {
            $this->storage->discardStaged($staged);
        }
    }

    private function duplicate(?array $existing): ApiException
    {
        return new ApiException(
            'DUPLICATE_WORK',
            'Для этой двери такая работа уже зарегистрирована. Повторная регистрация возможна только после аннулирования прежней записи.',
            409,
            $existing ? ['existing_record_id' => (int) $existing['id'], 'completed_at' => Dates::toIso($existing['completed_at'])] : null
        );
    }

    public function list(array $actor, array $query): array
    {
        $status = $query['status'] ?? 'active';
        if (!in_array($status, ['active', 'voided', 'all'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, ['status' => 'active, voided или all']);
        }
        $f = ['status' => $status];

        if (!Permissions::can($actor['role'], 'work.view_all')) {
            $f['user_id'] = (int) $actor['id'];           // исполнитель видит только собственные работы
        } elseif (isset($query['user_id']) && ($u = Input::id($query['user_id'])) !== null) {
            $f['user_id'] = $u;
        }
        if (isset($query['work_type_id']) && ($t = Input::id($query['work_type_id'])) !== null) {
            $f['work_type_id'] = $t;
        }
        [$page, $per] = Input::paging($query, 50);
        $empty = ['items' => [], 'page' => $page, 'per_page' => $per, 'total' => 0];

        if (isset($query['door_number']) && $query['door_number'] !== '') {
            $number = is_string($query['door_number']) ? Door::normalizeNumber($query['door_number']) : null;
            $door = $number !== null ? $this->doors->findByNumber($number) : null;
            if ($door === null) {
                return $empty;
            }
            $f['door_id'] = (int) $door['id'];
        }

        $tz = (string) Config::get('app.timezone', 'UTC');
        $errors = [];
        if (isset($query['date_from']) && $query['date_from'] !== '') {
            $f['from_utc'] = is_string($query['date_from']) ? Dates::localDayToUtc($query['date_from'], $tz) : null;
            if ($f['from_utc'] === null) {
                $errors['date_from'] = 'Формат YYYY-MM-DD';
            }
        }
        if (isset($query['date_to']) && $query['date_to'] !== '') {
            $f['to_utc'] = is_string($query['date_to']) ? Dates::localDayToUtc($query['date_to'], $tz, true) : null;
            if ($f['to_utc'] === null) {
                $errors['date_to'] = 'Формат YYYY-MM-DD';
            }
        }
        if ($errors) {
            throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, $errors);
        }

        [$rows, $total] = $this->records->search($f, $per, ($page - 1) * $per);
        return ['items' => array_map([WorkRecord::class, 'present'], $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public function get(array $actor, int $id): array
    {
        $row = $id > 0 ? $this->records->findDetailed($id) : null;
        // Чужую запись исполнителю не раскрываем даже фактом её существования
        if ($row === null || (!Permissions::can($actor['role'], 'work.view_all') && (int) $row['user_id'] !== (int) $actor['id'])) {
            throw new ApiException('NOT_FOUND', 'Запись о работе не найдена', 404);
        }
        return WorkRecord::present($row) + ['photos' => $this->photos->forRecordId($id)];
    }

    public function void(array $actor, int $id, array $input, string $ip): array
    {
        $reason = WorkRecordValidator::reason($input);

        return Tx::run(function () use ($actor, $id, $reason, $ip) {
            $row = $id > 0 ? $this->records->findDetailed($id, true) : null;
            if ($row === null) {
                throw new ApiException('NOT_FOUND', 'Запись о работе не найдена', 404);
            }
            if ($row['voided_at'] !== null) {
                throw new ApiException('ALREADY_VOIDED', 'Запись уже аннулирована', 409);
            }
            // Финансовые последствия (ТЗ 6.1): историю выплат не удаляем и не переписываем — фиксируем корректировками
            $run = $this->payroll->activeRunForRecord($id);
            if ($run !== null && $run['status'] === 'draft') {
                throw new ApiException('WORK_IN_DRAFT_PAYROLL', 'Работа включена в черновик расчёта. Отмените черновик расчёта и повторите.', 409);
            }
            $paid = $this->finance->postedAllocatedSumLocked($id);

            $this->records->markVoided($id, (int) $actor['id'], $reason, Dates::nowUtc());
            $this->audit->log((int) $actor['id'], 'work_record.void', 'work_record', $id,
                ['status' => 'active'], ['status' => 'voided'], $reason, $ip);

            $userId = (int) $row['user_id'];
            if (Money::cmp($paid, '0') > 0) {
                // За работу уже выплачено: образуется переплата, она видна в остатке отдельно
                $this->adjustments->insert([
                    'user_id' => $userId, 'type' => 'void_paid_work', 'amount' => bcmul($paid, '-1', 2), 'affects_balance' => 0,
                    'work_record_id' => $id, 'payment_id' => null, 'payroll_run_id' => $run === null ? null : (int) $run['payroll_run_id'],
                    'reason' => $reason, 'created_by' => (int) $actor['id'],
                ]);
            }
            if ($run !== null) {
                // Работа входила в подтверждённый расчёт: снимок расчёта не меняется, отклонение фиксируется корректировкой
                $this->adjustments->insert([
                    'user_id' => $userId, 'type' => 'payroll_correction', 'amount' => bcmul((string) $row['total_amount'], '-1', 2), 'affects_balance' => 0,
                    'work_record_id' => $id, 'payment_id' => null, 'payroll_run_id' => (int) $run['payroll_run_id'],
                    'reason' => $reason, 'created_by' => (int) $actor['id'],
                ]);
            }
            return WorkRecord::present($this->records->findDetailed($id));
        });
    }
}
