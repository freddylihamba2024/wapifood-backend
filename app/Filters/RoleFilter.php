<?php

declare(strict_types=1);

namespace App\Filters;

use App\Common\Exceptions\ApiException;
use App\Common\Exceptions\ApiExceptionRenderer;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Exige au moins un des rôles globaux indiqués. À placer APRÈS le filtre « jwt ».
 *
 *   'filter' => ['jwt', 'role:super_admin']
 *   'filter' => ['jwt', 'role:restaurant_owner,restaurant_staff']
 */
final class RoleFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $context = service('authContext');

        try {
            if (! $context->isAuthenticated()) {
                throw ApiException::unauthorized('Authentification requise.', 'TOKEN_MISSING');
            }

            if ($arguments === null || $arguments === [] || ! $context->hasRole(...$arguments)) {
                throw ApiException::forbidden('Vous n\'avez pas les droits nécessaires.', 'FORBIDDEN_ROLE');
            }
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
