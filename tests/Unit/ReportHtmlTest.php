<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReportHtml;
use PHPUnit\Framework\TestCase;

final class ReportHtmlTest extends TestCase
{
    private function base(string $title = 'Производственный отчёт'): array
    {
        return ['org' => 'ООО «Дверь» <script>', 'title' => $title, 'period' => 'с 01.11.2026 по 14.11.2026',
                'filters' => ['Исполнитель: <b>Иванов</b>'], 'generated' => '14.11.2026 10:00', 'tz' => 'Europe/Kaliningrad', 'currency' => '₽'];
    }

    private function production(): array
    {
        return $this->base() + [
            'counts' => ['doors' => 5, 'repairs' => 3, 'packings' => 2, 'other_works' => 1, 'operations' => 6],
            'money'  => ['accrued' => '1234567.50', 'paid' => '1000.00', 'debt' => '1233567.50', 'overpayment' => '0.00', 'adjustments' => '0.00'],
            'by_work_type' => [
                ['work_type' => ['id' => 1, 'name' => 'Ремонт <двери>', 'unit' => 'шт', 'category' => 'repair'], 'works_count' => 3, 'doors_count' => 3, 'quantity' => '3.000', 'amount' => '1500.00'],
            ],
            'by_executor' => [
                ['user' => ['id' => 2, 'full_name' => 'Иванов <Иван>', 'role' => 'master', 'is_active' => true], 'works_count' => 3, 'doors_count' => 3, 'accrued' => '1500.00',
                 'paid' => '500.00', 'balance_at_end' => '1000.00', 'debt' => '1000.00', 'overpayment' => '0.00',
                 'by_work_type' => [['work_type_id' => 1, 'name' => 'Ремонт <двери>', 'unit' => 'шт', 'works_count' => 3, 'quantity' => '3.000', 'amount' => '1500.00']]],
            ],
            'totals' => ['works_count' => 3, 'amount' => '1500.00'],
        ];
    }

    public function testEscapingNeverLetsMarkupThrough(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', ReportHtml::e('<script>alert("x")</script>'));
        $html = ReportHtml::layout($this->base(), ReportHtml::production($this->production()));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Иванов</b>', $html);
        $this->assertStringContainsString('ООО «Дверь» &lt;script&gt;', $html);
        $this->assertStringContainsString('Ремонт &lt;двери&gt;', $html);
        $this->assertStringContainsString('Иванов &lt;Иван&gt;', $html);
    }

    public function testLayoutHasTitlePeriodAndGenerationDate(): void
    {
        $html = ReportHtml::layout($this->base(), '');
        $this->assertStringContainsString('Производственный отчёт', $html);
        $this->assertStringContainsString('с 01.11.2026 по 14.11.2026', $html);
        $this->assertStringContainsString('Дата формирования: 14.11.2026 10:00', $html);
    }

    public function testProductionShowsFormattedSumsAndKpi(): void
    {
        $html = ReportHtml::production($this->production());
        $nb = "\u{00A0}";
        $this->assertStringContainsString("1{$nb}234{$nb}567,50 ₽", $html);       // начислено
        $this->assertStringContainsString('Уникальных дверей', $html);
        $this->assertStringContainsString('Выплачено за период', $html);
        $this->assertStringContainsString('<thead>', $html);                       // повтор шапки таблицы на страницах
        $this->assertStringContainsString("1{$nb}500,00", $html);
        $this->assertStringContainsString('Итого', $html);
    }

    public function testEmptyDataShowsMessageInsteadOfBrokenTable(): void
    {
        $d = $this->production();
        $d['by_work_type'] = [];
        $d['by_executor'] = [];
        $html = ReportHtml::production($d);
        $this->assertSame(2, substr_count($html, 'Нет данных за выбранный период'));
    }

