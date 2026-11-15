<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\{Database, Migrator};

$applied = (new Migrator(Database::pdo(), dirname(__DIR__) . '/database/migrations'))->run();
echo $applied ? "Применено миграций: " . count($applied) . "\n  " . implode("\n  ", $applied) . "\n"
              : "Схема актуальна, новых миграций нет.\n";
