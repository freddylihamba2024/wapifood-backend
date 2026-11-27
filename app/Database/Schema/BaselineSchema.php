<?php

declare(strict_types=1);

namespace App\Database\Schema;

use RuntimeException;

/**
 * Lecture du schéma de référence v1 (baseline_v1.sql).
 *
 * Fournit les instructions CREATE TABLE, la liste ordonnée des tables et les
 * colonnes attendues, utilisées par la migration et par le contrôle de conformité.
 */
final class BaselineSchema
{
    private const FILE = __DIR__ . '/baseline_v1.sql';

    /** @var list<string>|null */
    private ?array $statements = null;

    /**
     * Instructions SQL exécutables, dans l'ordre du fichier (ordre compatible avec les FK).
     *
     * @return list<string>
     */
    public function statements(): array
    {
        if ($this->statements !== null) {
            return $this->statements;
        }

        $sql = file_get_contents(self::FILE);

        if ($sql === false) {
            throw new RuntimeException('Schéma de référence introuvable : ' . self::FILE);
        }

        // Retire les lignes de commentaire SQL (aucune instruction ne contient « ; » dans une chaîne).
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        $this->statements = array_values(array_filter(
            array_map('trim', explode(';', $sql)),
            static fn (string $statement): bool => $statement !== '',
        ));

        return $this->statements;
    }

    /**
     * Instructions CREATE TABLE indexées par nom de table.
     *
     * @return array<string, string>
     */
    public function createStatements(): array
    {
        $tables = [];

        foreach ($this->statements() as $statement) {
            if (preg_match('/^CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $statement, $match) === 1) {
                $tables[$match[1]] = $statement;
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->createStatements());
    }

    /**
     * Colonnes déclarées pour chaque table.
     *
     * @return array<string, list<string>>
     */
    public function columns(): array
    {
        $columns = [];

        foreach ($this->createStatements() as $table => $statement) {
            $columns[$table] = [];
            $body            = substr($statement, (int) strpos($statement, '(') + 1);

            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                if (preg_match('/^\s{2}`?([a-z_][a-z0-9_]*)`?\s+[A-Z]+/', $line, $match) !== 1) {
                    continue;
                }

                if (in_array(strtolower($match[1]), ['primary', 'unique', 'key', 'constraint', 'index', 'foreign'], true)) {
                    continue;
                }

                $columns[$table][] = $match[1];
            }
        }

        return $columns;
    }
}
