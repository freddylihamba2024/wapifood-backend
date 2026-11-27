<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Seeds\DatabaseSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base des tests utilisant la base MySQL `wapifood_test` (groupe `tests`).
 *
 * Pour chaque classe de test : rollback puis migration complète des migrations App,
 * puis chargement des données de référence. Ne touche jamais la base de développement.
 *
 * @internal
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = true;
    protected $namespace   = 'App';
    protected $seed        = DatabaseSeeder::class;
    protected $seedOnce    = true;

    protected function setUp(): void
    {
        parent::setUp();

        $database = $this->db->getDatabase();

        if (! str_ends_with($database, '_test')) {
            $this->fail("Refus d'exécuter les tests sur la base « {$database} » : le nom doit finir par _test.");
        }
    }

    /**
     * Remplace le reset #[AfterClass] de DatabaseTestTrait : la base de test n'est
     * recréée qu'une fois par exécution de PHPUnit, et non pour chaque classe.
     * Recréer les 31 tables à chaque classe multiplie les fichiers ouverts côté MySQL
     * (MySQL 5.7 sous macOS refuse les connexions au-delà de 1024 descripteurs).
     * Chaque test isole ses données (identifiants uniques ou nettoyage ciblé).
     */
    public static function resetMigrationSeedCount(): void
    {
    }

    /**
     * Vide les tables indiquées (DELETE, pas TRUNCATE : sous InnoDB, TRUNCATE recrée le fichier de la table).
     *
     * @param list<string> $tables
     */
    protected function emptyTables(array $tables): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $this->db->table($table)->emptyTable();
            }
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** Numéro de téléphone RDC unique pour éviter les collisions entre tests. */
    protected function uniquePhone(): string
    {
        return '+2438' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
    }
}
