<?php

declare(strict_types=1);

namespace App\Commands;

use App\Modules\Notifications\Services\NotificationQueueProcessor;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Envoie les notifications en file (e-mail, SMS, WhatsApp), avec reprise sur échec.
 *
 *   php spark notifications:process              (au plus Notifications::$batchSize notifications)
 *   php spark notifications:process --limit 500
 *
 * À planifier chaque minute (cron). Plusieurs exécutions simultanées sont sans risque (verrou par notification).
 */
class ProcessNotifications extends BaseCommand
{
    protected $group       = 'WapiFood';
    protected $name        = 'notifications:process';
    protected $description = 'Envoie les notifications en file et planifie les nouvelles tentatives.';
    protected $usage       = 'notifications:process [--limit 100]';

    /** @var array<string, string> */
    protected $options = [
        '--limit' => 'Nombre maximal de notifications traitées (défaut : Notifications::$batchSize)',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $limit = $params['limit'] ?? CLI::getOption('limit');
        $stats = (new NotificationQueueProcessor())->process($limit === null ? null : max(1, (int) $limit));

        CLI::write(sprintf(
            'Traitées : %d · envoyées : %d · à réessayer : %d · abandonnées : %d',
            $stats['processed'],
            $stats['sent'],
            $stats['retry'],
            $stats['failed'],
        ), $stats['failed'] > 0 ? 'yellow' : 'green');

        return EXIT_SUCCESS;
    }
}
