<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\StatsService;

final class StatsController extends BaseController
{
    private StatsService $stats;

    public function __construct()
    {
        $this->stats = new StatsService();
    }

    public function summary(Request $r): Response   { return Response::ok($this->stats->summary($r->query)); }
    public function daily(Request $r): Response     { return Response::ok($this->stats->daily($r->query)); }
    public function workTypes(Request $r): Response { return Response::ok($this->stats->workTypes($r->query)); }
    public function users(Request $r): Response     { return Response::ok($this->stats->users($r->query)); }
    public function doors(Request $r): Response     { return Response::ok($this->stats->doors($r->query)); }
}
