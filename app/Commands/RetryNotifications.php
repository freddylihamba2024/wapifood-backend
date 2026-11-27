<?php

declare(strict_types=1);

namespace App\Commands;

use App\Modules\Notifications\Services\NotificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Remet en file les notifications en échec (après une panne du fournisseur, par exemple).
 *
 *   php spark notifications:retry --id 42          (une notification)
 *   php spark notifications:retry --hours 24       (toutes celles créées dans les 24 dernières heures)
 *
 * Elles seront envoyées par la prochaine exécution de `notifications:process`.
 */
class RetryNotifications extends BaseCommand
{
    protected $group       = 'WapiFood';
    protected $name        = 'notifications:retry';
    protected $description = 'Remet en file les notifications en échec.';
    protected $usage       = 'notifications:retry [--id 42] [--hours 24]';

    /** @var array<string, string> */
    protected $options = [
        '--id'    => 'Identifiant de la notification à renvoyer',
        '--hours' => 'Notifications en échec créées dans les N dernières heures (défaut 24)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $id    = $params['id'] ?? CLI::getOption('id');
        $hours = (int) ($params['hours'] ?? CLI::getOption('hours') ?? 24);

        if ($id !== null && ! ctype_digit((string) $id)) {
            CLI::error('--id doit être un entier.');

            return EXIT_ERROR;
        }

        $count = (new NotificationService())->requeue($id === null ? null : [(int) $id], $hours);

        CLI::write("Notifications remises en file : {$count}", 'green');

        return EXIT_SUCCESS;
    }
}
