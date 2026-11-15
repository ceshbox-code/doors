<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\WorkRecordService;

final class WorkRecordController extends BaseController
{
    private WorkRecordService $records;

    public function __construct()
    {
        $this->records = new WorkRecordService();
    }

    public function store(Request $r): Response
    {
        $key = (string) $r->header('Idempotency-Key');
        if ($key === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'Передайте уникальный заголовок Idempotency-Key', 400);
        }
        if ($r->bodyTooLarge) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Размер запроса превышает допустимый (64 МБ)', 413);
        }
        $res = $this->records->register($r->user, $r->input(), $r->files, $key, $r->ip);
        $response = Response::ok($res['data'], $res['status']);
        return $res['replayed'] ? $response->withHeader('Idempotent-Replay', 'true') : $response;
    }

    public function index(Request $r): Response
    {
        return Response::ok($this->records->list($r->user, $r->query));
    }

    public function show(Request $r): Response
    {
        return Response::ok($this->records->get($r->user, self::id($r, 'Запись о работе не найдена')));
    }

    public function void(Request $r): Response
    {
        return Response::ok($this->records->void($r->user, self::id($r, 'Запись о работе не найдена'), $r->json(), $r->ip));
    }
}
