<?php

declare(strict_types=1);

namespace App\Filters;

use App\Common\Http\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\WapiFood;

/**
 * Limitation du nombre de requêtes par adresse IP (algorithme du seau à jetons de CI4).
 *
 * Utilisation dans les routes :
 *   'filter' => 'ratelimit'                 → limite par défaut (Config\WapiFood)
 *   'filter' => 'ratelimit:5,60,auth-login' → 5 requêtes / 60 s, compteur « auth-login » séparé
 *   'filter' => ['jwt', 'ratelimit:20,600,order-create,per-user']
 *                                           → compteur par utilisateur connecté (à placer APRÈS « jwt »).
 *     Indispensable pour les routes métier : sur les réseaux mobiles (NAT des opérateurs),
 *     des milliers de clients peuvent partager la même adresse IP.
 *
 * Stockage : cache CI4 (fichier par défaut), aucun service externe requis.
 * Derrière un proxy, renseigner Config\App::$proxyIPs pour obtenir la vraie IP client.
 */
final class RateLimitFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments [capacité, fenêtre en secondes, nom du compteur, « per-user »]
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        /** @var WapiFood $config */
        $config = config(WapiFood::class);

        if (! $config->rateLimitEnabled) {
            return null;
        }

        $capacity = max(1, (int) ($arguments[0] ?? $config->rateLimitRequests));
        $window   = max(1, (int) ($arguments[1] ?? $config->rateLimitWindow));
        $bucket   = (string) ($arguments[2] ?? 'api');

        // Par utilisateur si demandé et authentifié ; sinon par IP.
        $context  = service('authContext');
        $identity = ($arguments[3] ?? null) === 'per-user' && $context->isAuthenticated()
            ? 'user:' . $context->userId()
            : 'ip:' . $request->getIPAddress();

        // md5 : clé de cache sans caractère réservé, et l'IP n'est pas stockée en clair.
        $key = 'rl_' . md5($bucket . '|' . $identity);

        $throttler = service('throttler');

        if ($throttler->check($key, $capacity, $window)) {
            return null;
        }

        $retryAfter = max(1, $throttler->getTokenTime());

        return ApiResponse::error(service('response'), 429, 'Trop de requêtes. Réessayez plus tard.', [], ['code' => 'RATE_LIMITED'])
            ->setHeader('Retry-After', (string) $retryAfter)
            ->setHeader('X-RateLimit-Limit', (string) $capacity)
            ->setHeader('X-RateLimit-Remaining', '0');
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
