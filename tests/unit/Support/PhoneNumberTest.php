<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Common\Support\PhoneNumber;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class PhoneNumberTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideNumbers(): iterable
    {
        yield 'national 10 chiffres' => ['0812345678', '+243812345678'];
        yield 'national 9 chiffres'  => ['812345678', '+243812345678'];
        yield 'avec espaces'         => ['081 234 56 78', '+243812345678'];
        yield 'international +'      => ['+243 81 234 5678', '+243812345678'];
        yield 'sans +'               => ['243812345678', '+243812345678'];
        yield 'préfixe 00'           => ['00243812345678', '+243812345678'];
        yield 'tirets'               => ['+243-99-123-4567', '+243991234567'];
        yield 'autre pays'           => ['+33 6 12 34 56 78', '+33612345678'];
        yield 'trop court'           => ['08123456', null];
        yield 'trop long RDC'        => ['+2438123456789', null];
        yield 'commence par 0 RDC'   => ['+243012345678', null];
        yield 'lettres'              => ['0812ABC678', null];
        yield 'vide'                 => ['', null];
        yield 'injection'            => ["0812345678'; DROP TABLE", null];
    }

    #[DataProvider('provideNumbers')]
    public function testNormalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }
}
