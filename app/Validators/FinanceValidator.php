<?php
declare(strict_types=1);

namespace App\Validators;

use App\Helpers\Dates;
use App\Helpers\Input;
use App\Helpers\Money;

final class FinanceValidator
{
    public const ADJUSTMENT_TYPES = ['manual', 'overpayment', 'payroll_correction'];
    public const MAX_PERIOD_DAYS = 3660;

    /** @return array{from:?string,to:?string} границы периода (локальные даты) из параметров запроса */
    public static function period(array $q, string $fromKey = 'date_from', string $toKey = 'date_to'): array
    {
        $e = [];
        $from = isset($q[$fromKey]) && $q[$fromKey] !== '' ? (is_string($q[$fromKey]) ? $q[$fromKey] : '') : null;
        $to   = isset($q[$toKey]) && $q[$toKey] !== '' ? (is_string($q[$toKey]) ? $q[$toKey] : '') : null;
        if ($from !== null && !Dates::validDate($from)) {
            $e[$fromKey] = 'Формат YYYY-MM-DD';
        }
        if ($to !== null && !Dates::validDate($to)) {
            $e[$toKey] = 'Формат YYYY-MM-DD';
        }
        if (!$e && $from !== null && $to !== null) {
            $days = Dates::daysBetween($from, $to);
            if ($days < 0) {
                $e[$toKey] = 'Конец периода раньше начала';
            } elseif ($days > self::MAX_PERIOD_DAYS) {
                $e[$toKey] = 'Период слишком длинный';
            }
        }
        if ($e) {
            Validation::fail($e);
        }
        return ['from' => $from, 'to' => $to];
    }

    /** @return array{user_id:int,period_from:string,period_to:string} */
    public static function payrollRun(array $in): array
    {
        $e = [];
        $userId = Input::id($in['user_id'] ?? null);
        if ($userId === null) {
            $e['user_id'] = 'Укажите исполнителя';
        }
        foreach (['period_from', 'period_to'] as $k) {
            if (!isset($in[$k]) || !is_string($in[$k]) || !Dates::validDate($in[$k])) {
                $e[$k] = 'Формат YYYY-MM-DD';
            }
        }
        if (!$e) {
            $days = Dates::daysBetween($in['period_from'], $in['period_to']);
            if ($days < 0) {
                $e['period_to'] = 'Конец периода раньше начала';
            } elseif ($days > self::MAX_PERIOD_DAYS) {
                $e['period_to'] = 'Период слишком длинный';
            }
        }
        if ($e) {
            Validation::fail($e);
        }
        return ['user_id' => $userId, 'period_from' => $in['period_from'], 'period_to' => $in['period_to']];
    }

    /** @return array<int,array{work_record_id:int,amount:string}> */
    public static function allocations($raw, string $field = 'allocations'): array
    {
        $e = [];
        $out = [];
        $seen = [];
        if (!is_array($raw) || $raw === [] || count($raw) > 500) {
            Validation::fail([$field => 'Список распределения: от 1 до 500 строк']);
        }
        foreach ($raw as $i => $row) {
            $id  = is_array($row) ? Input::id($row['work_record_id'] ?? null) : null;
            $amt = is_array($row) ? Money::amount($row['amount'] ?? null) : null;
            if ($id === null || $amt === null) {
                $e["{$field}[{$i}]"] = 'Нужны work_record_id и положительная сумма amount (до 2 знаков)';
                continue;
            }
            if (isset($seen[$id])) {
                $e["{$field}[{$i}]"] = "Начисление №{$id} указано дважды";
                continue;
            }
            $seen[$id] = true;
            $out[] = ['work_record_id' => $id, 'amount' => $amt];
        }
        if ($e) {
            Validation::fail($e);
        }
        return $out;
    }

