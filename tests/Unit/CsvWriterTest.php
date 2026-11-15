<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CsvWriter;
use App\Services\Dataset;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    private function dataset(): Dataset
    {
        $cols = [
            ['key' => 'name', 'title' => 'Исполнитель', 'type' => 'text'],
            ['key' => 'qty', 'title' => 'Кол-во', 'type' => 'qty'],
            ['key' => 'amount', 'title' => 'Сумма', 'type' => 'money'],
            ['key' => 'at', 'title' => 'Дата', 'type' => 'datetime'],
            ['key' => 'n', 'title' => 'Работ', 'type' => 'int'],
        ];
        $rows = [
            ['name' => 'Иванов Иван', 'qty' => '2.500', 'amount' => '250.50', 'at' => '2026-11-13 22:30:00', 'n' => 3],
            ['name' => '=1+1', 'qty' => '1.000', 'amount' => '-100.00', 'at' => null, 'n' => 0],
            ['name' => 'Пётр; "Первый"', 'qty' => '1.000', 'amount' => '0.00', 'at' => null, 'n' => 1],
        ];
        return new Dataset('Тест', $cols, $rows, ['qty' => '4.500', 'amount' => '150.50', 'n' => 4]);
    }

    private function lines(string $csv): array
    {
        $csv = substr($csv, 3);                                        // без BOM
        return array_map(static fn($l) => str_getcsv($l, ';', '"', ''), preg_split('/\r\n|\n|\r/', trim($csv)));
    }

    public function testBomHeaderAndSemicolonDelimiter(): void
    {
        $csv = CsvWriter::build($this->dataset(), 'comma', 'Europe/Kaliningrad');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = $this->lines($csv);
        $this->assertSame(['Исполнитель', 'Кол-во', 'Сумма', 'Дата', 'Работ'], $rows[0]);
        $this->assertCount(5, $rows);                                   // заголовок + 3 строки + итоги
    }

    public function testDecimalCommaByDefaultAndDotOnRequest(): void
    {
        $rows = $this->lines(CsvWriter::build($this->dataset(), 'comma', 'UTC'));
        $this->assertSame(['Иванов Иван', '2,5', '250,50', '13.11.2026 22:30', '3'], $rows[1]);
        $this->assertSame('-100,00', $rows[2][2]);
        $rows = $this->lines(CsvWriter::build($this->dataset(), 'dot', 'UTC'));
        $this->assertSame(['Иванов Иван', '2.5', '250.50', '13.11.2026 22:30', '3'], $rows[1]);
    }

    public function testDatesAreConvertedToBusinessTimezone(): void
    {
        $rows = $this->lines(CsvWriter::build($this->dataset(), 'comma', 'Europe/Kaliningrad'));
        $this->assertSame('14.11.2026 00:30', $rows[1][3]);
    }

    public function testFormulaInjectionIsNeutralizedOnlyInTextColumns(): void
    {
        $rows = $this->lines(CsvWriter::build($this->dataset(), 'comma', 'UTC'));
        $this->assertSame("'=1+1", $rows[2][0]);
        $this->assertSame('-100,00', $rows[2][2], 'отрицательные суммы не должны получать апостроф');
        foreach (['=SUM(A1)', '+1', '-1+1', '@cmd', "\t1"] as $bad) {
            $this->assertStringStartsWith("'", CsvWriter::text($bad), $bad);
        }
        $this->assertSame('Иванов', CsvWriter::text('Иванов'));
    }

    public function testSeparatorAndQuotesInTextAreEscaped(): void
    {
        $rows = $this->lines(CsvWriter::build($this->dataset(), 'comma', 'UTC'));
        $this->assertSame('Пётр; "Первый"', $rows[3][0]);
    }

    public function testTotalsRowWithLabelAndEmptyCells(): void
    {
        $rows = $this->lines(CsvWriter::build($this->dataset(), 'comma', 'UTC'));
        $this->assertSame(['ИТОГО', '4,5', '150,50', '', '4'], $rows[4]);
    }

    public function testEmptyDatasetHasOnlyHeader(): void
    {
        $d = new Dataset('Пусто', [['key' => 'a', 'title' => 'A', 'type' => 'text']], [], null);
        $this->assertCount(1, $this->lines(CsvWriter::build($d)));
    }

    public function testNoTotalsRowForEmptySelection(): void
    {
        $d = new Dataset('Пусто', [['key' => 'a', 'title' => 'A', 'type' => 'money']], [], ['a' => '0.00']);
        $this->assertCount(1, $this->lines(CsvWriter::build($d)));
    }
}
