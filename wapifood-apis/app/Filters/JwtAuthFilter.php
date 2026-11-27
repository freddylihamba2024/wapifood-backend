<?php

declare(strict_types=1);

namespace App\Filters;

use App\Common\Exceptions\ApiException;
use App\Common\Exceptions\ApiExceptionRenderer;
use App\Modules\Auth\Services\TokenService;
use App\Modules\Users\Repositories\UserRepository;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authentification par jeton d'accès (en-tête « Authorization: Bearer <jwt> »).
 *
 * Contrôles, dans l'ordre : signature et validité du JWT → compte existant et non suspendu
 * → token_version identique → session (sid) toujours active → compte vérifié
 * (sauf argument « allow-pending », réservé aux routes de gestion du compte).
 *
 *   'filter' => 'jwt'                 → comptes actifs uniquement
 *   'filter' => 'jwt:allow-pending'   → autorise aussi les comptes en attente de vérification
 */
final class JwtAuthFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $context = service('authContext');
        $context->clear();

        try {
            $header = $request->getHeaderLine('Authorization');

            if (preg_match('/\ABearer\s+(\S+)\z/i', trim($header), $match) !== 1) {
                throw ApiException::unauthorized('Authentification requise.', 'TOKEN_MISSING');
            }

            $claims = service('jwt')->verifyAccessToken($match[1]);
            $users  = new UserRepository();
            $user   = $users->find($claims['sub']);

            if ($user === null) {
                throw ApiException::unauthorized('Jeton d\'authentification invalide.', 'TOKEN_INVALID');
            }

            if (in_array($user['status'], ['suspended', 'deleted'], true)) {
                throw ApiException::forbidden('Ce compte est suspendu. Contactez le support WapiFood.', 'ACCOUNT_SUSPENDED');
            }

            if ((int) $user['token_version'] !== $claims['ver'] || ! (new TokenService())->isSessionActive((int) $user['id'], $claims['sid'])) {
                throw ApiException::unauthorized('Session révoquée. Reconnectez-vous.', 'TOKEN_REVOKED');
            }

            if ($user['status'] !== 'active' && ! in_array('allow-pending', (array) $arguments, true)) {
                throw ApiException::forbidden('Vérifiez votre numéro de téléphone pour accéder à cette fonctionnalité.', 'ACCOUNT_NOT_VERIFIED');
            }

            $context->authenticate($user, $users->rolesOf((int) $user['id']), $claims['sid']);
        } catch (ApiException $exception) {
            return (new ApiExceptionRenderer())->toResponse($exception, service('response'));
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        service('authContext')->clear();

        return null;
    }
}
