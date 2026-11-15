<?php
declare(strict_types=1);

namespace App\Core;

use PDOException;
use Throwable;

/** Транзакция с откатом при любой ошибке и повтором при взаимной блокировке (MariaDB 1213/1205). */
final class Tx
{
    /** @return mixed */
    public static function run(callable $fn, int $attempts = 3)
    {
        $pdo = Database::pdo();
        for ($i = 1; ; $i++) {
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $driverCode = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (in_array($driverCode, [1213, 1205], true) && $i < $attempts) {
                    usleep(random_int(20, 120) * 1000);
                    continue;
                }
                throw $e;
            }
        }
    }
}
