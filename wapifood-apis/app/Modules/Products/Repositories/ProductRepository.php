<?php

declare(strict_types=1);

namespace App\Modules\Products\Repositories;

use App\Common\Http\Pagination;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Lecture des produits et de leurs éléments associés (chargés par lot, sans requête N+1).
 *
 * Vue publique : produit non supprimé, disponible, dans une catégorie active (ou sans catégorie).
 */
final class ProductRepository
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $restaurantId, int $productId, bool $publicOnly): ?array
    {
        return $this->base($restaurantId, $publicOnly)->where('p.id', $productId)->get()->getRowArray();
    }

    /**
     * Produits commandables (vue publique) parmi $productIds, avec variantes et options actives.
     * Un produit d'un autre restaurant, supprimé ou indisponible est absent du résultat.
     *
     * @param list<int> $productIds
     *
     * @return array<int, array<string, mixed>> Produits par id, détails dans la clé « details »
     */
    public function findOrderable(int $restaurantId, array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));

        if ($productIds === []) {
            return [];
        }

        $rows     = $this->base($restaurantId, true)->whereIn('p.id', $productIds)->get()->getResultArray();
        $details  = $this->details(array_map(static fn (array $r): int => (int) $r['id'], $rows), true);
        $products = [];

        foreach ($rows as $row) {
            $products[(int) $row['id']] = $row + ['details' => $details[(int) $row['id']]];
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function search(int $restaurantId, array $filters, Pagination $pagination, bool $publicOnly): array
    {
        $builder = $this->base($restaurantId, $publicOnly);

        if (isset($filters['category_id'])) {
            $builder->where('p.category_id', (int) $filters['category_id']);
        }

        if (($filters['q'] ?? '') !== '') {
            $builder->groupStart()->like('p.name', (string) $filters['q'])->orLike('p.description', (string) $filters['q'])->groupEnd();
        }

        if (($filters['featured'] ?? null) === true) {
            $builder->where('p.is_featured', 1);
        }

        if (! $publicOnly && isset($filters['available'])) {
            $builder->where('p.is_available', $filters['available'] ? 1 : 0);
        }

        $builder->orderBy('c.sort_order IS NULL', 'ASC', false)
            ->orderBy('c.sort_order', 'ASC')
            ->orderBy('p.sort_order', 'ASC')
            ->orderBy('p.id', 'ASC');

        return $pagination->run($builder);
    }

    /**
     * Variantes, groupes d'options (avec options) et images, par produit.
     *
     * @param list<int> $productIds
     *
     * @return array<int, array{variants: list<array<string, mixed>>, option_groups: list<array<string, mixed>>, images: list<array<string, mixed>>}>
     */
    public function details(array $productIds, bool $activeOnly): array
    {
        $result = [];

        foreach ($productIds as $id) {
            $result[$id] = ['variants' => [], 'option_groups' => [], 'images' => []];
        }

        if ($productIds === []) {
            return $result;
        }

        $variants = $this->db->table('wf_product_variants')->whereIn('product_id', $productIds)->orderBy('id', 'ASC');

        if ($activeOnly) {
            $variants->where('is_active', 1);
        }

        foreach ($variants->get()->getResultArray() as $row) {
            $result[(int) $row['product_id']]['variants'][] = $row;
        }

        $groups  = $this->db->table('wf_product_option_groups')->whereIn('product_id', $productIds)->orderBy('id', 'ASC')->get()->getResultArray();
        $options = [];

        if ($groups !== []) {
            $optionQuery = $this->db->table('wf_product_options')->whereIn('option_group_id', array_column($groups, 'id'))->orderBy('id', 'ASC');

            if ($activeOnly) {
                $optionQuery->where('is_active', 1);
            }

            foreach ($optionQuery->get()->getResultArray() as $row) {
                $options[(int) $row['option_group_id']][] = $row;
            }
        }

        foreach ($groups as $group) {
            $group['options'] = $options[(int) $group['id']] ?? [];

            // En vue publique, un groupe sans option active n'est pas proposé.
            if ($activeOnly && $group['options'] === []) {
                continue;
            }

            $result[(int) $group['product_id']]['option_groups'][] = $group;
        }

        $images = $this->db->table('wf_product_images')->whereIn('product_id', $productIds)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');

        foreach ($images->get()->getResultArray() as $row) {
            $result[(int) $row['product_id']]['images'][] = $row;
        }

        return $result;
    }

    /** Un slug reste réservé même par un produit supprimé (contrainte d'unicité). */
    public function slugExists(int $restaurantId, string $slug): bool
    {
        return $this->db->table('wf_products')->where('restaurant_id', $restaurantId)->where('slug', $slug)->countAllResults() > 0;
    }

    public function nextSortOrder(int $restaurantId): int
    {
        $row = $this->db->table('wf_products')->selectMax('sort_order', 'max_order')->where('restaurant_id', $restaurantId)->get()->getRow();

        return (int) ($row->max_order ?? -1) + 1;
    }

    private function base(int $restaurantId, bool $publicOnly): BaseBuilder
    {
        $builder = $this->db->table('wf_products p')
            ->select('p.*, c.name AS category_name, c.is_active AS category_active')
            ->join('wf_menu_categories c', 'c.id = p.category_id', 'left')
            ->where('p.restaurant_id', $restaurantId)
            ->where('p.deleted_at', null);

        if ($publicOnly) {
            $builder->where('p.is_available', 1)
                ->groupStart()->where('p.category_id', null)->orWhere('c.is_active', 1)->groupEnd();
        }

        return $builder;
    }
}
