<?php

declare(strict_types=1);

namespace App\Common\Validation;

use App\Common\Exceptions\ApiException;

/**
 * Valide un tableau de données avec les règles CI4, y compris pour des structures imbriquées
 * (les erreurs sont alors préfixées : « address.city », « variants.0.name »…).
 */
final class InputValidator
{
    /**
     * @param array<string, mixed>                       $data
     * @param array<string, array<string, mixed>|string> $rules
     *
     * @return array<string, mixed> Uniquement les champs déclarés
     *
     * @throws ApiException 422
     */
    public static function validate(array $data, array $rules, string $prefix = ''): array
    {
        $errors = self::errors($data, $rules, $prefix);

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return array_intersect_key($data, $rules);
    }

    /**
     * @param array<string, mixed>                       $data
     * @param array<string, array<string, mixed>|string> $rules
     *
     * @return array<string, string>
     */
    public static function errors(array $data, array $rules, string $prefix = ''): array
    {
        $validation = service('validation', null, false);
        $validation->setRules($rules);

        if ($validation->run($data)) {
            return [];
        }

        $errors = [];

        foreach ($validation->getErrors() as $field => $message) {
            $errors[$prefix . $field] = $message;
        }

        return $errors;
    }
}
