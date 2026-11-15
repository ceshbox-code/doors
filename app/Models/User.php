<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class User
{
    public const ROLES = ['owner', 'master', 'director'];

    /** Представление пользователя для API: без хэша пароля. */
    public static function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'full_name'  => $row['full_name'],
            'username'   => $row['username'],
            'role'       => $row['role'],
            'is_active'  => (bool) (int) $row['is_active'],
            'created_at' => Dates::toIso($row['created_at']),
            'updated_at' => Dates::toIso($row['updated_at']),
        ];
    }
}
