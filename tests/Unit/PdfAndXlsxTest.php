<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Dataset;
use App\Services\ExportService;
use App\Services\PdfService;
use App\Services\ReportHtml;
use PHPUnit\Framework\TestCase;

/** Проверка реальной генерации файлов. Требует установленных mPDF и PhpSpreadsheet (зависимости образа). */
final class PdfAndXlsxTest extends TestCase
{
    private function base(): array
    {
        return ['org' => 'Тестовая организация', 'title' => 'Проверка кириллицы', 'period' => 'За всё время', 'filters' => [], 'generated' => '14.11.2026 10:00', 'tz' => 'UTC', 'currency' => '₽'];
    }

    public function testPdfIsValidEmbedsDejaVuAndPaginatesLongTables(): void
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mPDF не установлен');
        }
        $rows = '';
        for ($i = 1; $i <= 220; $i++) {
            $rows .= '<tr><td>' . $i . '</td><td>Ремонт двери № ' . $i . ' — съёмка, шпатлёвка, покраска</td><td class="num">1 234,50</td></tr>';
        }
        $html = '<table class="data"><thead><tr><th>№</th><th>Описание</th><th class="num">Сумма</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $pdf = PdfService::render($this->base(), $html);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -64));
        $this->assertStringContainsString('DejaVuSans', $pdf, 'шрифт с кириллицей должен быть встроен');
        $pages = preg_match_all('#/Type\s*/Page[^s]#', $pdf);
        $this->assertGreaterThanOrEqual(3, $pages, 'длинная таблица должна переноситься на следующие страницы');
    }

    public function testPdfLandscapeAndEmptyBodyDoNotFail(): void
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            $this->markTestSkipped('mPDF не установлен');
        }
        $pdf = PdfService::render($this->base(), ReportHtml::photos($this->base() + ['items' => [], 'total' => 0]), 'L');
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testXlsxHasRealNumbersDatesTotalsAndIsFormulaSafe(): void
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $this->markTestSkipped('PhpSpreadsheet не установлен');
        }
        $ds = new Dataset('Выполненные работы: тест', [
            ['key' => 'name', 'title' => 'Исполнитель', 'type' => 'text'],
            ['key' => 'amount', 'title' => 'Сумма', 'type' => 'money'],
            ['key' => 'at', 'title' => 'Дата', 'type' => 'datetime'],
            ['key' => 'n', 'title' => 'Работ', 'type' => 'int'],
        ], [
            ['name' => '=1+1', 'amount' => '250.50', 'at' => '2026-11-13 22:30:00', 'n' => 2],
            ['name' => 'Пётр', 'amount' => '100.00', 'at' => null, 'n' => 1],
        ], ['amount' => '350.50', 'n' => 3], ['Период: тест', 'Фильтр: исполнитель']);

        $bytes = (new ExportService())->xlsx($ds);
        $this->assertStringStartsWith('PK', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'ut_xlsx_');
        file_put_contents($tmp, $bytes);
        try {
            $wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
        $ws = $wb->getSheet(0);
        $this->assertSame('Выполненные работы тест', $ws->getTitle());
        $this->assertSame('Исполнитель', $ws->getCell('A1')->getValue());
        $this->assertSame('=1+1', $ws->getCell('A2')->getValue());
        $this->assertSame('s', $ws->getCell('A2')->getDataType(), 'значение «=1+1» должно остаться текстом, а не формулой');
        $this->assertEquals(250.5, $ws->getCell('B2')->getValue());
        $this->assertSame('#,##0.00', $ws->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertEquals(2, $ws->getCell('D2')->getValue());
        $this->assertSame('ИТОГО', $ws->getCell('A4')->getValue());
        $this->assertEquals(350.5, $ws->getCell('B4')->getValue());
        $this->assertSame(4, $ws->getHighestRow());
        $this->assertSame('Период: тест', $wb->getSheet(1)->getCell('A1')->getValue());
    }

    public function testSheetTitleIsExcelSafe(): void
    {
        $t = ExportService::sheetTitle('Работы: [за] месяц / итого? *');
        $this->assertDoesNotMatchRegularExpression('#[\\\\/?*\[\]:]#u', $t);
        $this->assertLessThanOrEqual(31, mb_strlen($t));
        $this->assertSame(31, mb_strlen(ExportService::sheetTitle(str_repeat('я', 80))));
        $this->assertSame('Данные', ExportService::sheetTitle(':::'));
    }
}
