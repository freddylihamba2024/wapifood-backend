<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Repositories;

use App\Common\Http\Pagination;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Requêtes de lecture sur les restaurants, leurs adresses et leurs horaires.
 * Les collections associées sont chargées par lot (pas de requête par restaurant).
 */
final class RestaurantRepository
{
    /** Statuts visibles par le public : actif, ou fermé temporairement (affiché comme tel). */
    public const PUBLIC_STATUSES = ['active', 'paused'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->table('wf_restaurants')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublic(int $id): ?array
    {
        return $this->db->table('wf_restaurants')
            ->where('id', $id)
            ->where('deleted_at', null)
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->get()
            ->getRowArray();
    }

    /**
     * Annuaire public : filtres q (nom), city, commune, featured, delivery, pickup.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function searchPublic(array $filters, Pagination $pagination): array
    {
        $builder = $this->db->table('wf_restaurants r')
            ->select('r.*')
            ->where('r.deleted_at', null)
            ->whereIn('r.status', self::PUBLIC_STATUSES);

        if (($filters['q'] ?? '') !== '') {
            $builder->like('r.name', (string) $filters['q']);
        }

        if (($filters['city'] ?? '') !== '' || ($filters['commune'] ?? '') !== '') {
            $builder->whereIn('r.id', static function (BaseBuilder $sub) use ($filters): BaseBuilder {
                $sub->select('restaurant_id')->from('wf_restaurant_addresses');

                if (($filters['city'] ?? '') !== '') {
                    $sub->where('city', (string) $filters['city']);
                }

                if (($filters['commune'] ?? '') !== '') {
                    $sub->where('commune', (string) $filters['commune']);
                }

                return $sub;
            });
        }

        foreach (['featured' => 'r.is_featured', 'delivery' => 'r.accepts_delivery', 'pickup' => 'r.accepts_pickup'] as $filter => $column) {
            if (($filters[$filter] ?? null) === true) {
                $builder->where($column, 1);
            }
        }

        // Restaurants ouverts d'abord (statut actif), mis en avant, puis par nom.
        $builder->orderBy("r.status = 'active'", 'DESC', false)
            ->orderBy('r.is_featured', 'DESC')
            ->orderBy('r.name', 'ASC');

        return $pagination->run($builder);
    }

    /**
     * Liste d'administration : filtres status et q (nom, slug, téléphone).
     *
     * @param array<string, mixed> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function searchAdmin(array $filters, Pagination $pagination): array
    {
        $builder = $this->db->table('wf_restaurants r')
            ->select('r.*, u.first_name AS owner_first_name, u.last_name AS owner_last_name, u.phone AS owner_phone, u.email AS owner_email')
            ->join('wf_users u', 'u.id = r.owner_user_id')
            ->where('r.deleted_at', null);

        if (($filters['status'] ?? '') !== '') {
            $builder->where('r.status', (string) $filters['status']);
        }

        if (($filters['q'] ?? '') !== '') {
            $builder->groupStart()
                ->like('r.name', (string) $filters['q'])
                ->orLike('r.slug', (string) $filters['q'])
                ->orLike('r.phone', (string) $filters['q'])
                ->groupEnd();
        }

        // Les demandes en attente d'abord, les plus anciennes en premier (file de validation).
        $builder->orderBy("r.status = 'pending'", 'DESC', false)->orderBy('r.created_at', 'ASC');

        return $pagination->run($builder);
    }

    /**
     * @param list<int> $restaurantIds
     *
     * @return array<int, list<array<string, mixed>>> Adresses par restaurant (principale en premier)
     */
    public function addressesFor(array $restaurantIds): array
    {
        return $this->groupBy('wf_restaurant_addresses', $restaurantIds, static function (BaseBuilder $builder): void {
            $builder->orderBy('is_primary', 'DESC')->orderBy('id', 'ASC');
        });
    }

    /**
     * @param list<int> $restaurantIds
     *
     * @return array<int, list<array<string, mixed>>> Horaires par restaurant (lundi → dimanche)
     */
    public function hoursFor(array $restaurantIds): array
    {
        return $this->groupBy('wf_restaurant_opening_hours', $restaurantIds, static function (BaseBuilder $builder): void {
            $builder->orderBy('day_of_week', 'ASC');
        });
    }

    public function slugExists(string $slug): bool
    {
        return $this->db->table('wf_restaurants')->where('slug', $slug)->countAllResults() > 0;
    }

    /**
     * @param list<int>             $ids
     * @param callable(BaseBuilder): void $order
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupBy(string $table, array $ids, callable $order): array
    {
        if ($ids === []) {
            return [];
        }

        $builder = $this->db->table($table)->whereIn('restaurant_id', $ids);
        $order($builder);

        $grouped = array_fill_keys($ids, []);

        foreach ($builder->get()->getResultArray() as $row) {
            $grouped[(int) $row['restaurant_id']][] = $row;
        }

        return $grouped;
    }
}