    /**
     * @return array{user_id:int,amount:string,payment_method:string,payment_date:?string,reference:?string,
     *               comment:?string,payroll_run_id:?int,allocations:?array,post:bool}
     */
    public static function payment(array $in): array
    {
        $e = [];
        $userId = Input::id($in['user_id'] ?? null);
        if ($userId === null) {
            $e['user_id'] = 'Укажите получателя';
        }
        $amount = Money::amount($in['amount'] ?? null);
        if ($amount === null) {
            $e['amount'] = 'Сумма: положительное число, до 2 знаков после запятой';
        }
        $method = Input::str($in['payment_method'] ?? null);
        if ($method === null || mb_strlen($method) < 1 || mb_strlen($method) > 40) {
            $e['payment_method'] = 'Способ выплаты: от 1 до 40 символов (наличные, карта, перевод…)';
        }
        // Не подставляем «сейчас» здесь: значение по умолчанию не должно попадать в хэш идемпотентности,
        // иначе повтор того же запроса выглядел бы как другой. Дату по умолчанию ставит сервис после проверки ключа.
        $date = null;
        if (isset($in['payment_date'])) {
            $parsed = is_string($in['payment_date']) ? Dates::parseToUtc($in['payment_date']) : null;
            if ($parsed === null) {
                $e['payment_date'] = 'ISO 8601 с часовым поясом, например 2026-11-14T10:00:00+02:00';
            } elseif ($parsed > gmdate('Y-m-d H:i:s', time() + 86400)) {
                $e['payment_date'] = 'Дата выплаты не может быть в будущем';
            } else {
                $date = $parsed;
            }
        }
        $reference = null;
        if (isset($in['reference'])) {
            $r = Input::str($in['reference']);
            if ($r === null || mb_strlen($r) > 190) {
                $e['reference'] = 'Номер операции: до 190 символов';
            } else {
                $reference = $r === '' ? null : $r;
            }
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
        $runId = null;
        if (isset($in['payroll_run_id'])) {
            $runId = Input::id($in['payroll_run_id']);
            if ($runId === null) {
                $e['payroll_run_id'] = 'Некорректный расчёт';
            }
        }
        $post = false;
        if (array_key_exists('post', $in)) {
            $b = Input::bool($in['post']);
            if ($b === null) {
                $e['post'] = 'true или false';
            } else {
                $post = $b;
            }
        }
        $allocations = null;
        if (isset($in['allocations'])) {
            try {
                $allocations = self::allocations($in['allocations']);
            } catch (\App\Core\ApiException $ex) {
                $e += $ex->details ?? ['allocations' => 'Некорректное распределение'];
            }
        }
        if ($e) {
            Validation::fail($e);
        }
        return [
            'user_id' => $userId, 'amount' => $amount, 'payment_method' => $method, 'payment_date' => $date,
            'reference' => $reference, 'comment' => $comment, 'payroll_run_id' => $runId,
            'allocations' => $allocations, 'post' => $post,
        ];
    }

    /** @return array{user_id:int,type:string,amount:string,reason:string,work_record_id:?int,payroll_run_id:?int} */
    public static function adjustment(array $in): array
    {
        $e = [];
        $userId = Input::id($in['user_id'] ?? null);
        if ($userId === null) {
            $e['user_id'] = 'Укажите исполнителя';
        }
        $type = Input::str($in['type'] ?? null);
        if ($type === null || !in_array($type, self::ADJUSTMENT_TYPES, true)) {
            $e['type'] = 'manual, overpayment или payroll_correction';
        }
        $amount = Money::signed($in['amount'] ?? null);
        if ($amount === null) {
            $e['amount'] = 'Сумма: ненулевое число со знаком (плюс увеличивает долг перед исполнителем, минус уменьшает), до 2 знаков';
        }
        $reason = Input::str($in['reason'] ?? null);
        if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            $e['reason'] = 'Причина: от 3 до 1000 символов';
        }
        $workId = null;
        if (isset($in['work_record_id'])) {
            $workId = Input::id($in['work_record_id']);
            if ($workId === null) {
                $e['work_record_id'] = 'Некорректная работа';
            }
        }
        $runId = null;
        if (isset($in['payroll_run_id'])) {
            $runId = Input::id($in['payroll_run_id']);
            if ($runId === null) {
                $e['payroll_run_id'] = 'Некорректный расчёт';
            }
        }
        if ($e) {
            Validation::fail($e);
        }
        return ['user_id' => $userId, 'type' => $type, 'amount' => $amount, 'reason' => $reason, 'work_record_id' => $workId, 'payroll_run_id' => $runId];
    }
}
