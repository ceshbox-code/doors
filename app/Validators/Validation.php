<?php
declare(strict_types=1);

namespace App\Validators;

use App\Core\ApiException;

final class Validation
{
    /** @return never */
    public static function fail(array $errors): void
    {
        throw new ApiException('VALIDATION_ERROR', 'Проверьте введённые данные', 422, $errors);
    }
}
