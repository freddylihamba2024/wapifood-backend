<?php

declare(strict_types=1);

namespace App\Modules\Orders\Repositories;

use App\Common\Http\Pagination;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Lecture des commandes, toujours restreinte à un client ou à un restaurant.
 * Les collections associées (lignes, historique, paiement) sont chargées par lot.
 */
final class OrderRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Accès sans restriction de périmètre : réservé à l'administration et aux traitements internes.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $orderId): ?array
    {
        return $this->base()->where('o.id', $orderId)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForCustomer(int $customerId, int $orderId): ?array
    {
        return $this->base()->where('o.customer_id', $customerId)->where('o.id', $orderId)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForRestaurant(int $restaurantId, int $orderId): ?array
    {
        return $this->base()->where('o.restaurant_id', $restaurantId)->where('o.id', $orderId)->get()->getRowArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(int $customerId, string $key): ?array
    {
        return $this->base()->where('o.customer_id', $customerId)->where('o.idempotency_key', $key)->get()->getRowArray();
    }

    public function orderNumberExists(string $orderNumber): bool
    {
        return $this->db->table('wf_orders')->where('order_number', $orderNumber)->countAllResults() > 0;
    }

    /**
     * Commandes d'un client, les plus récentes d'abord. Filtre : status (un ou plusieurs, séparés par des virgules).
     *
     * @param array{status?: list<string>} $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function searchForCustomer(int $customerId, array $filters, Pagination $pagination): array
    {
        $builder = $this->base()->where('o.customer_id', $customerId);
        $this->applyStatus($builder, $filters);
        $builder->orderBy('o.created_at', 'DESC')->orderBy('o.id', 'DESC');

        return $pagination->run($builder);
    }

    /**
     * Commandes d'un restaurant. Filtres : status, date_from / date_to (UTC, AAAA-MM-JJ), q (numéro de commande).
     * Tri : les plus anciennes d'abord pour les commandes en cours (file de préparation), sinon les plus récentes.
     *
     * @param array{status?: list<string>, date_from?: string, date_to?: string, q?: string, oldest_first?: bool} $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function searchForRestaurant(int $restaurantId, array $filters, Pagination $pagination): array
    {
        $builder = $this->base()->where('o.restaurant_id', $restaurantId);
        $this->applyStatus($builder, $filters);

        if (($filters['date_from'] ?? '') !== '') {
            $builder->where('o.created_at >=', $filters['date_from'] . ' 00:00:00');
        }

        if (($filters['date_to'] ?? '') !== '') {
            $builder->where('o.created_at <=', $filters['date_to'] . ' 23:59:59');
        }

        if (($filters['q'] ?? '') !== '') {
            $builder->like('o.order_number', (string) $filters['q']);
        }

        $direction = ($filters['oldest_first'] ?? false) ? 'ASC' : 'DESC';
        $builder->orderBy('o.created_at', $direction)->orderBy('o.id', $direction);

        return $pagination->run($builder);
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function itemsFor(array $orderIds): array
    {
        return $this->groupBy('wf_order_items', $orderIds, 'id');
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<int, int> Nombre d'articles (somme des quantités) par commande
     */
    public function itemCountsFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $counts = array_fill_keys($orderIds, 0);
        $rows   = $this->db->table('wf_order_items')
            ->select('order_id, SUM(quantity) AS n')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $counts[(int) $row['order_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyOf(int $orderId): array
    {
        return $this->db->table('wf_order_status_history')
            ->where('order_id', $orderId)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Dernier paiement enregistré pour la commande.
     *
     * @return array<string, mixed>|null
     */
    public function latestPaymentOf(int $orderId): ?array
    {
        return $this->db->table('wf_payments')->where('order_id', $orderId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
    }

    private function base(): BaseBuilder
    {
        return $this->db->table('wf_orders o')
            ->select('o.*, r.name AS restaurant_name, r.slug AS restaurant_slug, r.phone AS restaurant_phone, r.logo_url AS restaurant_logo_url')
            ->join('wf_restaurants r', 'r.id = o.restaurant_id');
    }

    /**
     * @param array{status?: list<string>} $filters
     */
    private function applyStatus(BaseBuilder $builder, array $filters): void
    {
        if (($filters['status'] ?? []) !== []) {
            $builder->whereIn('o.status', $filters['status']);
        }
    }

    /**
     * @param list<int> $orderIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupBy(string $table, array $orderIds, string $orderColumn): array
    {
        if ($orderIds === []) {
            return [];
        }

        $grouped = array_fill_keys($orderIds, []);
        $rows    = $this->db->table($table)->whereIn('order_id', $orderIds)->orderBy($orderColumn, 'ASC')->get()->getResultArray();

        foreach ($rows as $row) {
            $grouped[(int) $row['order_id']][] = $row;
        }

        return $grouped;
    }
}
