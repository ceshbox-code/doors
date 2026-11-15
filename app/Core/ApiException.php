<?php
declare(strict_types=1);

namespace App\Core;

/** Ожидаемая ошибка бизнес-логики/доступа; превращается в ответ в едином формате ТЗ п. 12.11. */
final class ApiException extends \RuntimeException
{
    public string $errorCode;
    public int $httpStatus;
    public ?array $details;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400, ?array $details = null)
    {
        parent::__construct($message);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->details    = $details;
    }
}
