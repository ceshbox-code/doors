<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\RateService;

final class RateController extends BaseController
{
    private RateService $rates;

    public function __construct()
    {
        $this->rates = new RateService();
    }

    public function index(Request $r): Response   { return Response::ok($this->rates->list($r->query)); }
    public function history(Request $r): Response { return Response::ok($this->rates->list($r->query, null, true)); }
    public function store(Request $r): Response   { return Response::ok($this->rates->create($r->user, $r->json(), $r->ip), 201); }
    public function show(Request $r): Response    { return Response::ok($this->rates->get(self::id($r, 'Расценка не найдена'))); }
    public function close(Request $r): Response   { return Response::ok($this->rates->close($r->user, self::id($r, 'Расценка не найдена'), $r->json(), $r->ip)); }
    public function forUser(Request $r): Response { return Response::ok($this->rates->forUser(self::id($r, 'Пользователь не найден'), $r->query)); }
}
