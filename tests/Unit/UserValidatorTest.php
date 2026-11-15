<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ApiException;
use App\Validators\UserValidator;
use PHPUnit\Framework\TestCase;

final class UserValidatorTest extends TestCase
{
    private function valid(): array
    {
        return ['full_name' => 'Иванов Иван', 'username' => 'ivanov', 'password' => 'long-enough-pass', 'role' => 'master'];
    }

    private function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            return $e->details;
        }
        $this->fail('Ожидалась ApiException');
    }

    public function testValidCreate(): void
    {
        $d = UserValidator::create($this->valid());
        $this->assertSame('ivanov', $d['username']);
        $this->assertSame('master', $d['role']);
    }

    public function testCreateTrimsNames(): void
    {
        $d = UserValidator::create(['full_name' => '  Петров Пётр ', 'username' => ' petrov '] + $this->valid());
        $this->assertSame('Петров Пётр', $d['full_name']);
        $this->assertSame('petrov', $d['username']);
    }

    public function testCreateRejectsBadFields(): void
    {
        $e = $this->errors(fn() => UserValidator::create([
            'full_name' => 'И', 'username' => 'a b', 'password' => 'short', 'role' => 'boss',
        ]));
        $this->assertEqualsCanonicalizing(['full_name', 'username', 'password', 'role'], array_keys($e));
    }

    public function testPasswordMustDifferFromUsername(): void
    {
        $e = $this->errors(fn() => UserValidator::create(['username' => 'longusername1', 'password' => 'LONGUSERNAME1'] + $this->valid()));
        $this->assertArrayHasKey('password', $e);
    }

    public function testUpdateAcceptsPartialChanges(): void
    {
        $this->assertSame(['role' => 'director'], UserValidator::update(['role' => 'director']));
    }

    public function testUpdateRejectsUsernameAndIsActive(): void
    {
        $e = $this->errors(fn() => UserValidator::update(['username' => 'x1x', 'is_active' => false]));
        $this->assertArrayHasKey('username', $e);
        $this->assertArrayHasKey('is_active', $e);
    }

    public function testUpdateRejectsEmptyPayload(): void
    {
        $this->assertArrayHasKey('_', $this->errors(fn() => UserValidator::update([])));
    }
}
