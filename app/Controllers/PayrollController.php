<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PayrollService;

final class PayrollController extends BaseController
{
    private PayrollService $payroll;

    public function __construct()
    {
        $this->payroll = new PayrollService();
    }

    private static function rid(Request $r): int { return self::id($r, 'Расчёт не найден'); }

    public function index(Request $r): Response   { return Response::ok($this->payroll->list($r->user, $r->query)); }
    public function store(Request $r): Response   { return Response::ok($this->payroll->create($r->user, $r->json(), $r->ip), 201); }
    public function show(Request $r): Response    { return Response::ok($this->payroll->detail(self::rid($r), $r->user)); }
    public function confirm(Request $r): Response { return Response::ok($this->payroll->confirm($r->user, self::rid($r), $r->ip)); }
    public function cancel(Request $r): Response  { return Response::ok($this->payroll->cancel($r->user, self::rid($r), $r->json(), $r->ip)); }
}
