<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\Migrations\CreateBaselineSchema;
use App\Database\Schema\BaselineSchema;
use App\Database\Seeds\DatabaseSeeder;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class BaselineMigrationTest extends DatabaseTestCase
{
    private function schemaName(): string
    {
        return $this->db->getDatabase();
    }

    public function testMigrationCreatesThe31ReferenceTables(): void
    {
        $tables = $this->db->query(
            "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE 'wf\\_%'",
            [$this->schemaName()],
        )->getResultArray();

        // Les 31 tables de référence sont toutes présentes (les migrations additives en ajoutent d'autres).
        $baseline = (new BaselineSchema())->tables();
        $this->assertCount(31, $baseline);
        $this->assertSame([], array_values(array_diff($baseline, array_column($tables, 'name'))));
    }

    public function testForeignKeysAndEnumsArePreserved(): void
    {
        // Les 44 clés étrangères nommées dans le schéma de référence existent toutes
        // (les migrations additives peuvent en ajouter d'autres, même sur ces tables).
        preg_match_all('/CONSTRAINT (fk_\w+) FOREIGN KEY/', implode("\n", (new BaselineSchema())->statements()), $names);
        $fkCount = (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.referential_constraints WHERE constraint_schema = ? AND constraint_name IN ?',
            [$this->schemaName(), $names[1]],
        )->getRow()->n;

        $this->assertSame(44, $fkCount);

        $orderStatus = $this->db->query(
            "SELECT column_type AS t FROM information_schema.columns WHERE table_schema = ? AND table_name = 'wf_orders' AND column_name = 'status'",
            [$this->schemaName()],
        )->getRow()->t;

        $this->assertSame("enum('pending','accepted','preparing','ready','out_for_delivery','completed','cancelled','rejected')", $orderStatus);
    }

    public function testMigrationIsRecordedInSpark(): void
    {
        $this->seeInDatabase('migrations', ['class' => CreateBaselineSchema::class, 'namespace' => 'App']);
    }

    /**
     * Cas de la base existante importée depuis wapifood_database.sql : la migration ne modifie rien.
     */
    public function testRunningAgainstExistingSchemaIsANoOp(): void
    {
        $before = $this->db->query('SELECT COUNT(*) AS n FROM wf_roles')->getRow()->n;

        // En environnement de test, le groupe par défaut est `tests` (wapifood_test).
        (new CreateBaselineSchema())->up();

        $this->assertSame($before, $this->db->query('SELECT COUNT(*) AS n FROM wf_roles')->getRow()->n);
    }

    public function testDivergentExistingSchemaIsRejected(): void
    {
        $this->db->query('ALTER TABLE wf_reviews DROP COLUMN comment');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('wf_reviews.comment');

            (new CreateBaselineSchema())->up();
        } finally {
            $this->db->query('ALTER TABLE wf_reviews ADD COLUMN comment TEXT NULL AFTER rating');
        }
    }

    /**
     * Le schéma créé par la migration doit être identique à celui créé par le script SQL
     * (base de développement `wapifood`). Seules les métadonnées sont lues, jamais les données.
     */
    public function testMigratedSchemaMatchesTheReferenceSqlScriptDatabase(): void
    {
        $reference = 'wapifood';

        $exists = $this->db->query('SELECT COUNT(*) AS n FROM information_schema.schemata WHERE schema_name = ?', [$reference])->getRow()->n;

        if ((int) $exists === 0) {
            $this->markTestSkipped('Base de référence « wapifood » absente sur ce serveur.');
        }

        $columns = 'SELECT table_name, column_name, ordinal_position, column_type, is_nullable, column_default, extra, collation_name
                    FROM information_schema.columns WHERE table_schema = ? AND table_name LIKE \'wf\\_%\' ORDER BY table_name, ordinal_position';
        $indexes = 'SELECT table_name, index_name, seq_in_index, column_name, non_unique
                    FROM information_schema.statistics WHERE table_schema = ? AND table_name LIKE \'wf\\_%\' ORDER BY table_name, index_name, seq_in_index';
        $fks = 'SELECT k.table_name, k.constraint_name, k.column_name, k.referenced_table_name, k.referenced_column_name, r.delete_rule
                FROM information_schema.key_column_usage k
                JOIN information_schema.referential_constraints r
                  ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                WHERE k.table_schema = ? ORDER BY k.table_name, k.constraint_name';

        // Seul le périmètre du schéma v1 est comparé : les migrations additives (M2+) peuvent
        // ou non avoir été appliquées à la base de référence.
        $baseline = (new BaselineSchema())->columns();
        $sql = implode("\n", (new BaselineSchema())->statements());
        preg_match_all('/CONSTRAINT (\w+) /', $sql, $constraintNames);
        preg_match_all('/(?:UNIQUE KEY|KEY) (\w+)/', $sql, $keyNames);
        // Index du schéma v1 : clé primaire, clés nommées, et index créés par les clés étrangères.
        $indexNames = ['PRIMARY', ...$keyNames[1], ...$constraintNames[1]];
        $filter = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($baseline[$row['table_name']])
                && (! isset($row['ordinal_position']) || in_array($row['column_name'], $baseline[$row['table_name']], true))
                && (! isset($row['constraint_name']) || in_array($row['constraint_name'], $constraintNames[1], true))
                && (! isset($row['index_name']) || in_array($row['index_name'], $indexNames, true)),
        ));
        $strip = static fn (array $rows): array => array_map(static function (array $row): array {
            unset($row['ordinal_position']); // les colonnes ajoutées après coup décalent les positions

            return $row;
        }, $rows);

        foreach (['colonnes' => $columns, 'index' => $indexes, 'clés étrangères' => $fks] as $label => $sql) {
            $this->assertSame(
                $strip($filter($this->db->query($sql, [$reference])->getResultArray())),
                $strip($filter($this->db->query($sql, [$this->schemaName()])->getResultArray())),
                "Divergence de {$label} entre la migration et wapifood_database.sql",
            );
        }
    }

    public function testSeedsAreIdempotentAndNeverOverwriteExistingValues(): void
    {
        $this->db->table('wf_platform_settings')->where('setting_key', 'platform_name')->update(['setting_value' => 'WapiFood RDC']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, $this->db->table('wf_roles')->countAllResults());
        $this->assertSame(4, $this->db->table('wf_platform_settings')->countAllResults());
        $this->seeInDatabase('wf_platform_settings', ['setting_key' => 'platform_name', 'setting_value' => 'WapiFood RDC']);
        $this->seeInDatabase('wf_roles', ['id' => 5, 'code' => 'super_admin']);
    }

    public function testSessionRunsInUtc(): void
    {
        $row = $this->db->query('SELECT @@session.time_zone AS tz, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS diff')->getRow();

        $this->assertSame('+00:00', $row->tz);
        $this->assertSame(0, (int) $row->diff);
    }

    public function testStrictModeRejectsTruncation(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db->table('wf_roles')->insert(['code' => str_repeat('x', 51), 'name' => 'Trop long']);
    }
}
