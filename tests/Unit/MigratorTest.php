<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testSplitsStatementsAndSkipsComments(): void
    {
        $sql = "-- комментарий; с точкой с запятой\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (id INT);\n";
        $this->assertSame(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'], Migrator::splitStatements($sql));
    }

    public function testCyrillicXInCommentDoesNotSplitLine(): void
    {
        // «х» в UTF-8 = D1 85; \R в PCRE без /u считает 0x85 переводом строки
        $sql = "-- у действующих расчётов,\n-- чем в одном действующем\nCREATE TABLE a (id INT);\n";
        $this->assertSame(['CREATE TABLE a (id INT)'], Migrator::splitStatements($sql));
    }

    public function testAllShippedMigrationsParse(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') as $file) {
            $this->assertNotEmpty(Migrator::splitStatements(file_get_contents($file)), basename($file));
        }
    }
}
