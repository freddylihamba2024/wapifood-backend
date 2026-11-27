<?php

declare(strict_types=1);

namespace Tests\Unit\Commissions;

use App\Modules\Commissions\Services\CommissionCalculator;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Commission D5 : assiette = sous-total − remise restaurant, hors livraison ; arrondi au centime.
 *
 * @internal
 */
final class CommissionCalculatorTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function cases(): iterable
    {
        yield '10 % de 31.60' => ['31.60', '0.00', '10.00', '3.16', '28.44'];
        yield 'remise déduite de l\'assiette' => ['20.00', '5.00', '10.00', '1.50', '13.50'];
        yield 'arrondi 0,5 au-dessus' => ['0.25', '0.00', '10.00', '0.03', '0.22'];
        yield 'arrondi en dessous' => ['0.24', '0.00', '10.00', '0.02', '0.22'];
        yield 'taux décimal 12,5 %' => ['8.50', '0.00', '12.50', '1.06', '7.44'];
        yield 'taux nul' => ['100.00', '0.00', '0', '0.00', '100.00'];
        yield 'taux 100 %' => ['9.99', '0.00', '100', '9.99', '0.00'];
        yield 'remise supérieure au sous-total' => ['5.00', '8.00', '10.00', '0.00', '0.00'];
        yield 'gros montant CDF' => ['1250000.00', '0.00', '7.50', '93750.00', '1156250.00'];
    }

    #[DataProvider('cases')]
    public function testCalculation(string $subtotal, string $discount, string $rate, string $commission, string $restaurant): void
    {
        $result = (new CommissionCalculator())->calculate($subtotal, $discount, $rate);

        $this->assertSame($commission, $result['commission_amount']);
        $this->assertSame($restaurant, $result['restaurant_amount']);
    }

    public function testInvalidRateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CommissionCalculator())->calculate('10.00', '0.00', '100.01');
    }
}
