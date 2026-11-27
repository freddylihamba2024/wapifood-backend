<?php

declare(strict_types=1);

namespace App\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Builder as BaseBuilder;

/**
 * Pilote MySQLi WapiFood : comportement identique à CI4.
 * Classe requise par la résolution de pilote personnalisé de CI4 (voir Connection).
 */
class Builder extends BaseBuilder
{
}
