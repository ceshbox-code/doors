<?php
declare(strict_types=1);

namespace App\Validators;

use App\Helpers\Input;
use App\Helpers\Money;
use App\Models\Door;

final class WorkRecordValidator
{
    /**
     * @return array{work_type_id:int,door_number:?string,new_door:bool,quantity:string,comment:?string,user_id:?int}
     */
    public static function register(array $in): array
    {
        $e = [];
        $typeId = Input::id($in['work_type_id'] ?? null);
        if ($typeId === null) {
            $e['work_type_id'] = 'Выберите вид работы';
        }

        $doorNumber = null;
        if (isset($in['door_number']) && $in['door_number'] !== '') {
            $doorNumber = is_string($in['door_number']) ? Door::normalizeNumber($in['door_number']) : null;
            if ($doorNumber === null) {
                $e['door_number'] = 'Номер двери вида D-000123';
            }
        }

        $newDoor = false;
        if (array_key_exists('new_door', $in)) {
            $b = Input::bool($in['new_door']);
            if ($b === null) {
                $e['new_door'] = 'Допустимо true или false';
            } else {
                $newDoor = $b;
            }
        }
        if ($doorNumber !== null && $newDoor) {
            $e['door_number'] = 'Укажите либо номер существующей двери, либо new_door=true, но не оба';
        }

        $quantity = array_key_exists('quantity', $in) ? Money::quantity($in['quantity']) : '1.000';
        if ($quantity === null || !Money::isPositive($quantity)) {
            $e['quantity'] = 'Количество: положительное число, до 3 знаков после запятой, до 99999.999';
            $quantity = '1.000';
        }

        $comment = null;
        if (isset($in['comment'])) {
            $c = Input::str($in['comment']);
            if ($c === null || mb_strlen($c) > 2000) {
                $e['comment'] = 'Комментарий: до 2000 символов';
            } else {
                $comment = $c === '' ? null : $c;
            }
        }

        $userId = null;
        if (isset($in['user_id'])) {
            $userId = Input::id($in['user_id']);
            if ($userId === null) {
                $e['user_id'] = 'Некорректный исполнитель';
            }
        }

        if ($e) {
            Validation::fail($e);
        }
        return [
            'work_type_id' => $typeId, 'door_number' => $doorNumber, 'new_door' => $newDoor,
            'quantity' => $quantity, 'comment' => $comment, 'user_id' => $userId,
        ];
    }

    public static function reason(array $in): string
    {
        $r = Input::str($in['reason'] ?? null);
        if ($r === null || mb_strlen($r) < 3 || mb_strlen($r) > 1000) {
            Validation::fail(['reason' => 'Причина: от 3 до 1000 символов']);
        }
        return $r;
    }
}
