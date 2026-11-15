<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PhotoService;

final class PhotoController extends BaseController
{
    private PhotoService $photos;

    public function __construct()
    {
        $this->photos = new PhotoService();
    }

    public function store(Request $r): Response
    {
        if ($r->bodyTooLarge) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Размер запроса превышает допустимый (64 МБ)', 413);
        }
        return Response::ok($this->photos->upload($r->user, $r->input(), $r->files, $r->ip), 201);
    }

    /** Выдача файла только после проверки прав; адрес файла на диске наружу не раскрывается. */
    public function show(Request $r): Response
    {
        $variant = isset($r->query['variant']) && is_string($r->query['variant']) ? $r->query['variant'] : 'optimized';
        $f = $this->photos->file($r->user, self::id($r, 'Фотография не найдена'), $variant);
        $inm = trim((string) $r->header('If-None-Match'), " \"");
        if ($inm !== '' && $inm === $f['etag']) {
            return Response::notModified($f['etag']);
        }
        return Response::file($f['path'], $f['mime'], $f['etag']);
    }

    public function forRecord(Request $r): Response
    {
        return Response::ok($this->photos->forRecord($r->user, self::id($r, 'Запись о работе не найдена')));
    }

    public function destroy(Request $r): Response
    {
        return Response::ok($this->photos->delete($r->user, self::id($r, 'Фотография не найдена'), $r->json(), $r->ip));
    }
}
