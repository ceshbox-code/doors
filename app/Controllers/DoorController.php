<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\DoorService;

final class DoorController extends BaseController
{
    private DoorService $doors;

    public function __construct()
    {
        $this->doors = new DoorService();
    }

    public function store(Request $r): Response   { return Response::ok($this->doors->create($r->user, $r->ip), 201); }
    public function index(Request $r): Response   { return Response::ok($this->doors->list($r->query)); }
    public function show(Request $r): Response    { return Response::ok($this->doors->card(self::id($r, 'Дверь не найдена'))); }
    public function history(Request $r): Response { return Response::ok($this->doors->history(self::id($r, 'Дверь не найдена'))); }

    public function byNumber(Request $r): Response
    {
        return Response::ok($this->doors->byNumber($r->user, (string) ($r->params['number'] ?? '')));
    }
}
