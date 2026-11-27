<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Modules\Restaurants\Models\RestaurantModel;
use App\Modules\Users\Models\UserModel;
use CodeIgniter\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\DatabaseTestCase;

/**
 * Vérifie que chaque modèle correspond exactement à sa table.
 *
 * @internal
 */
final class ModelSchemaTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{class-string<Model>}>
     */
    public static function provideModels(): iterable
    {
        foreach (glob(APPPATH . 'Modules/*/Models/*Model.php') ?: [] as $file) {
            $module = basename(dirname($file, 2));
            $class  = 'App\\Modules\\' . $module . '\\Models\\' . basename($file, '.php');

            yield $class => [$class];
        }
    }

    public function testEveryReferenceTableHasAModel(): void
    {
        $tables = [];

        foreach (self::provideModels() as [$class]) {
            $tables[] = (new $class())->table;
        }

        $schemaTables = array_column($this->db->query(
            "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'wf\\_%'",
        )->getResultArray(), 't');

        // Chaque table WapiFood (référence + migrations additives) a exactement un modèle.
        $this->assertCount(count($tables), array_unique($tables));
        $this->assertEqualsCanonicalizing($schemaTables, $tables);
    }

    /**
     * @param class-string<Model> $class
     */
    #[DataProvider('provideModels')]
    public function testModelFieldsExistInTable(string $class): void
    {
        $model  = new $class();
        $fields = $this->db->getFieldNames($model->table);

        $this->assertNotEmpty($fields, "Table {$model->table} introuvable");
        $this->assertContains($model->primaryKey, $fields);

        foreach ($model->allowedFields as $field) {
            $this->assertContains($field, $fields, "{$class}::\$allowedFields contient « {$field} » absent de {$model->table}");
        }

        $casts = (new ReflectionProperty($model, 'casts'))->getValue($model);

        foreach (array_keys($casts) as $field) {
            $this->assertContains($field, $fields, "{$class}::\$casts contient « {$field} » absent de {$model->table}");
        }

        foreach (['createdField', 'updatedField', 'deletedField'] as $property) {
            $column = (new ReflectionProperty($model, $property))->getValue($model);

            if ($column !== '') {
                $this->assertContains($column, $fields, "{$class}::\${$property} = « {$column} » absent de {$model->table}");
            }
        }
    }

    public function testRoundTripKeepsDecimalsAsStringsAndCastsBooleans(): void
    {
        $users  = new UserModel();
        $userId = (int) $users->insert([
            'first_name'    => 'Test',
            'phone'         => $this->uniquePhone(),
            'password_hash' => 'not-a-real-hash',
            'status'        => 'active',
        ]);

        $restaurants  = new RestaurantModel();
        $restaurantId = (int) $restaurants->insert([
            'owner_user_id'        => $userId,
            'name'                 => 'Chez Test',
            'slug'                 => 'chez-test-' . bin2hex(random_bytes(4)),
            'phone'                => $this->uniquePhone(),
            'commission_rate'      => '12.50',
            'minimum_order_amount' => '5.00',
            'accepts_pickup'       => false,
        ]);

        $restaurant = $restaurants->find($restaurantId);

        $this->assertSame($restaurantId, $restaurant['id']);
        $this->assertSame($userId, $restaurant['owner_user_id']);
        $this->assertSame('12.50', $restaurant['commission_rate']);
        $this->assertSame('5.00', $restaurant['minimum_order_amount']);
        $this->assertTrue($restaurant['accepts_delivery']);
        $this->assertFalse($restaurant['accepts_pickup']);
        $this->assertSame('pending', $restaurant['status']);

        // Horodatage écrit par PHP en UTC = horloge MySQL de session (UTC).
        $this->assertEqualsWithDelta(time(), strtotime($restaurant['created_at'] . ' UTC'), 5);
    }

    public function testSoftDeleteKeepsRow(): void
    {
        $users  = new UserModel();
        $userId = (int) $users->insert([
            'first_name'    => 'Supprimé',
            'phone'         => $this->uniquePhone(),
            'password_hash' => 'not-a-real-hash',
        ]);

        $users->delete($userId);

        $this->assertNull($users->find($userId));
        $this->assertNotNull($users->withDeleted()->find($userId));
    }
}
