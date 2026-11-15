<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AdjustmentService;
use App\Services\FinanceService;

final class FinanceController extends BaseController
{
    private FinanceService $finance;
    private AdjustmentService $adjustments;

    public function __construct()
    {
        $this->finance = new FinanceService();
        $this->adjustments = new AdjustmentService();
    }

    public function summary(Request $r): Response  { return Response::ok($this->finance->summary($r->query)); }
    public function balances(Request $r): Response { return Response::ok($this->finance->balances($r->query)); }
    public function accruals(Request $r): Response { return Response::ok($this->finance->accruals($r->user, $r->query)); }

    public function user(Request $r): Response
    {
        return Response::ok($this->finance->userFinance($r->user, self::id($r, 'Пользователь не найден'), $r->query));
    }

    public function adjustments(Request $r): Response     { return Response::ok($this->finance->adjustmentList($r->user, $r->query)); }
    public function createAdjustment(Request $r): Response { return Response::ok($this->adjustments->create($r->user, $r->json(), $r->ip), 201); }
}
