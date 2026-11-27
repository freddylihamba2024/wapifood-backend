<?php

declare(strict_types=1);

namespace App\Filters;

use App\Common\Exceptions\ApiException;
use App\Common\Exceptions\ApiExceptionRenderer;
use App\Modules\Restaurants\Services\RestaurantAccessService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Résout le restaurant dans lequel l'utilisateur agit et vérifie sa permission.
 * À placer APRÈS le filtre « jwt ».
 *
 * Restaurant : en-tête « X-Restaurant-Id », ou l'unique restaurant de l'utilisateur.
 * Restaurant suspendu : lecture seule (toute méthode autre que GET → 403 RESTAURANT_SUSPENDED).
 *
 *   'filter' => ['jwt', 'restaurant:orders.view']
 */
final class RestaurantAccessFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments [permission requise]
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $context = service('authContext');

        try {
            if (! $context->isAuthenticated()) {
                throw ApiException::unauthorized('Authentification requise.', 'TOKEN_MISSING');
            }

            $header = trim($request->getHeaderLine('X-Restaurant-Id'));

            if ($header !== '' && preg_match('/\A[1-9]\d{0,18}\z/', $header) !== 1) {
                throw ApiException::badRequest('En-tête X-Restaurant-Id invalide.', [], 'INVALID_RESTAURANT_ID');
            }

            $restaurant = (new RestaurantAccessService())->resolve($context->userId(), $header === '' ? null : (int) $header);
            $permission = $arguments[0] ?? null;

            if ($permission !== null && ! $restaurant->can($permission)) {
                throw ApiException::forbidden('Votre rôle dans ce restaurant ne permet pas cette action.', 'PERMISSION_DENIED');
            }

            // Un restaurant suspendu par l'administration reste consultable mais ne peut plus rien modifier.
            if ($restaurant->restaurantStatus === 'suspended' && strtoupper($request->getMethod()) !== 'GET') {
                throw ApiException::forbidden('Ce restaurant est suspendu : les modifications sont bloquées. Contactez WapiFood.', 'RESTAURANT_SUSPENDED');
            }

            $context->setRestaurant($restaurant);
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
        return null;
    }
}
