<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\PhoneNumber;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Auth\Services\PasswordHasher;
use App\Modules\Users\Models\RoleModel;
use App\Modules\Users\Models\UserModel;
use App\Modules\Users\Models\UserRoleModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Création des comptes super administrateur.
 *
 * Utilisé par la commande `php spark wapifood:create-admin` : aucun mot de passe
 * n'est jamais prédéfini ni stocké en clair.
 */
final class AdminAccountService
{
    public const SUPER_ADMIN_ROLE = 'super_admin';

    private BaseConnection $db;

    public function __construct(
        private readonly UserModel $users = new UserModel(),
        private readonly RoleModel $roles = new RoleModel(),
        private readonly UserRoleModel $userRoles = new UserRoleModel(),
        private readonly PasswordHasher $hasher = new PasswordHasher(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    public function superAdminCount(): int
    {
        return $this->db->table('wf_user_roles ur')
            ->join('wf_roles r', 'r.id = ur.role_id')
            ->join('wf_users u', 'u.id = ur.user_id')
            ->where('r.code', self::SUPER_ADMIN_ROLE)
            ->where('u.deleted_at', null)
            ->countAllResults();
    }

    /**
     * @param bool $allowAdditional Autorise la création si un super administrateur existe déjà
     *
     * @return int Identifiant du compte créé
     *
     * @throws ApiException 422 (données invalides) ou 409 (conflit)
     */
    public function createSuperAdmin(
        string $firstName,
        ?string $lastName,
        ?string $email,
        string $phone,
        string $password,
        bool $allowAdditional = false,
    ): int {
        $firstName = trim($firstName);
        $lastName  = ($lastName !== null && trim($lastName) !== '') ? trim($lastName) : null;
        $email     = ($email !== null && trim($email) !== '') ? mb_strtolower(trim($email)) : null;
        $phoneE164 = PhoneNumber::normalize($phone);

        $errors = [];

        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $errors['first_name'] = 'Le prénom est requis (100 caractères maximum).';
        }

        if ($lastName !== null && mb_strlen($lastName) > 100) {
            $errors['last_name'] = 'Le nom ne doit pas dépasser 100 caractères.';
        }

        if ($email !== null && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190)) {
            $errors['email'] = 'L\'adresse e-mail est invalide.';
        }

        if ($phoneE164 === null) {
            $errors['phone'] = 'Le numéro de téléphone est invalide.';
        }

        $passwordErrors = $this->hasher->policyErrors($password, $firstName, (string) $lastName, (string) $email);

        if ($passwordErrors !== []) {
            $errors['password'] = implode(' ', $passwordErrors);
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        if (! $allowAdditional && $this->superAdminCount() > 0) {
            throw ApiException::conflict('Un super administrateur existe déjà. Utilisez --additional pour en créer un autre.', [], 'SUPER_ADMIN_EXISTS');
        }

        // withDeleted : un numéro ou un e-mail reste réservé même après suppression logique.
        if ($this->users->withDeleted()->where('phone', $phoneE164)->countAllResults() > 0) {
            throw ApiException::conflict('Ce numéro de téléphone est déjà utilisé.', ['phone' => 'Déjà utilisé.'], 'PHONE_TAKEN');
        }

        if ($email !== null && $this->users->withDeleted()->where('email', $email)->countAllResults() > 0) {
            throw ApiException::conflict('Cette adresse e-mail est déjà utilisée.', ['email' => 'Déjà utilisée.'], 'EMAIL_TAKEN');
        }

        $role = $this->roles->where('code', self::SUPER_ADMIN_ROLE)->first();

        if ($role === null) {
            throw new RuntimeException('Rôle super_admin absent : exécutez « php spark db:seed DatabaseSeeder ».');
        }

        $this->db->transException(true)->transStart();

        $userId = (int) $this->users->insert([
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'email'         => $email,
            'phone'         => $phoneE164,
            'password_hash' => $this->hasher->hash($password),
            'status'        => 'active',
        ]);

        $this->userRoles->insert(['user_id' => $userId, 'role_id' => (int) $role['id']]);

        $this->audit->log('admin.super_admin_created', null, 'user', $userId, ['channel' => 'cli']);

        $this->db->transComplete();

        return $userId;
    }
}
