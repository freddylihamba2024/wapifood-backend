<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Common\Support\Money;
use App\Common\Support\Slug;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Libraries\Upload\UploadService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Menus\Services\CategoryService;
use App\Modules\Products\Models\ProductImageModel;
use App\Modules\Products\Models\ProductModel;
use App\Modules\Products\Presenters\ProductPresenter;
use App\Modules\Products\Repositories\ProductRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Produits du catalogue d'un restaurant, avec variantes, groupes d'options et images.
 *
 * - Un produit appartient au seul restaurant du contexte ; sa devise est celle du restaurant.
 * - « variants » et « option_groups » envoyés à la mise à jour REMPLACENT l'existant
 *   (éléments avec « id » : mis à jour ; sans « id » : créés ; absents : supprimés).
 *   S'ils ne sont pas envoyés, l'existant est conservé. Les commandes passées n'en dépendent pas
 *   (snapshot dans wf_order_items).
 * - La suppression est logique (deleted_at) : l'historique des commandes reste intact.
 */
final class ProductService
{
    public const MAX_VARIANTS      = 30;
    public const MAX_OPTION_GROUPS = 20;
    public const MAX_OPTIONS       = 50;
    public const MAX_IMAGES        = 10;

    private BaseConnection $db;

    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly ProductImageModel $images = new ProductImageModel(),
        private readonly ProductRepository $repository = new ProductRepository(),
        private readonly UploadService $uploads = new UploadService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param array<string, mixed> $filters category_id, q, featured, available
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function list(int $restaurantId, array $filters, Pagination $pagination, bool $publicOnly): array
    {
        [$rows, $meta] = $this->repository->search($restaurantId, $filters, $pagination, $publicOnly);
        $details       = $this->repository->details(array_map(static fn (array $r): int => (int) $r['id'], $rows), $publicOnly);

        return [array_map(static fn (array $r): array => ProductPresenter::present($r, $details[(int) $r['id']], ! $publicOnly), $rows), $meta];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $restaurantId, int $productId, bool $publicOnly): array
    {
        $row = $this->repository->find($restaurantId, $productId, $publicOnly) ?? throw ApiException::notFound('Produit introuvable.');

        return ProductPresenter::present($row, $this->repository->details([$productId], $publicOnly)[$productId], ! $publicOnly);
    }

