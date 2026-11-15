<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Табличный набор данных для CSV и XLSX. Значения хранятся «сырыми»: деньги — строки 'N.NN',
 * даты-время — UTC 'Y-m-d H:i:s'; форматирование выполняют писатели файлов.
 * Типы колонок: text, int, money, qty, datetime, date (UTC-момент), localdate ('YYYY-MM-DD' — день в часовом поясе предприятия).
 */
final class Dataset
{
    public string $title;
    /** @var array<int,array{key:string,title:string,type:string}> */
    public array $columns;
    /** @var array<int,array<string,mixed>> */
    public array $rows;
    /** @var array<string,mixed>|null значения итоговой строки по ключам колонок */
    public ?array $totals;
    /** @var string[] строки с условиями выборки (период, фильтры) */
    public array $meta;

    public function __construct(string $title, array $columns, array $rows, ?array $totals = null, array $meta = [])
    {
        $this->title = $title;
        $this->columns = $columns;
        $this->rows = $rows;
        $this->totals = $totals;
        $this->meta = $meta;
    }
}
