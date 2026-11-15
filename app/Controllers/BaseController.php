<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;

abstract class BaseController
{
    /** Числовой {id} из пути; нечисловой считается несуществующим ресурсом. */
    protected static function id(Request $r, string $notFoundMessage): int
    {
        $raw = (string) ($r->params['id'] ?? '');
        if (!ctype_digit($raw) || strlen($raw) > 18) {
            throw new ApiException('NOT_FOUND', $notFoundMessage, 404);
        }
        return (int) $raw;
    }
}
