<?php

declare(strict_types=1);

namespace App\Common\Support;

use InvalidArgumentException;

/**
 * Montants monétaires en unités mineures entières (centimes) : aucun calcul en float.
 *
 * Les colonnes DECIMAL(12,2) arrivent en chaînes ("12.50") ; on les convertit en entiers
 * (1250) pour calculer, puis on reformate en chaîne pour la base et l'API.
 */
final class Money
{
    /** Montant décimal positif ou nul, 2 décimales maximum, 10 chiffres entiers maximum. */
    public const UNSIGNED_PATTERN = '/\A\d{1,10}(\.\d{1,2})?\z/';

    /** Idem, signe moins autorisé (ex. variante moins chère que le produit de base). */
    public const SIGNED_PATTERN = '/\A-?\d{1,10}(\.\d{1,2})?\z/';

    public static function isValid(string $amount, bool $allowNegative = false): bool
    {
        return preg_match($allowNegative ? self::SIGNED_PATTERN : self::UNSIGNED_PATTERN, $amount) === 1;
    }

    /**
     * "12.5" → 1250 ; "-0.75" → -75
     */
    public static function toCents(string|int $amount): int
    {
        $amount = trim((string) $amount);

        if (! self::isValid($amount, true)) {
            throw new InvalidArgumentException("Montant invalide : « {$amount} ».");
        }

        $negative = str_starts_with($amount, '-');
        [$units, $decimals] = array_pad(explode('.', ltrim($amount, '-')), 2, '');

        $cents = (int) $units * 100 + (int) str_pad($decimals, 2, '0');

        return $negative ? -$cents : $cents;
    }

    /**
     * 1250 → "12.50" ; -75 → "-0.75"
     */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs  = abs($cents);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Normalise une saisie : "5" → "5.00". */
    public static function normalize(string $amount): string
    {
        return self::fromCents(self::toCents($amount));
    }
}
