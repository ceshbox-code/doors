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
        $query = $this->scopedQuery($r);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($query['user_id']);
        }
        $data = $this->stats->summary($query);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($data['money']);
        }
        return Response::ok($data);
    }

    public function daily(Request $r): Response
    {
        $query = $this->scopedQuery($r);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($query['user_id']);
        }
        $data = $this->stats->daily($query);
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
        $query = $this->scopedQuery($r);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($query['user_id']);
        }
        $data = $this->stats->workTypes($query);
        if (($r->user['role'] ?? '') === 'owner') {
            unset($data['totals']['amount']);
            foreach ($data['items'] as &$item) {
                unset($item['amount']);
            }
            unset($item);
        }
        return Response::ok($data);
    }

    /** Prevent a master from widening statistics beyond their own records. */
    private function scopedQuery(Request $r): array
    {
        $query = $r->query;
        $role = (string) ($r->user['role'] ?? '');
        if ($role === 'owner') {
            unset($query['user_id']);
        } elseif ($role === 'master') {
            $query['user_id'] = (string) (int) $r->user['id'];
        }
        return $query;
    }

    public function users(Request $r): Response   { return Response::ok($this->stats->users($r->query)); }
    public function doors(Request $r): Response   { return Response::ok($this->stats->doors($r->query)); }
}