    public function testPayslipMarksVoidedItemsDraftsAndRemaining(): void
    {
        $d = $this->base('Расчётный лист') + [
            'executor' => 'Иванов Иван',
            'run' => ['id' => 7, 'status' => 'draft', 'period_from' => '2026-11-01', 'period_to' => '2026-11-14', 'confirmed_at' => null, 'items' => [
                ['completed_at' => '2026-11-10T08:00:00Z', 'door_number' => 'D-000001', 'work_type_name' => 'Ремонт двери', 'quantity' => '1.000', 'unit' => 'шт', 'unit_price' => '500.00', 'total_amount' => '500.00', 'voided' => false],
                ['completed_at' => '2026-11-11T08:00:00Z', 'door_number' => null, 'work_type_name' => 'Упаковка', 'quantity' => '2.000', 'unit' => 'шт', 'unit_price' => '100.00', 'total_amount' => '200.00', 'voided' => true],
            ]],
            'adjustments' => [['created_at' => '2026-11-12T08:00:00Z', 'type' => 'manual', 'reason' => 'Премия', 'amount' => '50.00', 'affects_balance' => true]],
            'summary' => ['accrued' => '500.00', 'voided' => '200.00', 'adjustments' => '50.00', 'total_accrued' => '550.00', 'paid' => '300.00',
                          'remaining' => '250.00', 'overpayment' => '0.00', 'overall_balance' => '250.00'],
        ];
        $html = ReportHtml::payslip($d);
        $this->assertStringContainsString('ЧЕРНОВИК', $html);
        $this->assertStringContainsString('аннулирована', $html);
        $this->assertStringContainsString('class="struck"', $html);
        $this->assertStringContainsString('Остаток к выплате', $html);
        $this->assertStringContainsString('250,00 ₽', $html);
        $this->assertStringContainsString('Премия', $html);
        $d['summary']['overpayment'] = '30.00';
        $this->assertStringContainsString('Переплата', ReportHtml::payslip($d));
    }

    public function testPaymentsShowAllocationsAndStatusColumnOnlyWhenNeeded(): void
    {
        $d = $this->base('Отчёт по выплатам') + [
            'status' => 'posted', 'total_posted' => '450.00', 'by_method' => ['наличные' => '450.00'],
            'items' => [['id' => 1, 'payment_date' => '2026-11-14 08:00:00', 'executor' => 'Иванов', 'amount' => '450.00', 'method' => 'наличные', 'reference' => 'РКО-1', 'status' => 'posted',
                         'comment' => null, 'allocations' => [['work_record_id' => 5, 'door_number' => 'D-000002', 'work_type_name' => 'Ремонт', 'amount' => '450.00']]]],
        ];
        $html = ReportHtml::payments($d);
        $this->assertStringContainsString('D-000002', $html);
        $this->assertStringContainsString('РКО-1', $html);
        $this->assertStringNotContainsString('>Статус<', $html);
        $d['status'] = 'all';
        $this->assertStringContainsString('>Статус<', ReportHtml::payments($d));
    }

    public function testPhotosReportEmbedsImagesAndEscapesComment(): void
    {
        $d = $this->base('Фотоотчёт') + ['total' => 1, 'items' => [[
            'id' => 9, 'door_number' => 'D-000009', 'work_type' => 'Ремонт', 'executor' => 'Иванов', 'completed_at' => '2026-11-14 08:00:00',
            'comment' => '<i>скол</i>', 'photos' => ['data:image/jpeg;base64,AAAA'], 'photos_total' => 3,
        ]]];
        $html = ReportHtml::photos($d);
        $this->assertStringContainsString('src="data:image/jpeg;base64,AAAA"', $html);
        $this->assertStringContainsString('&lt;i&gt;скол&lt;/i&gt;', $html);
        $this->assertStringContainsString('Показано 1 из 3', $html);
        $this->assertStringContainsString('Нет работ', ReportHtml::photos($this->base() + ['items' => [], 'total' => 0]));
    }

    public function testAdjustmentAndStatusLabelsFallBackToRawValue(): void
    {
        $this->assertSame('Отмена выплаты', ReportHtml::adjType('cancel_payment'));
        $this->assertSame('неизвестный', ReportHtml::adjType('неизвестный'));
        $this->assertSame('Подтверждена', ReportHtml::payStatus('posted'));
    }
}
