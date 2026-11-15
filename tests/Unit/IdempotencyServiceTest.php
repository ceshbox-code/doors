<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IdempotencyService;
use PHPUnit\Framework\TestCase;

final class IdempotencyServiceTest extends TestCase
{
    public function testHashIsIndependentOfKeyOrder(): void
    {
        $a = IdempotencyService::hash(['a' => 1, 'b' => ['x' => 1, 'y' => 2]]);
        $b = IdempotencyService::hash(['b' => ['y' => 2, 'x' => 1], 'a' => 1]);
        $this->assertSame($a, $b);
    }

    public function testHashDiffersForDifferentContent(): void
    {
        $this->assertNotSame(
            IdempotencyService::hash(['quantity' => '1.000']),
            IdempotencyService::hash(['quantity' => '2.000'])
        );
    }

    public function testKeyValidation(): void
    {
        $this->assertTrue(IdempotencyService::validKey('3f2b8c1e-aaaa-4bbb-8ccc-123456789abc'));
        $this->assertTrue(IdempotencyService::validKey('mobile:2026-11-13T10.20'));
        foreach (['short', str_repeat('a', 101), 'with space 123', "evil\nkey12345", ''] as $bad) {
            $this->assertFalse(IdempotencyService::validKey($bad), $bad);
        }
    }
}
