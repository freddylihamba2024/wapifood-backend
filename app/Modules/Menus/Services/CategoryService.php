<?php

declare(strict_types=1);

namespace App\Modules\Menus\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Libraries\Upload\UploadService;
use App\Modules\Menus\Models\MenuCategoryModel;
use App\Modules\Users\Presenters\UserPresenter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Catégories du menu d'un restaurant. Toute opération est limitée au restaurant du contexte.
 */
final class CategoryService
{
    private BaseConnection $db;

    public function __construct(
        private readonly MenuCategoryModel $categories = new MenuCategoryModel(),
        private readonly UploadService $uploads = new UploadService(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $restaurantId, bool $activeOnly): array
    {
        $builder = $this->categories->where('restaurant_id', $restaurantId);

        if ($activeOnly) {
            $builder->where('is_active', 1);
        }

        $rows   = $builder->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll();
        $counts = $this->productCounts($restaurantId, $activeOnly);

        return array_map(fn (array $row): array => $this->present($row, $counts[(int) $row['id']] ?? 0, ! $activeOnly), $rows);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(int $restaurantId, array $input): array
    {
        $data = InputValidator::validate($input, self::rules(true));

        $nextOrder = (int) ($this->db->table('wf_menu_categories')
            ->selectMax('sort_order', 'max_order')
            ->where('restaurant_id', $restaurantId)
            ->get()->getRow()->max_order ?? -1) + 1;

        $id = (int) $this->categories->insert($this->columns($data) + [
            'restaurant_id' => $restaurantId,
            'sort_order'    => $nextOrder,
            'is_active'     => true,
        ]);

        return $this->show($restaurantId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $restaurantId, int $categoryId): array
    {
        return $this->present($this->owned($restaurantId, $categoryId), $this->productCounts($restaurantId, false)[$categoryId] ?? 0, true);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $restaurantId, int $categoryId, array $input): array
    {
        $this->owned($restaurantId, $categoryId);
        $columns = $this->columns(InputValidator::validate($input, self::rules(false)));

        if ($columns !== []) {
            $this->categories->update($categoryId, $columns);
        }

        return $this->show($restaurantId, $categoryId);
    }

    /**
     * Supprime une catégorie vide. Une catégorie contenant des produits doit être désactivée
     * (is_active = false) ou vidée au préalable.
     */
    public function delete(int $restaurantId, int $categoryId): void
    {
        $category = $this->owned($restaurantId, $categoryId);

        $products = $this->db->table('wf_products')
            ->where('category_id', $categoryId)
            ->where('deleted_at', null)
            ->countAllResults();

        if ($products > 0) {
            throw ApiException::conflict(
                "Cette catégorie contient {$products} produit(s). Déplacez-les ou désactivez la catégorie.",
                ['products' => $products],
                'CATEGORY_NOT_EMPTY',
            );
        }

        $this->db->transException(true)->transStart();
        // Les produits supprimés (logiquement) gardent leur historique mais perdent la catégorie.
        $this->db->table('wf_products')->where('category_id', $categoryId)->update(['category_id' => null]);
        $this->categories->delete($categoryId);
        $this->db->transComplete();

        $this->uploads->delete($category['image_url']);
    }

    /**
     * Nouvel ordre d'affichage : les catégories listées prennent les positions 0, 1, 2…
     *
     * @param mixed $ids
     *
     * @return list<array<string, mixed>>
     */
    public function reorder(int $restaurantId, mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || $ids === []
            || array_filter($ids, static fn ($id): bool => ! is_int($id) || $id < 1) !== []
            || count(array_unique($ids)) !== count($ids)) {
            throw ApiException::validation(['category_ids' => 'Liste d\'identifiants de catégories invalide.']);
        }

        $owned = $this->categories->where('restaurant_id', $restaurantId)->whereIn('id', $ids)->countAllResults();

        if ($owned !== count($ids)) {
            throw ApiException::validation(['category_ids' => 'Une ou plusieurs catégories n\'appartiennent pas à ce restaurant.']);
        }

        $this->db->transException(true)->transStart();

        foreach ($ids as $position => $id) {
            $this->categories->update($id, ['sort_order' => $position]);
        }

        $this->db->transComplete();

        return $this->list($restaurantId, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function replaceImage(int $restaurantId, int $categoryId, ?UploadedFile $file): array
    {
        $category = $this->owned($restaurantId, $categoryId);
        $path     = $this->uploads->storeImage($file, 'category-images', 'image');

        $this->categories->update($categoryId, ['image_url' => $path]);
        $this->uploads->delete($category['image_url']);

        return $this->show($restaurantId, $categoryId);
    }

    /**
     * Vérifie qu'une catégorie appartient au restaurant (sinon 404).
     *
     * @return array<string, mixed>
     */
    public function owned(int $restaurantId, int $categoryId): array
    {
        return $this->categories->where('restaurant_id', $restaurantId)->find($categoryId)
            ?? throw ApiException::notFound('Catégorie introuvable.');
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private static function rules(bool $creating): array
    {
        return [
            'name'        => ['label' => 'nom', 'rules' => ($creating ? 'required' : 'permit_empty') . '|string|min_length[1]|max_length[120]'],
            'description' => ['label' => 'description', 'rules' => 'permit_empty|string|max_length[500]'],
            'is_active'   => ['label' => 'active', 'rules' => 'permit_empty|wf_boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $columns = [];

        if (($data['name'] ?? '') !== '') {
            $columns['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('description', $data)) {
            $columns['description'] = ($data['description'] ?? '') === '' ? null : trim((string) $data['description']);
        }

        if (isset($data['is_active']) && $data['is_active'] !== '') {
            $columns['is_active'] = WapiRules::toBool($data['is_active']);
        }

        return $columns;
    }

    /**
     * @return array<int, int> Nombre de produits par catégorie
     */
    private function productCounts(int $restaurantId, bool $availableOnly): array
    {
        $builder = $this->db->table('wf_products')
            ->select('category_id, COUNT(*) AS n')
            ->where('restaurant_id', $restaurantId)
            ->where('deleted_at', null)
            ->where('category_id IS NOT NULL', null, false)
            ->groupBy('category_id');

        if ($availableOnly) {
            $builder->where('is_available', 1);
        }

        return array_map('intval', array_column($builder->get()->getResultArray(), 'n', 'category_id'));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row, int $productCount, bool $management): array
    {
        $data = [
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'description'   => $row['description'],
            'image_url'     => UploadService::url($row['image_url']),
            'sort_order'    => (int) $row['sort_order'],
            'product_count' => $productCount,
        ];

        if ($management) {
            $data += [
                'is_active'  => (bool) $row['is_active'],
                'created_at' => UserPresenter::isoDate($row['created_at']),
                'updated_at' => UserPresenter::isoDate($row['updated_at']),
            ];
        }

        return $data;
    }
}
