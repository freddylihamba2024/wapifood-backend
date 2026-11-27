<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\PhoneNumber;
use App\Common\Validation\InputValidator;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Users\Presenters\UserPresenter;
use App\Modules\Users\Repositories\UserRepository;
use CodeIgniter\Database\BaseConnection;

/**
 * Équipe d'un restaurant (wf_restaurant_users).
 *
 * - Un membre est un compte WapiFood existant et actif, ajouté par son numéro de téléphone.
 * - Rôles attribuables : manager, staff, cashier. Le propriétaire n'est ni modifiable ni retirable ici.
 * - Toute opération est limitée au restaurant du contexte (membre d'un autre restaurant = 404).
 */
final class StaffService
{
    public const ASSIGNABLE_ROLES = ['manager', 'staff', 'cashier'];

    private BaseConnection $db;

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $restaurantId): array
    {
        return array_map(self::present(...), $this->query($restaurantId)->orderBy('ru.id', 'ASC')->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $input { phone, role }
     *
     * @return array<string, mixed>
     */
    public function add(int $restaurantId, int $actorId, array $input): array
    {
        $data = InputValidator::validate($input, [
            'phone' => ['label' => 'téléphone', 'rules' => 'required|wf_phone'],
            'role'  => ['label' => 'rôle', 'rules' => 'required|in_list[' . implode(',', self::ASSIGNABLE_ROLES) . ']'],
        ]);

        $user = $this->users->findByIdentifier((string) PhoneNumber::normalize($data['phone']));

        if ($user === null || $user['status'] !== 'active') {
            throw ApiException::validation(['phone' => 'Aucun compte WapiFood actif ne correspond à ce numéro. La personne doit d\'abord créer son compte.']);
        }

        $exists = $this->db->table('wf_restaurant_users')
            ->where('restaurant_id', $restaurantId)
            ->where('user_id', $user['id'])
            ->countAllResults() > 0;

        if ($exists) {
            throw ApiException::conflict('Cette personne fait déjà partie de l\'équipe.', [], 'MEMBER_EXISTS');
        }

        $this->db->transException(true)->transStart();

        $this->db->table('wf_restaurant_users')->insert([
            'restaurant_id'   => $restaurantId,
            'user_id'         => $user['id'],
            'restaurant_role' => $data['role'],
            'status'          => 'active',
        ]);
        $memberId = (int) $this->db->insertID();

        $this->users->assignRole((int) $user['id'], 'restaurant_staff');
        $this->audit->log('restaurant.staff_added', $actorId, 'restaurant', $restaurantId, ['member_user_id' => (int) $user['id'], 'role' => $data['role']]);

        $this->db->transComplete();

        return $this->member($restaurantId, $memberId);
    }

    /**
     * @param array<string, mixed> $input { role?, status? }
     *
     * @return array<string, mixed>
     */
    public function update(int $restaurantId, int $actorId, int $memberId, array $input): array
    {
        $member = $this->editable($restaurantId, $actorId, $memberId);
        $data   = InputValidator::validate($input, [
            'role'   => ['label' => 'rôle', 'rules' => 'permit_empty|in_list[' . implode(',', self::ASSIGNABLE_ROLES) . ']'],
            'status' => ['label' => 'statut', 'rules' => 'permit_empty|in_list[active,disabled]'],
        ]);

        $changes = array_filter([
            'restaurant_role' => $data['role'] ?? null,
            'status'          => $data['status'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        if ($changes !== []) {
            $this->db->table('wf_restaurant_users')->where('id', $memberId)->update($changes);
            $this->audit->log('restaurant.staff_updated', $actorId, 'restaurant', $restaurantId, ['member_user_id' => (int) $member['user_id']] + $changes);
        }

        return $this->member($restaurantId, $memberId);
    }

    public function remove(int $restaurantId, int $actorId, int $memberId): void
    {
        $member = $this->editable($restaurantId, $actorId, $memberId);
        $userId = (int) $member['user_id'];

        $this->db->transException(true)->transStart();

        $this->db->table('wf_restaurant_users')->where('id', $memberId)->delete();

        // Plus aucune équipe : le rôle global « personnel restaurant » est retiré.
        $remaining = $this->db->table('wf_restaurant_users')
            ->where('user_id', $userId)
            ->where('restaurant_role !=', 'owner')
            ->countAllResults();

        if ($remaining === 0) {
            $this->db->query(
                "DELETE ur FROM wf_user_roles ur JOIN wf_roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.code = 'restaurant_staff'",
                [$userId],
            );
        }

        $this->audit->log('restaurant.staff_removed', $actorId, 'restaurant', $restaurantId, ['member_user_id' => $userId]);

        $this->db->transComplete();
    }

    /**
     * @return array<string, mixed>
     */
    private function editable(int $restaurantId, int $actorId, int $memberId): array
    {
        $member = $this->query($restaurantId)->where('ru.id', $memberId)->get()->getRowArray()
            ?? throw ApiException::notFound('Membre introuvable.');

        if ($member['restaurant_role'] === 'owner') {
            throw ApiException::forbidden('Le propriétaire du restaurant ne peut pas être modifié ici.', 'OWNER_PROTECTED');
        }

        if ((int) $member['user_id'] === $actorId) {
            throw ApiException::forbidden('Vous ne pouvez pas modifier votre propre accès.', 'SELF_MODIFICATION');
        }

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    private function member(int $restaurantId, int $memberId): array
    {
        return self::present($this->query($restaurantId)->where('ru.id', $memberId)->get()->getRowArray());
    }

    private function query(int $restaurantId): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('wf_restaurant_users ru')
            ->select('ru.id, ru.user_id, ru.restaurant_role, ru.status, ru.created_at, u.first_name, u.last_name, u.phone')
            ->join('wf_users u', 'u.id = ru.user_id')
            ->where('ru.restaurant_id', $restaurantId);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'user'       => [
                'id'         => (int) $row['user_id'],
                'first_name' => $row['first_name'],
                'last_name'  => $row['last_name'],
                'phone'      => $row['phone'],
            ],
            'role'       => $row['restaurant_role'],
            'status'     => $row['status'],
            'created_at' => UserPresenter::isoDate($row['created_at']),
        ];
    }
}
