<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\ExportService;
use App\Services\PdfService;
use App\Services\ReportFilters;
use App\Services\ReportHtml;
use App\Services\ReportService;
use Throwable;

/** Экспорт PDF / CSV / XLSX (ТЗ 12.10). Учитывает фильтры запроса и права пользователя; формирует файл на сервере. */
final class ExportController extends BaseController
{
    private ReportService $reports;
    private ExportService $export;
    private AuditService $audit;

    public function __construct()
    {
        $this->reports = new ReportService();
        $this->export = new ExportService();
        $this->audit = new AuditService();
    }

    // ----------------------------------------------------------------- PDF

    public function production(Request $r): Response
    {
        $f = ReportFilters::fromQuery($r->query);
        $d = $this->reports->production($f);
        return $this->pdf($r, 'production', $d, ReportHtml::production($d), 'P', ['filters' => ReportFilters::describe($f)]);
    }

    public function payments(Request $r): Response
    {
        $f = ReportFilters::fromQuery($r->query);
        $status = isset($r->query['status']) && is_string($r->query['status']) ? $r->query['status'] : 'posted';
        if (!in_array($status, ['posted', 'draft', 'cancelled', 'all'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, ['status' => 'posted, draft, cancelled или all']);
        }
        $d = $this->reports->payments($f, $status);
        return $this->pdf($r, 'payments', $d, ReportHtml::payments($d), 'L', ['filters' => ReportFilters::describe($f), 'status' => $status]);
    }

    public function finance(Request $r): Response
    {
        $f = ReportFilters::fromQuery($r->query);
        $d = $this->reports->finance($f);
        return $this->pdf($r, 'finance', $d, ReportHtml::finance($d), 'P', ['filters' => ReportFilters::describe($f)]);
    }

    public function photos(Request $r): Response
    {
        $f = ReportFilters::fromQuery($r->query);
        $d = $this->reports->photos($f);
        return $this->pdf($r, 'photos', $d, ReportHtml::photos($d), 'P', ['filters' => ReportFilters::describe($f), 'records' => count($d['items'])]);
    }

    /** Расчётный лист: исполнитель — только свой подтверждённый; администратор и директор — по правам (проверка в сервисе). */
    public function payslip(Request $r): Response
    {
        $id = self::id($r, 'Расчёт не найден');
        $d = $this->reports->payslip($r->user, $id);
        return $this->pdf($r, 'payslip', $d, ReportHtml::payslip($d), 'P', ['payroll_run_id' => $id], 'payslip_' . $id);
    }

    private function pdf(Request $r, string $report, array $data, string $html, string $orientation, array $audit, ?string $name = null): Response
    {
        @set_time_limit(180);
        try {
            $bytes = PdfService::render($data, $html, $orientation);
        } catch (Throwable $e) {
            Logger::error('Ошибка формирования PDF', ['report' => $report, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            throw new ApiException('REPORT_FAILED', 'Не удалось сформировать PDF. Повторите попытку или уменьшите период.', 500,
                Config::get('app.debug') ? ['reason' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()] : null);
        }
        $this->audit->log((int) $r->user['id'], 'report.export', 'report', null, null, ['report' => $report, 'format' => 'pdf'] + $audit, null, $r->ip);
        return Response::download($bytes, 'application/pdf', ($name ?? ('report_' . $report)) . '_' . gmdate('Ymd-His') . '.pdf');
    }

    // ----------------------------------------------------------------- CSV / XLSX

    private function dataset(Request $r): array
    {
        $name = isset($r->query['dataset']) && is_string($r->query['dataset']) && $r->query['dataset'] !== '' ? $r->query['dataset'] : 'works';
        $f = ReportFilters::fromQuery($r->query);
        return [$name, $f, $this->export->dataset($name, $f, $r->query)];
    }

    public function csv(Request $r): Response
    {
        @set_time_limit(120);
        [$name, $f, $ds] = $this->dataset($r);
        $decimal = isset($r->query['decimal']) && $r->query['decimal'] === 'dot' ? 'dot' : 'comma';
        $bytes = $this->export->csv($ds, $decimal);
        $this->audit->log((int) $r->user['id'], 'report.export', 'report', null, null,
            ['report' => $name, 'format' => 'csv', 'rows' => count($ds->rows), 'filters' => ReportFilters::describe($f)], null, $r->ip);
        return Response::download($bytes, 'text/csv; charset=utf-8', $name . '_' . gmdate('Ymd-His') . '.csv');
    }

    public function xlsx(Request $r): Response
    {
        @set_time_limit(180);
        [$name, $f, $ds] = $this->dataset($r);
        try {
            $bytes = $this->export->xlsx($ds);
        } catch (Throwable $e) {
            Logger::error('Ошибка формирования XLSX', ['dataset' => $name, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            throw new ApiException('REPORT_FAILED', 'Не удалось сформировать файл XLSX. Повторите попытку или уменьшите период.', 500,
                Config::get('app.debug') ? ['reason' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()] : null);
        }
        $this->audit->log((int) $r->user['id'], 'report.export', 'report', null, null,
            ['report' => $name, 'format' => 'xlsx', 'rows' => count($ds->rows), 'filters' => ReportFilters::describe($f)], null, $r->ip);
        return Response::download($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name . '_' . gmdate('Ymd-His') . '.xlsx');
    }
}
