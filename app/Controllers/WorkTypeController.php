<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\WorkTypeService;

final class WorkTypeController extends BaseController
{
    private WorkTypeService $types;

    public function __construct()
    {
        $this->types = new WorkTypeService();
    }

    private static function tid(Request $r): int { return self::id($r, 'Вид работы не найден'); }

    public function index(Request $r): Response      { return Response::ok($this->types->list($r->user, $r->query)); }
    public function store(Request $r): Response      { return Response::ok($this->types->create($r->user, $r->json(), $r->ip), 201); }
    public function show(Request $r): Response       { return Response::ok($this->types->get($r->user, self::tid($r))); }
    public function update(Request $r): Response     { return Response::ok($this->types->update($r->user, self::tid($r), $r->json(), $r->ip)); }
    public function deactivate(Request $r): Response { return Response::ok($this->types->setActive($r->user, self::tid($r), false, $r->ip)); }
    public function activate(Request $r): Response   { return Response::ok($this->types->setActive($r->user, self::tid($r), true, $r->ip)); }
}
