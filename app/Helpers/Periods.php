<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Validators\FinanceValidator;
use App\Validators\Validation;

/** Периоды отчётов (ТЗ 7.2): готовые наборы и произвольные даты. Все границы — календарные даты в часовом поясе предприятия. */
final class Periods
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'prev_week', 'this_month', 'prev_month'];

    private const NAMES = [
        'today' => 'Сегодня', 'yesterday' => 'Вчера', 'this_week' => 'Текущая неделя',
        'prev_week' => 'Предыдущая неделя', 'this_month' => 'Текущий месяц', 'prev_month' => 'Предыдущий месяц',
    ];

    /**
     * @return array{from:?string,to:?string,label:string,preset:?string} from/to — 'YYYY-MM-DD' (включительно) или null
     */
    public static function resolve(array $query, string $timezone, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone($timezone));
        $preset = isset($query['period']) && is_string($query['period']) && $query['period'] !== '' ? $query['period'] : null;

        if ($preset !== null) {
            if (!in_array($preset, self::PRESETS, true)) {
                Validation::fail(['period' => implode(', ', self::PRESETS)]);
            }
            $today = $now->setTime(0, 0);
            switch ($preset) {
                case 'today':      [$a, $b] = [$today, $today]; break;
                case 'yesterday':  $y = $today->modify('-1 day'); [$a, $b] = [$y, $y]; break;
                case 'this_week':  $m = $today->modify('monday this week'); [$a, $b] = [$m, $m->modify('+6 days')]; break;
                case 'prev_week':  $m = $today->modify('monday this week')->modify('-7 days'); [$a, $b] = [$m, $m->modify('+6 days')]; break;
                case 'this_month': $f = $today->modify('first day of this month'); [$a, $b] = [$f, $f->modify('last day of this month')]; break;
                default:           $f = $today->modify('first day of last month'); [$a, $b] = [$f, $f->modify('last day of this month')];
            }
            $from = $a->format('Y-m-d');
            $to = $b->format('Y-m-d');
            return ['from' => $from, 'to' => $to, 'label' => self::NAMES[$preset] . ' (' . self::rangeLabel($from, $to) . ')', 'preset' => $preset];
        }

        $p = FinanceValidator::period($query);
        return ['from' => $p['from'], 'to' => $p['to'], 'label' => self::rangeLabel($p['from'], $p['to']), 'preset' => null];
    }

    public static function rangeLabel(?string $from, ?string $to): string
    {
        if ($from === null && $to === null) {
            return 'За всё время';
        }
        if ($from !== null && $from === $to) {
            return Format::dateIso($from);
        }
        if ($from !== null && $to !== null) {
            return 'с ' . Format::dateIso($from) . ' по ' . Format::dateIso($to);
        }
        return $from !== null ? 'с ' . Format::dateIso($from) : 'по ' . Format::dateIso((string) $to);
    }
}
