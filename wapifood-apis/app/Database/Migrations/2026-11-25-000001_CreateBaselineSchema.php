<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Schema\BaselineSchema;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * M1 — Schéma de référence WapiFood (31 tables de wapifood_database.sql).
 *
 * - Base vide       : crée les 31 tables, index et clés étrangères à l'identique du script.
 * - Base existante  : `CREATE TABLE IF NOT EXISTS` ne modifie rien ; la migration vérifie
 *                     ensuite que chaque table et chaque colonne attendue existe, puis
 *                     s'enregistre dans la table `migrations`.
 *
 * Le SQL natif est utilisé plutôt que le Forge CI4, qui ne restitue pas fidèlement
 * les ENUM, les CHECK et les noms de contraintes.
 */
class CreateBaselineSchema extends Migration
{
    public function up(): void
    {
        $schema = new BaselineSchema();

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($schema->createStatements() as $statement) {
                $this->db->query($statement);
            }
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->assertSchemaMatches($schema);
    }

    /**
     * Le retour arrière supprimerait toutes les données WapiFood.
     * Il n'est donc autorisé que sur la base de tests automatisés.
     */
    public function down(): void
    {
        if (ENVIRONMENT !== 'testing') {
            throw new RuntimeException(
                'Rollback de la migration baseline refusé hors environnement de test : '
                . 'il supprimerait les 31 tables WapiFood et leurs données.',
            );
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (array_reverse((new BaselineSchema())->tables()) as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->db->escapeIdentifiers($table));
            }
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Échoue si une base existante diverge du schéma de référence (table ou colonne manquante).
     */
    private function assertSchemaMatches(BaselineSchema $schema): void
    {
        // Lecture directe d'information_schema : getFieldNames() met ses résultats en cache.
        $existing = [];
        $rows     = $this->db->query(
            'SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE()',
        )->getResultArray();

        foreach ($rows as $row) {
            $existing[$row['t']][] = $row['c'];
        }

        $missing = [];

        foreach ($schema->columns() as $table => $columns) {
            if (! isset($existing[$table])) {
                $missing[] = $table;

                continue;
            }

            foreach (array_diff($columns, $existing[$table]) as $column) {
                $missing[] = "{$table}.{$column}";
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'La base existante ne correspond pas au schéma de référence WapiFood. Éléments manquants : '
                . implode(', ', $missing),
            );
        }
    }
}
