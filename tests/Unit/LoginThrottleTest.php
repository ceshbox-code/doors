<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LoginThrottle;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    public function testBelowLimitsIsAllowed(): void
    {
        $this->assertFalse(LoginThrottle::isBlocked(4, 19));
    }

    public function testUsernameLimitBlocks(): void
    {
        $this->assertTrue(LoginThrottle::isBlocked(5, 0));
    }

    public function testIpLimitBlocks(): void
    {
        $this->assertTrue(LoginThrottle::isBlocked(0, 20));
    }
}
