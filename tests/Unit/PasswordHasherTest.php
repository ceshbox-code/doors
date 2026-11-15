<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $h = PasswordHasher::hash('correct horse battery');
        $this->assertNotSame('correct horse battery', $h);
        $this->assertTrue(PasswordHasher::verify('correct horse battery', $h));
        $this->assertFalse(PasswordHasher::verify('wrong', $h));
        $this->assertFalse(PasswordHasher::needsRehash($h));
    }

    public function testBurnDoesNotThrow(): void
    {
        PasswordHasher::burn('anything');
        $this->assertTrue(true);
    }
}
