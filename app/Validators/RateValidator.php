<?php
declare(strict_types=1);

namespace App\Validators;

use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Money;

final class RateValidator
{
    /** @return array{user_id:int,work_type_id:int,unit_price:string,valid_from:?string} valid_from в UTC или null (= сейчас) */
    public static function create(array $in): array
    {
        $e = [];
        $userId = Input::id($in['user_id'] ?? null);
        $typeId = Input::id($in['work_type_id'] ?? null);
        $price  = Money::price($in['unit_price'] ?? null);
        $from   = null;

        if ($userId === null) {
            $e['user_id'] = 'Укажите исполнителя';
        }
        if ($typeId === null) {
            $e['work_type_id'] = 'Укажите вид работы';
        }
        if ($price === null) {
            $e['unit_price'] = 'Цена: неотрицательное число, не более 2 знаков после запятой, до 9 999 999.99';
        }
        if (isset($in['valid_from'])) {
            $from = is_string($in['valid_from']) ? Dates::parseToUtc($in['valid_from']) : null;
            if ($from === null) {
                $e['valid_from'] = 'Дата начала: ISO 8601 с часовым поясом, например 2026-12-01T00:00:00+02:00';
            }
        }
        if ($e) {
            Validation::fail($e);
        }
        return ['user_id' => $userId, 'work_type_id' => $typeId, 'unit_price' => $price, 'valid_from' => $from];
    }

    /** @return ?string valid_to в UTC или null (= сейчас) */
    public static function close(array $in): ?string
    {
        if (!isset($in['valid_to'])) {
            return null;
        }
        $to = is_string($in['valid_to']) ? Dates::parseToUtc($in['valid_to']) : null;
        if ($to === null) {
            Validation::fail(['valid_to' => 'Дата окончания: ISO 8601 с часовым поясом']);
        }
        return $to;
    }
}
