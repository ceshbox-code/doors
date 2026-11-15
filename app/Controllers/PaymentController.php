<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\PaymentService;

final class PaymentController extends BaseController
{
    private PaymentService $payments;

    public function __construct()
    {
        $this->payments = new PaymentService();
    }

    private static function pid(Request $r): int { return self::id($r, 'Выплата не найдена'); }

    public function index(Request $r): Response       { return Response::ok($this->payments->list($r->user, $r->query)); }
    public function show(Request $r): Response        { return Response::ok($this->payments->get($r->user, self::pid($r))); }
    public function allocations(Request $r): Response { return Response::ok($this->payments->allocations($r->user, self::pid($r))); }
    public function post(Request $r): Response        { return Response::ok($this->payments->post($r->user, self::pid($r), $r->ip)); }
    public function cancel(Request $r): Response      { return Response::ok($this->payments->cancel($r->user, self::pid($r), $r->json(), $r->ip)); }

    public function replaceAllocations(Request $r): Response
    {
        return Response::ok($this->payments->replaceAllocations($r->user, self::pid($r), $r->json(), $r->ip));
    }

    public function store(Request $r): Response
    {
        $key = (string) $r->header('Idempotency-Key');
        if ($key === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'Передайте уникальный заголовок Idempotency-Key', 400);
        }
        $res = $this->payments->create($r->user, $r->json(), $key, $r->ip);
        $response = Response::ok($res['data'], $res['status']);
        return $res['replayed'] ? $response->withHeader('Idempotent-Replay', 'true') : $response;
    }
}
