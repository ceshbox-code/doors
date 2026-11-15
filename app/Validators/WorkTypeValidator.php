<?php
declare(strict_types=1);

namespace App\Validators;

use App\Helpers\Input;
use App\Models\WorkType;

final class WorkTypeValidator
{
    private static function name(array $in, array &$e): ?string
    {
        $v = Input::str($in['name'] ?? null);
        if ($v === null || mb_strlen($v) < 2 || mb_strlen($v) > 190) {
            $e['name'] = 'Название: от 2 до 190 символов';
        }
        return $v;
    }

    private static function description(array $in, array &$e): ?string
    {
        if (!isset($in['description'])) {
            return null;
        }
        $v = Input::str($in['description']);
        if ($v === null || mb_strlen($v) > 2000) {
            $e['description'] = 'Описание: до 2000 символов';
            return null;
        }
        return $v === '' ? null : $v;
    }

    private static function unit(array $in, array &$e): ?string
    {
        $v = Input::str($in['unit'] ?? null);
        if ($v === null || mb_strlen($v) < 1 || mb_strlen($v) > 40) {
            $e['unit'] = 'Единица измерения: от 1 до 40 символов (шт, компл, пог.м, м², ч, операция и т.п.)';
        }
        return $v;
    }

    private static function category(array $in, array &$e): ?string
    {
        $v = Input::str($in['category'] ?? null);
        if ($v === null || !in_array($v, WorkType::CATEGORIES, true)) {
            $e['category'] = 'repair (ремонт), packing (упаковка) или other (дополнительные работы)';
        }
        return $v;
    }

    private static function flag(array $in, string $key, array &$e): ?bool
    {
        $v = Input::bool($in[$key] ?? null);
        if ($v === null) {
            $e[$key] = 'Допустимо true или false';
        }
        return $v;
    }

    public static function create(array $in): array
    {
        $e = [];
        $out = [
            'name'                    => self::name($in, $e),
            'description'             => self::description($in, $e),
            'unit'                    => array_key_exists('unit', $in) ? self::unit($in, $e) : 'шт',
            'category'                => array_key_exists('category', $in) ? self::category($in, $e) : 'other',
            'door_required'           => array_key_exists('door_required', $in) ? self::flag($in, 'door_required', $e) : true,
            'allow_multiple_per_door' => array_key_exists('allow_multiple_per_door', $in) ? self::flag($in, 'allow_multiple_per_door', $e) : false,
        ];
        if ($e) {
            Validation::fail($e);
        }
        return $out;
    }

    public static function update(array $in): array
    {
        $e = [];
        $out = [];
        if (array_key_exists('is_active', $in)) {
            $e['is_active'] = 'Используйте /work-types/{id}/deactivate и /activate';
        }
        if (array_key_exists('name', $in)) {
            $out['name'] = self::name($in, $e);
        }
        if (array_key_exists('description', $in)) {
            $out['description'] = $in['description'] === null ? null : self::description($in, $e);
        }
        if (array_key_exists('unit', $in)) {
            $out['unit'] = self::unit($in, $e);
        }
        if (array_key_exists('category', $in)) {
            $out['category'] = self::category($in, $e);
        }
        foreach (['door_required', 'allow_multiple_per_door'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = self::flag($in, $k, $e);
            }
        }
        if (!$e && !$out) {
            $e['_'] = 'Нет данных для изменения';
        }
        if ($e) {
            Validation::fail($e);
        }
        return $out;
    }
}
