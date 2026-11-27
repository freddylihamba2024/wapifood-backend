<?php

declare(strict_types=1);

namespace App\Common\Validation;

use App\Common\Support\Money;
use App\Common\Support\PhoneNumber;

/**
 * Règles de validation propres à WapiFood (enregistrées dans Config\Validation).
 * Les messages sont dans app/Language/{fr,en}/Validation.php.
 */
final class WapiRules
{
    /** Booléen JSON, 0/1 ou "true"/"false". */
    public function wf_boolean(mixed $value): bool
    {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
    }

    /** Montant ≥ 0, 2 décimales maximum (chaîne ou nombre JSON). */
    public function wf_money(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && Money::isValid(self::numericString($value));
    }

    /** Montant pouvant être négatif (écart de prix d'une variante). */
    public function wf_money_signed(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value)) && Money::isValid(self::numericString($value), true);
    }

    /** Numéro de téléphone normalisable en E.164 (RDC ou international). */
    public function wf_phone(mixed $value): bool
    {
        return is_string($value) && PhoneNumber::normalize($value) !== null;
    }

    /** Entier JSON ou chaîne de chiffres. */
    public function wf_int(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/\A-?\d{1,9}\z/', $value) === 1);
    }

    /** Liste JSON (tableau indexé). */
    public function wf_list(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    public static function numericString(mixed $value): string
    {
        if (is_float($value)) {
            // 12.5 → "12.5" ; refuse la notation scientifique.
            $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }

        return trim((string) $value);
    }

    /** Conversion d'une valeur validée par wf_boolean. */
    public static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
