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

    public function summary(Request $r): Response
    {
        $data = $this->stats->summary($r->query);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($data['money']);
        }
        return Response::ok($data);
    }

    public function daily(Request $r): Response
    {
        $data = $this->stats->daily($r->query);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($data['totals']['amount']);
            foreach ($data['items'] as &$item) {
                unset($item['amount']);
            }
            unset($item);
        }
        return Response::ok($data);
    }

    public function workTypes(Request $r): Response
    {
        $data = $this->stats->workTypes($r->query);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($data['totals']['amount']);
            foreach ($data['items'] as &$item) {
                unset($item['amount']);
            }
            unset($item);
        }
        return Response::ok($data);
    }

    public function users(Request $r): Response   { return Response::ok($this->stats->users($r->query)); }
    public function doors(Request $r): Response   { return Response::ok($this->stats->doors($r->query)); }
}
