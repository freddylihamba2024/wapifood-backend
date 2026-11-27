<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Validation\InputValidator;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Validators\CustomerRules;
use App\Modules\Users\Presenters\UserPresenter;
use App\Modules\Users\Repositories\UserRepository;

/**
 * Modification du profil du compte connecté (prénom, nom, e-mail).
 *
 * - Le téléphone n'est pas modifiable ici : c'est l'identifiant de connexion, vérifié par SMS.
 * - Un changement d'e-mail annule sa vérification.
 * - Le prénom ne peut pas être vidé ; le nom et l'e-mail peuvent l'être (envoyer une chaîne vide).
 */
final class ProfileService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $userId, array $input): array
    {
        $data    = InputValidator::validate($input, CustomerRules::profile());
        $user    = $this->users->find($userId) ?? throw ApiException::unauthorized();
        $changes = [];

        if (array_key_exists('first_name', $data)) {
            $firstName = trim((string) $data['first_name']);

            if ($firstName === '') {
                throw ApiException::validation(['first_name' => 'Le prénom est obligatoire.']);
            }

            $changes['first_name'] = $firstName;
        }

        if (array_key_exists('last_name', $data)) {
            $lastName              = trim((string) $data['last_name']);
            $changes['last_name']  = $lastName === '' ? null : $lastName;
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            $email = $email === '' ? null : mb_strtolower($email);

            if ($email !== $user['email']) {
                if ($email !== null && $this->users->emailExists($email)) {
                    throw ApiException::conflict('Cette adresse e-mail est déjà associée à un compte.', ['email' => 'Déjà utilisée.'], 'EMAIL_TAKEN');
                }

                $changes['email']             = $email;
                $changes['email_verified_at'] = null;
            }
        }

        $changes = array_filter($changes, static fn ($value, string $field): bool => $field === 'email_verified_at' || $value !== $user[$field], ARRAY_FILTER_USE_BOTH);

        if ($changes !== []) {
            $this->users->update($userId, $changes);
            $this->audit->log('user.profile_updated', $userId, 'user', $userId, ['fields' => array_values(array_diff(array_keys($changes), ['email_verified_at']))]);
            $user = $this->users->find($userId);
        }

        return UserPresenter::present($user, $this->users->rolesOf($userId));
    }
}
