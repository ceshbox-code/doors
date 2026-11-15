<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Permissions;
use App\Core\Tx;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Models\Photo;
use App\Repositories\PhotoRepository;
use App\Repositories\WorkRecordRepository;
use App\Validators\WorkRecordValidator;
use Throwable;

final class PhotoService
{
    private PhotoRepository $photos;
    private WorkRecordRepository $records;
    private PhotoStorage $storage;
    private AuditService $audit;

    public function __construct(?PhotoStorage $storage = null)
    {
        $this->photos  = new PhotoRepository();
        $this->records = new WorkRecordRepository();
        $this->storage = $storage ?? new PhotoStorage();
        $this->audit   = new AuditService();
    }

    /**
     * Сохраняет подготовленные фотографии для записи: файлы на диск + строки в БД.
     * Вызывается внутри транзакции. $placed получает список созданных файлов — его нужно очистить при откате.
     *
     * @return array<int,array> представление фотографий для API
     */
    public function attach(int $recordId, array $staged, array &$placed): array
    {
        $placed = $this->storage->place($staged);
        foreach ($placed as $p) {
            $this->photos->insert($recordId, $p);
        }
        return $this->forRecordId($recordId);
    }

    public function forRecordId(int $recordId): array
    {
        return array_map([Photo::class, 'present'], $this->photos->listByRecord($recordId));
    }

    private function canSeeRecord(array $actor, int $ownerId): bool
    {
        return Permissions::can($actor['role'], 'photos.view')
            || (Permissions::can($actor['role'], 'work.view_own') && $ownerId === (int) $actor['id']);
    }

    /** Фотографии работы (права как на просмотр самой работы; чужую запись исполнителю не раскрываем). */
    public function forRecord(array $actor, int $recordId): array
    {
        $rec = $recordId > 0 ? $this->records->findDetailed($recordId) : null;
        if ($rec === null || !$this->canSeeRecord($actor, (int) $rec['user_id'])) {
            throw new ApiException('NOT_FOUND', 'Запись о работе не найдена', 404);
        }
        return $this->forRecordId($recordId);
    }

    /** Дополнительные фотографии к уже зарегистрированной работе (POST /photos). */
    public function upload(array $actor, array $input, array $uploads, string $ip): array
    {
        $recordId = Input::id($input['work_record_id'] ?? null);
        if ($recordId === null) {
            throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, ['work_record_id' => 'Укажите работу']);
        }
        if (!$uploads) {
            throw new ApiException('PHOTO_REQUIRED', 'Приложите фотографию', 422);
        }
        $rec = $this->records->findDetailed($recordId);
        $own = $rec !== null && (int) $rec['user_id'] === (int) $actor['id'];
        if ($rec === null || (!$own && !Permissions::can($actor['role'], 'work.register_for_others'))) {
            throw new ApiException('NOT_FOUND', 'Запись о работе не найдена', 404);
        }
        if ($rec['voided_at'] !== null) {
            throw new ApiException('WORK_VOIDED', 'Работа аннулирована, добавлять фотографии нельзя', 409);
        }

        $prepared = $this->storage->prepare($uploads);
        if ($this->photos->countByRecord($recordId) + count($prepared) > PhotoStorage::MAX_PER_RECORD) {
            throw new ApiException('TOO_MANY_PHOTOS', 'К одной работе можно приложить не более ' . PhotoStorage::MAX_PER_RECORD . ' фотографий', 422);
        }
        $dups = $this->photos->existingShas($recordId, array_column($prepared, 'sha256'));
        if ($dups) {
            throw new ApiException('DUPLICATE_PHOTO', 'Такая фотография уже приложена к этой работе', 409);
        }

        $staged = $this->storage->stage($prepared);
        $placed = [];
        try {
            return Tx::run(function () use ($actor, $recordId, $staged, &$placed, $ip) {
                $this->storage->discard($placed);          // следы прошлой попытки при повторе после взаимной блокировки
                $placed = [];
                $all = $this->attach($recordId, $staged, $placed);
                $this->audit->log((int) $actor['id'], 'photo.upload', 'work_record', $recordId, null, [
                    'count' => count($staged), 'sha256' => array_column($staged, 'sha256'),
                ], null, $ip);
                return $all;
            });
        } catch (Throwable $e) {
            $this->storage->discard($placed);
            throw $e;
        } finally {
            $this->storage->discardStaged($staged);
        }
    }

    /**
     * Файл для выдачи. variant: optimized (по умолчанию) или original.
     *
     * @return array{path:string,mime:string,etag:string}
     */
    public function file(array $actor, int $id, string $variant): array
    {
        $p = $id > 0 ? $this->photos->findWithOwner($id) : null;
        if ($p === null || !$this->canSeeRecord($actor, (int) $p['owner_id'])) {
            throw new ApiException('NOT_FOUND', 'Фотография не найдена', 404);
        }
        if (!in_array($variant, ['optimized', 'original'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, ['variant' => 'optimized или original']);
        }
        $useOriginal = $variant === 'original' || empty($p['optimized_file_name']);
        $path = $this->storage->resolve($useOriginal ? $p['file_name'] : $p['optimized_file_name']);
        if ($path === null) {
            throw new ApiException('PHOTO_FILE_MISSING', 'Файл фотографии недоступен', 500);
        }
        return [
            'path' => $path,
            'mime' => $useOriginal ? $p['mime_type'] : 'image/jpeg',
            'etag' => substr($p['sha256'], 0, 40) . '-' . ($useOriginal ? 'o' : 'w'),
        ];
    }

    /** Удаление по специальному праву. Только мягкое, только для фото аннулированной работы; файлы остаются на диске. */
    public function delete(array $actor, int $id, array $input, string $ip): array
    {
        $reason = WorkRecordValidator::reason($input);
        return Tx::run(function () use ($actor, $id, $reason, $ip) {
            $p = $id > 0 ? $this->photos->findWithOwner($id) : null;
            if ($p === null) {
                throw new ApiException('NOT_FOUND', 'Фотография не найдена', 404);
            }
            if ($p['record_voided_at'] === null) {
                throw new ApiException('PHOTO_LINKED', 'Фотография связана с действительной работой — удалять её нельзя', 409);
            }
            $this->photos->softDelete($id, (int) $actor['id'], $reason, Dates::nowUtc());
            $this->audit->log((int) $actor['id'], 'photo.delete', 'photo', $id,
                ['work_record_id' => (int) $p['work_record_id'], 'sha256' => $p['sha256'], 'file_name' => $p['file_name']],
                null, $reason, $ip);
            return ['id' => $id, 'deleted' => true];
        });
    }
}
