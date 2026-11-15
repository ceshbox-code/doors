<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;

$pdo = Database::pdo();
$files = glob(dirname(__DIR__) . '/database/seeds/*.php') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    (require $file)($pdo);
    echo 'Seed: ' . basename($file) . "\n";
}
