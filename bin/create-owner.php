<?php
declare(strict_types=1);

/**
 * Создание первого администратора (роль owner).
 * Использование: php bin/create-owner.php <логин> "<ФИО>"
 * Пароль берётся из переменной OWNER_PASSWORD или запрашивается в консоли.
 * Пароли никогда не хранятся в репозитории.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;
use App\Helpers\PasswordHasher;

[$script, $username, $fullName] = $argv + [null, null, null];
if (!$username || !$fullName) {
    fwrite(STDERR, "Использование: php bin/create-owner.php <логин> \"<ФИО>\"\n");
    exit(1);
}

$password = getenv('OWNER_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDOUT, 'Пароль (не менее 10 символов): ');
    @system('stty -echo');
    $password = trim((string) fgets(STDIN));
    @system('stty echo');
    fwrite(STDOUT, "\n");
}
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Пароль слишком короткий.\n");
    exit(1);
}

$pdo = Database::pdo();
$pdo->prepare("INSERT INTO users (full_name, username, password_hash, role) VALUES (?, ?, ?, 'owner')")
    ->execute([$fullName, $username, PasswordHasher::hash($password)]);
echo "Администратор '{$username}' создан.\n";
