<?php

declare(strict_types=1);

namespace App\Modules\Users\Repositories;

use App\Common\Support\PhoneNumber;
use App\Modules\Users\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Accès aux comptes et à leurs rôles globaux.
 */
final class UserRepository
{
    private BaseConnection $db;

    public function __construct(private readonly UserModel $users = new UserModel(), ?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->users->find($id);
    }

    /**
     * Recherche par e-mail (si l'identifiant contient « @ ») ou par téléphone normalisé.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return $this->users->where('email', mb_strtolower($identifier))->first();
        }

        $phone = PhoneNumber::normalize($identifier);

        return $phone === null ? null : $this->users->where('phone', $phone)->first();
    }

    /** Un téléphone ou e-mail reste réservé même si le compte a été supprimé logiquement. */
    public function phoneExists(string $phone): bool
    {
        return $this->users->withDeleted()->where('phone', $phone)->countAllResults() > 0;
    }

    public function emailExists(string $email): bool
    {
        return $this->users->withDeleted()->where('email', $email)->countAllResults() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return (int) $this->users->insert($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->users->update($id, $data);
    }

    /**
     * Incrémente token_version : tous les jetons d'accès déjà émis deviennent invalides.
     */
    public function bumpTokenVersion(int $id): void
    {
        $this->db->table('wf_users')->set('token_version', 'token_version + 1', false)->where('id', $id)->update();
    }

    /**
     * @return list<string> Codes des rôles globaux (ex. customer, super_admin)
     */
    public function rolesOf(int $userId): array
    {
        $rows = $this->db->table('wf_user_roles ur')
            ->select('r.code')
            ->join('wf_roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)
            ->orderBy('r.id')
            ->get()
            ->getResultArray();

        return array_column($rows, 'code');
    }

    public function assignRole(int $userId, string $roleCode): void
    {
        $role = $this->db->table('wf_roles')->select('id')->where('code', $roleCode)->get()->getRowArray();

        if ($role === null) {
            throw new RuntimeException("Rôle inconnu : {$roleCode}. Exécutez « php spark db:seed DatabaseSeeder ».");
        }

        $this->db->table('wf_user_roles')->ignore(true)->insert(['user_id' => $userId, 'role_id' => (int) $role['id']]);
    }
}