    /**
     * @param array<string, mixed> $restaurant
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(array $restaurant, int $userId, array $input): array
    {
        $restaurantId = (int) $restaurant['id'];
        $data         = InputValidator::validate($input, self::rules(true));
        $columns      = $this->columns($restaurantId, $data);
        $basePrice    = Money::toCents($columns['base_price']);
        $variants     = array_key_exists('variants', $input) ? $this->validateVariants($input['variants'], $basePrice, []) : [];
        $groups       = array_key_exists('option_groups', $input) ? $this->validateGroups($input['option_groups'], []) : [];

        $this->assertSkuAvailable($restaurantId, $columns['sku'] ?? null, null);

        $this->db->transException(true)->transStart();

        $productId = (int) $this->products->insert($columns + [
            'restaurant_id' => $restaurantId,
            'currency'      => $restaurant['currency'],
            'slug'          => Slug::unique($columns['name'], fn (string $slug): bool => $this->repository->slugExists($restaurantId, $slug)),
            'sort_order'    => $this->repository->nextSortOrder($restaurantId),
        ]);

        $this->syncVariants($productId, $variants);
        $this->syncGroups($productId, $groups);
        $this->audit->log('product.created', $userId, 'product', $productId, ['restaurant_id' => $restaurantId]);

        $this->db->transComplete();

        return $this->show($restaurantId, $productId, false);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(int $restaurantId, int $userId, int $productId, array $input): array
    {
        $product   = $this->owned($restaurantId, $productId);
        $data      = InputValidator::validate($input, self::rules(false));
        $columns   = $this->columns($restaurantId, $data);
        $basePrice = Money::toCents($columns['base_price'] ?? $product['base_price']);
        $existing  = $this->repository->details([$productId], false)[$productId];

        $variants = array_key_exists('variants', $input)
            ? $this->validateVariants($input['variants'], $basePrice, array_map('intval', array_column($existing['variants'], 'id')))
            : null;

        // Un nouveau prix de base ne doit pas rendre une variante existante négative.
        if ($variants === null && isset($columns['base_price'])) {
            $this->validateVariants(array_map(static fn (array $v): array => ['id' => (int) $v['id'], 'name' => $v['name'], 'price_delta' => $v['price_delta']], $existing['variants']), $basePrice, array_map('intval', array_column($existing['variants'], 'id')));
        }

        $groups = array_key_exists('option_groups', $input)
            ? $this->validateGroups($input['option_groups'], $this->existingGroupMap($existing['option_groups']))
            : null;

        if (array_key_exists('sku', $columns)) {
            $this->assertSkuAvailable($restaurantId, $columns['sku'], $productId);
        }

        $this->db->transException(true)->transStart();

        if ($columns !== []) {
            $this->products->update($productId, $columns);
        }

        if ($variants !== null) {
            $this->syncVariants($productId, $variants);
        }

        if ($groups !== null) {
            $this->syncGroups($productId, $groups);
        }

        $this->audit->log('product.updated', $userId, 'product', $productId, ['fields' => array_keys($columns + array_filter(['variants' => $variants, 'option_groups' => $groups], static fn ($v): bool => $v !== null))]);

        $this->db->transComplete();

        return $this->show($restaurantId, (int) $product['id'], false);
    }

    public function delete(int $restaurantId, int $userId, int $productId): void
    {
        $this->owned($restaurantId, $productId);
        $this->products->delete($productId);
        $this->audit->log('product.deleted', $userId, 'product', $productId, ['restaurant_id' => $restaurantId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setAvailability(int $restaurantId, int $userId, int $productId, mixed $available): array
    {
        if (! (new WapiRules())->wf_boolean($available)) {
            throw ApiException::validation(['is_available' => 'Le champ disponible doit être un booléen (true ou false).']);
        }

        $this->owned($restaurantId, $productId);
        $this->products->update($productId, ['is_available' => WapiRules::toBool($available)]);
        $this->audit->log('product.availability_changed', $userId, 'product', $productId, ['is_available' => WapiRules::toBool($available)]);

        return $this->show($restaurantId, $productId, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function addImage(int $restaurantId, int $productId, ?UploadedFile $file, ?string $altText): array
    {
        $product = $this->owned($restaurantId, $productId);
        $count   = $this->images->where('product_id', $productId)->countAllResults();

        if ($count >= self::MAX_IMAGES) {
            throw ApiException::conflict('Nombre maximal d\'images atteint (' . self::MAX_IMAGES . ').', [], 'IMAGE_LIMIT_REACHED');
        }

        $altText = $altText === null ? null : mb_substr(trim($altText), 0, 180);
        $path    = $this->uploads->storeImage($file, 'product-images', 'image');

        $this->db->transException(true)->transStart();
        $this->images->insert(['product_id' => $productId, 'image_url' => $path, 'alt_text' => $altText ?: null, 'sort_order' => $count]);

        if ($product['image_url'] === null) {
            $this->products->update($productId, ['image_url' => $path]);
        }

        $this->db->transComplete();

        return $this->show($restaurantId, $productId, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteImage(int $restaurantId, int $productId, int $imageId): array
    {
        $product = $this->owned($restaurantId, $productId);
        $image   = $this->images->where('product_id', $productId)->find($imageId) ?? throw ApiException::notFound('Image introuvable.');

        $this->db->transException(true)->transStart();
        $this->images->delete($imageId);

        if ($product['image_url'] === $image['image_url']) {
            $next = $this->images->where('product_id', $productId)->orderBy('sort_order', 'ASC')->first();
            $this->products->update($productId, ['image_url' => $next['image_url'] ?? null]);
        }

        $this->db->transComplete();
        $this->uploads->delete($image['image_url']);

        return $this->show($restaurantId, $productId, false);
    }

    /**
     * Produit du restaurant (non supprimé), sinon 404.
     *
     * @return array<string, mixed>
     */
    private function owned(int $restaurantId, int $productId): array
    {
        return $this->repository->find($restaurantId, $productId, false) ?? throw ApiException::notFound('Produit introuvable.');
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'permit_empty';

        return [
            'category_id'         => ['label' => 'catégorie', 'rules' => 'permit_empty|wf_int|greater_than[0]'],
            'name'                => ['label' => 'nom', 'rules' => "{$required}|string|min_length[1]|max_length[180]"],
            'description'         => ['label' => 'description', 'rules' => 'permit_empty|string|max_length[5000]'],
            'sku'                 => ['label' => 'référence (SKU)', 'rules' => 'permit_empty|string|max_length[80]|regex_match[/\A[A-Za-z0-9._-]+\z/]'],
            'base_price'          => ['label' => 'prix', 'rules' => "{$required}|wf_money"],
            'preparation_minutes' => ['label' => 'temps de préparation', 'rules' => 'permit_empty|wf_int|greater_than_equal_to[1]|less_than_equal_to[600]'],
            'is_available'        => ['label' => 'disponible', 'rules' => 'permit_empty|wf_boolean'],
            'is_featured'         => ['label' => 'mis en avant', 'rules' => 'permit_empty|wf_boolean'],
            'sort_order'          => ['label' => 'ordre', 'rules' => 'permit_empty|wf_int|greater_than_equal_to[0]'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function columns(int $restaurantId, array $data): array
    {
        $columns = [];

        if (array_key_exists('category_id', $data)) {
            if ($data['category_id'] === null || $data['category_id'] === '') {
                $columns['category_id'] = null;
            } else {
                try {
                    (new CategoryService())->owned($restaurantId, (int) $data['category_id']);
                } catch (ApiException) {
                    throw ApiException::validation(['category_id' => 'Cette catégorie n\'existe pas dans votre restaurant.']);
                }

                $columns['category_id'] = (int) $data['category_id'];
            }
        }

        if (($data['name'] ?? '') !== '') {
            $columns['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('description', $data)) {
            $columns['description'] = ($data['description'] ?? '') === '' ? null : trim((string) $data['description']);
        }

        if (array_key_exists('sku', $data)) {
            $columns['sku'] = ($data['sku'] ?? '') === '' ? null : (string) $data['sku'];
        }

        if (isset($data['base_price']) && $data['base_price'] !== '') {
            $columns['base_price'] = Money::normalize(WapiRules::numericString($data['base_price']));
        }

        if (array_key_exists('preparation_minutes', $data)) {
            $columns['preparation_minutes'] = ($data['preparation_minutes'] ?? '') === '' ? null : (int) $data['preparation_minutes'];
        }

        foreach (['is_available', 'is_featured'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $columns[$field] = WapiRules::toBool($data[$field]);
            }
        }

        if (isset($data['sort_order']) && $data['sort_order'] !== '') {
            $columns['sort_order'] = (int) $data['sort_order'];
        }

        return $columns;
    }

    private function assertSkuAvailable(int $restaurantId, ?string $sku, ?int $exceptProductId): void
    {
        if ($sku === null) {
            return;
        }

        $builder = $this->db->table('wf_products')->where('restaurant_id', $restaurantId)->where('sku', $sku);

        if ($exceptProductId !== null) {
            $builder->where('id !=', $exceptProductId);
        }

        if ($builder->countAllResults() > 0) {
            throw ApiException::conflict('Cette référence (SKU) est déjà utilisée dans votre catalogue.', ['sku' => 'Déjà utilisée.'], 'SKU_TAKEN');
        }
    }

    /**
     * @param list<int> $existingIds
     *
     * @return list<array<string, mixed>>
     */
    private function validateVariants(mixed $variants, int $basePriceCents, array $existingIds): array
    {
        if (! is_array($variants) || ! array_is_list($variants) || count($variants) > self::MAX_VARIANTS) {
            throw ApiException::validation(['variants' => 'Le champ variants doit être une liste de ' . self::MAX_VARIANTS . ' éléments au maximum.']);
        }

        $errors = [];
        $clean  = [];

        foreach ($variants as $index => $variant) {
            $prefix = "variants.{$index}.";

            if (! is_array($variant)) {
                $errors["variants.{$index}"] = 'Variante invalide.';

                continue;
            }

            $fieldErrors = InputValidator::errors($variant, [
                'id'          => ['label' => 'id', 'rules' => 'permit_empty|wf_int'],
                'name'        => ['label' => 'nom', 'rules' => 'required|string|max_length[120]'],
                'price_delta' => ['label' => 'écart de prix', 'rules' => 'permit_empty|wf_money_signed'],
                'is_active'   => ['label' => 'active', 'rules' => 'permit_empty|wf_boolean'],
            ], $prefix);

            if ($fieldErrors !== []) {
                $errors += $fieldErrors;

                continue;
            }

            $id    = isset($variant['id']) && $variant['id'] !== '' ? (int) $variant['id'] : null;
            $delta = Money::toCents(WapiRules::numericString($variant['price_delta'] ?? '0'));

            if ($id !== null && ! in_array($id, $existingIds, true)) {
                $errors[$prefix . 'id'] = 'Cette variante n\'appartient pas à ce produit.';
            } elseif ($basePriceCents + $delta < 0) {
                $errors[$prefix . 'price_delta'] = 'Le prix de la variante (prix de base + écart) ne peut pas être négatif.';
            } else {
                $clean[] = [
                    'id'          => $id,
                    'name'        => trim((string) $variant['name']),
                    'price_delta' => Money::fromCents($delta),
                    'is_active'   => ! isset($variant['is_active']) || $variant['is_active'] === '' || WapiRules::toBool($variant['is_active']),
                ];
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $clean;
    }

    /**
     * @param array<int, list<int>> $existing Identifiants des options par groupe existant
     *
     * @return list<array<string, mixed>>
     */
    private function validateGroups(mixed $groups, array $existing): array
    {
        if (! is_array($groups) || ! array_is_list($groups) || count($groups) > self::MAX_OPTION_GROUPS) {
            throw ApiException::validation(['option_groups' => 'Le champ option_groups doit être une liste de ' . self::MAX_OPTION_GROUPS . ' groupes au maximum.']);
        }

        $errors = [];
        $clean  = [];

        foreach ($groups as $g => $group) {
            $prefix = "option_groups.{$g}.";

            if (! is_array($group)) {
                $errors["option_groups.{$g}"] = 'Groupe d\'options invalide.';

                continue;
            }

            $fieldErrors = InputValidator::errors($group, [
                'id'             => ['label' => 'id', 'rules' => 'permit_empty|wf_int'],
                'name'           => ['label' => 'nom', 'rules' => 'required|string|max_length[120]'],
                'selection_type' => ['label' => 'type de sélection', 'rules' => 'permit_empty|in_list[single,multiple]'],
                'is_required'    => ['label' => 'obligatoire', 'rules' => 'permit_empty|wf_boolean'],
                'min_selections' => ['label' => 'sélections minimum', 'rules' => 'permit_empty|wf_int|greater_than_equal_to[0]|less_than_equal_to[50]'],
                'max_selections' => ['label' => 'sélections maximum', 'rules' => 'permit_empty|wf_int|greater_than_equal_to[1]|less_than_equal_to[50]'],
                'options'        => ['label' => 'options', 'rules' => 'required|wf_list'],
            ], $prefix);

            if ($fieldErrors !== []) {
                $errors += $fieldErrors;

                continue;
            }

            $id       = isset($group['id']) && $group['id'] !== '' ? (int) $group['id'] : null;
            $type     = $group['selection_type'] ?? 'single';
            $required = isset($group['is_required']) && $group['is_required'] !== '' && WapiRules::toBool($group['is_required']);
            $min      = isset($group['min_selections']) && $group['min_selections'] !== '' ? (int) $group['min_selections'] : ($required ? 1 : 0);
            $max      = $type === 'single' ? 1 : (isset($group['max_selections']) && $group['max_selections'] !== '' ? (int) $group['max_selections'] : max(1, count($group['options'])));

            if ($id !== null && ! array_key_exists($id, $existing)) {
                $errors[$prefix . 'id'] = 'Ce groupe d\'options n\'appartient pas à ce produit.';

                continue;
            }

            if ($required && $min < 1) {
                $min = 1;
            }

            if ($min > $max) {
                $errors[$prefix . 'min_selections'] = 'Le minimum de sélections ne peut pas dépasser le maximum.';

                continue;
            }

            $options = $this->validateOptions($group['options'], $prefix, $id === null ? [] : $existing[$id], $errors);

            $active = count(array_filter($options, static fn (array $o): bool => $o['is_active']));

            if ($options !== [] && $active < $min) {
                $errors[$prefix . 'options'] = "Le groupe doit proposer au moins {$min} option(s) active(s).";
            }

            $clean[] = [
                'id'             => $id,
                'name'           => trim((string) $group['name']),
                'selection_type' => $type,
                'is_required'    => $min > 0,
                'min_selections' => $min,
                'max_selections' => $max,
                'options'        => $options,
            ];
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $clean;
    }

    /**
     * @param list<mixed>           $options
     * @param list<int>             $existingIds
     * @param array<string, string> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function validateOptions(array $options, string $groupPrefix, array $existingIds, array &$errors): array
    {
        if ($options === [] || count($options) > self::MAX_OPTIONS) {
            $errors[$groupPrefix . 'options'] = 'Un groupe doit contenir entre 1 et ' . self::MAX_OPTIONS . ' options.';

            return [];
        }

        $clean = [];

        foreach ($options as $o => $option) {
            $prefix = "{$groupPrefix}options.{$o}.";

            if (! is_array($option)) {
                $errors[rtrim($prefix, '.')] = 'Option invalide.';

                continue;
            }

            $fieldErrors = InputValidator::errors($option, [
                'id'          => ['label' => 'id', 'rules' => 'permit_empty|wf_int'],
                'name'        => ['label' => 'nom', 'rules' => 'required|string|max_length[120]'],
                'price_delta' => ['label' => 'supplément', 'rules' => 'permit_empty|wf_money'],
                'is_active'   => ['label' => 'active', 'rules' => 'permit_empty|wf_boolean'],
            ], $prefix);

            if ($fieldErrors !== []) {
                $errors += $fieldErrors;

                continue;
            }

            $id = isset($option['id']) && $option['id'] !== '' ? (int) $option['id'] : null;

            if ($id !== null && ! in_array($id, $existingIds, true)) {
                $errors[$prefix . 'id'] = 'Cette option n\'appartient pas à ce groupe.';

                continue;
            }

            $clean[] = [
                'id'          => $id,
                'name'        => trim((string) $option['name']),
                'price_delta' => Money::normalize(WapiRules::numericString($option['price_delta'] ?? '0')),
                'is_active'   => ! isset($option['is_active']) || $option['is_active'] === '' || WapiRules::toBool($option['is_active']),
            ];
        }

        return $clean;
    }

    /**
     * @param list<array<string, mixed>> $variants
     */
    private function syncVariants(int $productId, array $variants): void
    {
        $keep = array_values(array_filter(array_column($variants, 'id')));
        $this->deleteMissing('wf_product_variants', 'product_id', $productId, $keep);

        foreach ($variants as $variant) {
            $row = ['name' => $variant['name'], 'price_delta' => $variant['price_delta'], 'is_active' => (int) $variant['is_active']];

            if ($variant['id'] === null) {
                $this->db->table('wf_product_variants')->insert($row + ['product_id' => $productId]);
            } else {
                $this->db->table('wf_product_variants')->where('id', $variant['id'])->where('product_id', $productId)->update($row);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    private function syncGroups(int $productId, array $groups): void
    {
        $keep = array_values(array_filter(array_column($groups, 'id')));
        $this->deleteMissing('wf_product_option_groups', 'product_id', $productId, $keep);

        foreach ($groups as $group) {
            $row = [
                'name'           => $group['name'],
                'selection_type' => $group['selection_type'],
                'is_required'    => (int) $group['is_required'],
                'min_selections' => $group['min_selections'],
                'max_selections' => $group['max_selections'],
            ];

            if ($group['id'] === null) {
                $this->db->table('wf_product_option_groups')->insert($row + ['product_id' => $productId]);
                $groupId = (int) $this->db->insertID();
            } else {
                $groupId = $group['id'];
                $this->db->table('wf_product_option_groups')->where('id', $groupId)->where('product_id', $productId)->update($row);
            }

            $keepOptions = array_values(array_filter(array_column($group['options'], 'id')));
            $this->deleteMissing('wf_product_options', 'option_group_id', $groupId, $keepOptions);

            foreach ($group['options'] as $option) {
                $optionRow = ['name' => $option['name'], 'price_delta' => $option['price_delta'], 'is_active' => (int) $option['is_active']];

                if ($option['id'] === null) {
                    $this->db->table('wf_product_options')->insert($optionRow + ['option_group_id' => $groupId]);
                } else {
                    $this->db->table('wf_product_options')->where('id', $option['id'])->where('option_group_id', $groupId)->update($optionRow);
                }
            }
        }
    }

    /**
     * @param list<int> $keepIds
     */
    private function deleteMissing(string $table, string $parentColumn, int $parentId, array $keepIds): void
    {
        $builder = $this->db->table($table)->where($parentColumn, $parentId);

        if ($keepIds !== []) {
            $builder->whereNotIn('id', $keepIds);
        }

        $builder->delete();
    }

    /**
     * @param list<array<string, mixed>> $groups
     *
     * @return array<int, list<int>>
     */
    private function existingGroupMap(array $groups): array
    {
        $map = [];

        foreach ($groups as $group) {
            $map[(int) $group['id']] = array_map(static fn (array $o): int => (int) $o['id'], $group['options']);
        }

        return $map;
    }
}
