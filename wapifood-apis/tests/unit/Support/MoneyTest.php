<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Common\Support\Money;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class MoneyTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideConversions(): iterable
    {
        yield 'entier'          => ['12', 1200];
        yield 'une décimale'    => ['12.5', 1250];
        yield 'deux décimales'  => ['12.05', 1205];
        yield 'zéro'            => ['0', 0];
        yield 'négatif'         => ['-0.75', -75];
        yield 'grand montant'   => ['9999999999.99', 999999999999];
        yield 'centime'         => ['0.01', 1];
    }

    #[DataProvider('provideConversions')]
    public function testToCentsAndBack(string $amount, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($amount));
        $this->assertSame(Money::normalize($amount), Money::fromCents($cents));
    }

    public function testNoFloatingPointDrift(): void
    {
        // 0.1 + 0.2 en float vaut 0.30000000000000004.
        $this->assertSame('0.30', Money::fromCents(Money::toCents('0.1') + Money::toCents('0.2')));
    }

    public function testInvalidAmounts(): void
    {
        foreach (['1.234', 'abc', '1e3', '', '1,50', '--1', '12345678901'] as $amount) {
            $this->assertFalse(Money::isValid($amount, true), $amount);
        }

        $this->assertFalse(Money::isValid('-1'));
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('1.234');
    }
}
