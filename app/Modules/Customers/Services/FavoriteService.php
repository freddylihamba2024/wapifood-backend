<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Modules\Restaurants\Presenters\RestaurantPresenter;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use App\Modules\Restaurants\Services\OpeningHoursService;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Restaurants favoris d'un client.
 *
 * Ajout et retrait idempotents. Seuls les restaurants visibles publiquement peuvent être ajoutés ;
 * un favori dont le restaurant n'est plus visible (suspendu…) est masqué de la liste sans être supprimé.
 */
final class FavoriteService
{
    private BaseConnection $db;

    public function __construct(
        private readonly RestaurantRepository $restaurants = new RestaurantRepository(),
        private readonly OpeningHoursService $hours = new OpeningHoursService(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function list(int $userId, Pagination $pagination): array
    {
        $builder = $this->db->table('wf_customer_favorites f')
            ->select('r.*, f.created_at AS favorited_at')
            ->join('wf_restaurants r', 'r.id = f.restaurant_id')
            ->where('f.user_id', $userId)
            ->where('r.deleted_at', null)
            ->whereIn('r.status', RestaurantRepository::PUBLIC_STATUSES)
            ->orderBy('f.created_at', 'DESC')
            ->orderBy('f.id', 'DESC');

        [$rows, $meta] = $pagination->run($builder);
        $ids           = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $addresses     = $this->restaurants->addressesFor($ids);
        $hours         = $this->restaurants->hoursFor($ids);
        $now           = new DateTimeImmutable();

        $items = array_map(fn (array $r): array => RestaurantPresenter::summary(
            $r,
            $addresses[(int) $r['id']][0] ?? null,
            $this->hours->isOpenAt($hours[(int) $r['id']], $r['timezone'], $now),
        ), $rows);

        return [$items, $meta];
    }

    public function add(int $userId, int $restaurantId): void
    {
        $this->restaurants->findPublic($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');

        // INSERT IGNORE : un deuxième ajout (ou deux ajouts simultanés) ne crée pas de doublon.
        $this->db->table('wf_customer_favorites')->ignore(true)->insert([
            'user_id'       => $userId,
            'restaurant_id' => $restaurantId,
        ]);
    }

    public function remove(int $userId, int $restaurantId): void
    {
        $this->db->table('wf_customer_favorites')->where('user_id', $userId)->where('restaurant_id', $restaurantId)->delete();
    }

    public function isFavorite(int $userId, int $restaurantId): bool
    {
        return $this->db->table('wf_customer_favorites')->where('user_id', $userId)->where('restaurant_id', $restaurantId)->countAllResults() > 0;
    }
}
