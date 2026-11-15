<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Periods;
use App\Models\Door;
use App\Repositories\DoorRepository;
use App\Validators\Validation;

/**
 * Единый разбор фильтров статистики и отчётов (ТЗ 12.9): period | date_from, date_to, user_id, work_type_id, door_number.
 * Одни и те же фильтры используются в JSON-статистике, PDF, CSV и XLSX — поэтому цифры везде совпадают.
 */
final class ReportFilters
{
    /**
     * @return array{tz:string,period:array,from_utc:?string,to_utc:?string,user_id:?int,work_type_id:?int,
     *               door_id:?int,door_number:?string,door_missing:bool}
     */
    public static function fromQuery(array $q): array
    {
        $tz = (string) Config::get('app.timezone', 'UTC');
        $period = Periods::resolve($q, $tz);
        $errors = [];

        $ids = [];
        foreach (['user_id', 'work_type_id'] as $k) {
            $ids[$k] = null;
            if (isset($q[$k]) && $q[$k] !== '') {
                $ids[$k] = Input::id($q[$k]);
                if ($ids[$k] === null) {
                    $errors[$k] = 'Некорректное значение';
                }
            }
        }

        $doorId = null;
        $doorNumber = null;
        $doorMissing = false;
        if (isset($q['door_number']) && $q['door_number'] !== '') {
            $doorNumber = is_string($q['door_number']) ? Door::normalizeNumber($q['door_number']) : null;
            if ($doorNumber === null) {
                $errors['door_number'] = 'Номер двери вида D-000123';
            } else {
                $door = (new DoorRepository())->findByNumber($doorNumber);
                $doorId = $door === null ? null : (int) $door['id'];
                $doorMissing = $door === null;       // такой двери нет — результат заведомо пуст
            }
        }
        if ($errors) {
            Validation::fail($errors);
        }

        return [
            'tz'           => $tz,
            'period'       => $period,
            'from_utc'     => $period['from'] === null ? null : Dates::localDayToUtc($period['from'], $tz),
            'to_utc'       => $period['to'] === null ? null : Dates::localDayToUtc($period['to'], $tz, true),
            'user_id'      => $ids['user_id'],
            'work_type_id' => $ids['work_type_id'],
            'door_id'      => $doorId,
            'door_number'  => $doorNumber,
            'door_missing' => $doorMissing,
        ];
    }

    /** Публичное описание применённых фильтров (для ответов API и шапок отчётов). */
    public static function describe(array $f): array
    {
        return [
            'period'       => ['from' => $f['period']['from'], 'to' => $f['period']['to'], 'label' => $f['period']['label'], 'preset' => $f['period']['preset']],
            'user_id'      => $f['user_id'],
            'work_type_id' => $f['work_type_id'],
            'door_number'  => $f['door_number'],
        ];
    }
}
