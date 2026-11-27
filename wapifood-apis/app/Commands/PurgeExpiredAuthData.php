<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Supprime les refresh tokens et codes OTP devenus inutiles.
 *
 *   php spark auth:purge-expired --dry-run     (compte sans supprimer)
 *   php spark auth:purge-expired               (supprime)
 *   php spark auth:purge-expired --days 30     (délai de conservation, 30 jours par défaut)
 *
 * Seules les lignes expirées ou révoquées depuis plus de --days jours sont supprimées :
 * l'historique récent reste disponible pour les enquêtes de sécurité. Aucune donnée métier
 * n'est concernée. À planifier une fois par jour (cron).
 */
class PurgeExpiredAuthData extends BaseCommand
{
    protected $group       = 'WapiFood';
    protected $name        = 'auth:purge-expired';
    protected $description = 'Supprime les refresh tokens et codes OTP expirés ou révoqués depuis plus de N jours.';
    protected $usage       = 'auth:purge-expired [--days 30] [--dry-run]';

    /** @var array<string, string> */
    protected $options = [
        '--days'    => 'Délai de conservation en jours (minimum 7, défaut 30)',
        '--dry-run' => 'Affiche le nombre de lignes concernées sans rien supprimer',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $days   = max(7, (int) ($params['days'] ?? CLI::getOption('days') ?? 30));
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $db     = db_connect();

        $tokens = static fn () => $db->table('wf_refresh_tokens')
            ->groupStart()
                ->where('expires_at <', $cutoff)
                ->orWhere('revoked_at <', $cutoff)
            ->groupEnd();

        $codes = static fn () => $db->table('wf_auth_codes')->where('expires_at <', $cutoff);

        $tokenCount = $tokens()->countAllResults();
        $codeCount  = $codes()->countAllResults();

        CLI::write("Seuil : antérieur au {$cutoff} UTC ({$days} jours).");
        CLI::write("Refresh tokens concernés : {$tokenCount}");
        CLI::write("Codes OTP concernés      : {$codeCount}");

        if ($dryRun) {
            CLI::write('Mode --dry-run : aucune suppression.', 'yellow');

            return EXIT_SUCCESS;
        }

        // Les chaînes de rotation pointent vers des jetons supprimés : on les détache d'abord.
        $db->query(
            'UPDATE wf_refresh_tokens t JOIN wf_refresh_tokens r ON r.id = t.replaced_by_id
             SET t.replaced_by_id = NULL
             WHERE r.expires_at < ? OR r.revoked_at < ?',
            [$cutoff, $cutoff],
        );

        $tokens()->delete();
        $codes()->delete();

        CLI::write('Purge terminée.', 'green');

        return EXIT_SUCCESS;
    }
}
