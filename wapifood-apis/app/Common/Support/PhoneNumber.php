<?php

declare(strict_types=1);

namespace App\Common\Support;

/**
 * Normalisation des numéros de téléphone au format E.164.
 *
 * RDC (+243) : 9 chiffres nationaux, sans le 0 initial.
 *   0812345678, 812345678, 243812345678, +243 81 234 5678, 00243812345678 → +243812345678
 * Autres pays : accepté uniquement en format international (+XXXXXXXX, 8 à 15 chiffres).
 */
final class PhoneNumber
{
    public const DRC_PREFIX = '+243';

    /**
     * @return string|null Numéro E.164, ou null si le numéro est invalide
     */
    public static function normalize(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '' || preg_match('/[^\d\s+().\-]/', $value) === 1) {
            return null;
        }

        $hasPlus = str_starts_with($value, '+');
        $digits  = (string) preg_replace('/\D/', '', $value);

        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits  = substr($digits, 2);
            $hasPlus = true;
        }

        if ($hasPlus || (strlen($digits) === 12 && str_starts_with($digits, '243'))) {
            $number = '+' . $digits;

            if (str_starts_with($number, self::DRC_PREFIX)) {
                return self::isValidDrc($number) ? $number : null;
            }

            return preg_match('/\A\+[1-9]\d{7,14}\z/', $number) === 1 ? $number : null;
        }

        // Numéro national congolais
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $number = self::DRC_PREFIX . $digits;

        return self::isValidDrc($number) ? $number : null;
    }

    private static function isValidDrc(string $number): bool
    {
        return preg_match('/\A\+243[1-9]\d{8}\z/', $number) === 1;
    }
}
