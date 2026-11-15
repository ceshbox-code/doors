<?php
declare(strict_types=1);

namespace App\Validators;

use App\Core\ApiException;
use App\Models\User;

final class UserValidator
{
    private static function text($v): ?string
    {
        return is_string($v) ? trim($v) : null;
    }

    private static function fail(array $errors): void
    {
        throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, $errors);
    }

    private static function checkFullName(?string $v, array &$errors): void
    {
        if ($v === null || mb_strlen($v) < 2 || mb_strlen($v) > 190) {
            $errors['full_name'] = 'ФИО: от 2 до 190 символов';
        }
    }

    private static function checkPassword($v, ?string $username, array &$errors): void
    {
        if (!is_string($v) || mb_strlen($v) < 10 || mb_strlen($v) > 200) {
            $errors['password'] = 'Пароль: от 10 до 200 символов';
        } elseif ($username !== null && strcasecmp($v, $username) === 0) {
            $errors['password'] = 'Пароль не должен совпадать с логином';
        }
    }

    private static function checkRole(?string $v, array &$errors): void
    {
        if ($v === null || !in_array($v, User::ROLES, true)) {
            $errors['role'] = 'Роль: owner, master или director';
        }
    }

    /** @return array{full_name:string,username:string,password:string,role:string} */
    public static function create(array $in): array
    {
        $errors = [];
        $fullName = self::text($in['full_name'] ?? null);
        $username = self::text($in['username'] ?? null);
        $role     = self::text($in['role'] ?? null);

        self::checkFullName($fullName, $errors);
        if ($username === null || !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $errors['username'] = 'Логин: 3–100 символов, латиница, цифры, точка, дефис, подчёркивание';
        }
        self::checkPassword($in['password'] ?? null, $username, $errors);
        self::checkRole($role, $errors);

        if ($errors) {
            self::fail($errors);
        }
        return ['full_name' => $fullName, 'username' => $username, 'password' => $in['password'], 'role' => $role];
    }

    /** @return array{full_name?:string,role?:string,password?:string} */
    public static function update(array $in): array
    {
        $errors = [];
        $out = [];

        if (array_key_exists('username', $in)) {
            $errors['username'] = 'Логин изменить нельзя';
        }
        if (array_key_exists('is_active', $in)) {
            $errors['is_active'] = 'Используйте /users/{id}/deactivate и /users/{id}/activate';
        }
        if (array_key_exists('full_name', $in)) {
            $out['full_name'] = self::text($in['full_name']);
            self::checkFullName($out['full_name'], $errors);
        }
        if (array_key_exists('role', $in)) {
            $out['role'] = self::text($in['role']);
            self::checkRole($out['role'], $errors);
        }
        if (array_key_exists('password', $in)) {
            self::checkPassword($in['password'], null, $errors);
            $out['password'] = $in['password'];
        }
        if (!$errors && !$out) {
            $errors['_'] = 'Нет данных для изменения';
        }
        if ($errors) {
            self::fail($errors);
        }
        return $out;
    }
}
