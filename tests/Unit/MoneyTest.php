<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testPriceAcceptsNumbersAndStrings(): void
    {
        $this->assertSame('500.00', Money::price('500'));
        $this->assertSame('12.50', Money::price('12.5'));
        $this->assertSame('12.50', Money::price(12.5));
        $this->assertSame('0.00', Money::price(0));
        $this->assertSame('9999999.99', Money::price('9999999.99'));
    }

    public function testPriceRejectsBadValues(): void
    {
        foreach (['1.234', '-1', 'abc', '', '10000000', true, null, [], '1e3', '1,5'] as $bad) {
            $this->assertNull(Money::price($bad), var_export($bad, true));
        }
    }

    public function testQuantity(): void
    {
        $this->assertSame('1.000', Money::quantity('1'));
        $this->assertSame('0.500', Money::quantity(0.5));
        $this->assertNull(Money::quantity('1.2345'));
        $this->assertNull(Money::quantity('100000'));
        $this->assertTrue(Money::isPositive('0.001'));
        $this->assertFalse(Money::isPositive('0.000'));
    }

    public function testMultiplyRoundsHalfUpWithoutFloatErrors(): void
    {
        $this->assertSame('500.00', Money::multiply('1.000', '500.00'));
        $this->assertSame('0.13', Money::multiply('0.250', '0.50'));      // 0.125 -> 0.13
        $this->assertSame('0.12', Money::multiply('0.249', '0.50'));      // 0.1245 -> 0.12
        $this->assertSame('499.98', Money::multiply('2.500', '199.99'));  // 499.975 -> 499.98
        $this->assertSame('99.99', Money::multiply('3.000', '33.33'));
        $this->assertSame('0.30', Money::multiply('3.000', '0.10'));      // классическая ловушка 0.1*3 во float
    }

    public function testMaximumFitsIntoDecimal14_2(): void
    {
        $max = Money::multiply('99999.999', '9999999.99');
        $this->assertLessThanOrEqual(0, bccomp($max, '999999999999.99', 2));
    }

    public function testAmountIsPositiveWithTwoDecimals(): void
    {
        $this->assertSame('300.00', Money::amount('300'));
        $this->assertSame('0.01', Money::amount(0.01));
        $this->assertSame('999999999999.99', Money::amount('999999999999.99'));
        foreach (['0', '0.00', '-5', '1.234', 'abc', '', null, '1000000000000'] as $bad) {
            $this->assertNull(Money::amount($bad), var_export($bad, true));
        }
    }

    public function testSignedAmount(): void
    {
        $this->assertSame('-350.00', Money::signed('-350'));
        $this->assertSame('-0.50', Money::signed(-0.5));
        $this->assertSame('50.00', Money::signed('+50'));
        $this->assertSame('12.30', Money::signed(12.3));
        foreach (['0', '-0.00', '', 'x', '--5', '1.005', null] as $bad) {
            $this->assertNull(Money::signed($bad), var_export($bad, true));
        }
    }

    public function testDebtAndOverpaymentAreSeparated(): void
    {
        $this->assertSame('20.00', Money::positive('20.00'));
        $this->assertSame('0.00', Money::positive('-30.00'));
        $this->assertSame('30.00', Money::negativePart('-30.00'));
        $this->assertSame('0.00', Money::negativePart('20.00'));
        $this->assertSame('0.30', Money::add('0.10', '0.20'));        // без ошибок float
        $this->assertSame(0, Money::cmp('1.10', '1.1'));
    }
}
