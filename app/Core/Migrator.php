<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Применяет database/migrations/*.sql по порядку имён. Применённые файлы
 * фиксируются в schema_migrations и повторно не выполняются.
 * DDL в MariaDB неявно коммитится, поэтому файлы выполняются по одному оператору.
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir) {}

    /** @return string[] применённые в этом запуске файлы */
    public function run(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $done = $this->pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($file)) as $sql) {
                $this->pdo->exec($sql);
            }
            $this->pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    /**
     * Делит SQL на операторы по «;» в конце строки, убирая строки-комментарии.
     * Нарочно без \R и \s: в однобайтовом режиме PCRE \R считает переводом строки байт 0x85,
     * а это вторая половина буквы «х» в UTF-8, и русский комментарий разрезался посреди слова.
     */
    public static function splitStatements(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $sql) as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $lines[] = $line;
        }
        $parts = preg_split('/;[ \t]*(?:\r\n|\n|\r|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($s) => $s !== ''));
    }
}
