<?php

declare(strict_types=1);

namespace App\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Connection as BaseConnection;

/**
 * Connexion MySQLi WapiFood.
 *
 * Identique au pilote CI4, avec une seule différence : chaque session MySQL est
 * placée sur un fuseau fixe (UTC par défaut). Ainsi NOW(), CURRENT_TIMESTAMP et
 * les dates écrites par PHP (Config\App::$appTimezone = UTC) utilisent la même
 * référence, quel que soit le fuseau du serveur MySQL.
 *
 * Activation : 'DBDriver' => 'App\Database\MySQLi' dans Config\Database.
 * Option : 'sessionTimezone' => '+00:00' (décalage, ne nécessite pas les tables de fuseaux MySQL).
 */
class Connection extends BaseConnection
{
    protected string $sessionTimezone = '+00:00';

    /**
     * @return false|\mysqli
     */
    public function connect(bool $persistent = false)
    {
        $connection = parent::connect($persistent);

        if ($connection !== false && preg_match('/\A[+-](0\d|1[0-4]):[0-5]\d\z/', $this->sessionTimezone) === 1) {
            $connection->query("SET time_zone = '{$this->sessionTimezone}'");
        }

        return $connection;
    }

    /**
     * Le reste du framework compare la plateforme à « MySQLi » : on conserve ce nom.
     */
    public function getPlatform(): string
    {
        return 'MySQLi';
    }

    protected function getDriverFunctionPrefix(): string
    {
        return 'mysqli_';
    }
}
