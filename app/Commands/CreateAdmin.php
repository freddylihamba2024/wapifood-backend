<?php

declare(strict_types=1);

namespace App\Commands;

use App\Common\Exceptions\ApiException;
use App\Modules\Users\Services\AdminAccountService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Création sécurisée d'un super administrateur.
 *
 *   php spark wapifood:create-admin
 *   php spark wapifood:create-admin --first-name Jean --email admin@exemple.cd --phone 0812345678
 *
 * Le mot de passe est saisi deux fois sans affichage à l'écran. Pour un déploiement
 * non interactif, il peut être fourni par la variable d'environnement
 * WAPIFOOD_ADMIN_PASSWORD (jamais en argument : il resterait dans l'historique du shell).
 */
class CreateAdmin extends BaseCommand
{
    protected $group       = 'WapiFood';
    protected $name        = 'wapifood:create-admin';
    protected $description = 'Crée un compte super administrateur (mot de passe saisi de façon masquée).';
    protected $usage       = 'wapifood:create-admin [options]';

    /** @var array<string, string> */
    protected $options = [
        '--first-name' => 'Prénom',
        '--last-name'  => 'Nom',
        '--email'      => 'Adresse e-mail',
        '--phone'      => 'Téléphone (ex. 0812345678 ou +243812345678)',
        '--additional' => 'Autorise la création si un super administrateur existe déjà',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params)
    {
        $service = new AdminAccountService();

        $additional = CLI::getOption('additional') !== null || array_key_exists('additional', $params);

        if (! $additional && $service->superAdminCount() > 0) {
            CLI::error('Un super administrateur existe déjà. Relancez avec --additional pour en créer un autre.');

            return EXIT_ERROR;
        }

        $firstName = $this->option($params, 'first-name') ?? CLI::prompt('Prénom', null, 'required');
        $lastName  = $this->option($params, 'last-name') ?? CLI::prompt('Nom (facultatif)');
        $email     = $this->option($params, 'email') ?? CLI::prompt('E-mail (facultatif)');
        $phone     = $this->option($params, 'phone') ?? CLI::prompt('Téléphone', null, 'required');

        $password = (string) env('WAPIFOOD_ADMIN_PASSWORD', '');

        if ($password === '') {
            $password = $this->hiddenPrompt('Mot de passe');

            if (! hash_equals($password, $this->hiddenPrompt('Confirmation du mot de passe'))) {
                CLI::error('Les mots de passe ne correspondent pas.');

                return EXIT_ERROR;
            }
        }

        try {
            $userId = $service->createSuperAdmin($firstName, $lastName, $email, $phone, $password, $additional);
        } catch (ApiException $exception) {
            CLI::error($exception->getMessage());

            foreach ($exception->getErrors() as $field => $message) {
                CLI::error("  - {$field} : {$message}");
            }

            return EXIT_ERROR;
        } finally {
            unset($password);
        }

        CLI::write("Super administrateur créé (id {$userId}).", 'green');

        return EXIT_SUCCESS;
    }

    /**
     * @param array<int|string, string|null> $params
     */
    private function option(array $params, string $name): ?string
    {
        $value = $params[$name] ?? CLI::getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Lit une saisie sans l'afficher (stty). Refuse si le terminal ne le permet pas.
     */
    private function hiddenPrompt(string $label): string
    {
        if (! function_exists('posix_isatty') || ! posix_isatty(STDIN)) {
            CLI::error('Saisie masquée impossible (pas de terminal). Utilisez WAPIFOOD_ADMIN_PASSWORD.');

            exit(EXIT_ERROR);
        }

        CLI::print($label . ' : ');
        shell_exec('stty -echo');
        $value = fgets(STDIN);
        shell_exec('stty echo');
        CLI::newLine();

        return rtrim((string) $value, "\r\n");
    }
}
