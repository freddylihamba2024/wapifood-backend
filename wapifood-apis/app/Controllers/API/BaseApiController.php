<?php

declare(strict_types=1);

namespace App\Controllers\API;

use App\Common\Auth\AuthContext;
use App\Common\Exceptions\ApiException;
use App\Common\Exceptions\ApiExceptionRenderer;
use App\Common\Http\ApiResponse;
use App\Common\Validation\InputValidator;
use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Contrôleur de base de l'API.
 *
 * - Toute exception levée par une action est convertie en réponse JSON standard
 *   (même rendu que le gestionnaire global, testable en feature test).
 * - Fournit des raccourcis de réponse au format WapiFood.
 *
 * Les contrôleurs restent minces : lecture de la requête, validation,
 * appel d'un service, réponse. Aucune logique métier ici.
 */
abstract class BaseApiController extends BaseController
{
    /**
     * Point d'entrée appelé par CI4 pour chaque action routée.
     *
     * @param mixed ...$params
     */
    public function _remap(string $method, ...$params): ResponseInterface
    {
        try {
            if ($method === '' || str_starts_with($method, '_') || ! is_callable([$this, $method])) {
                throw ApiException::notFound();
            }

            return $this->{$method}(...$this->castRouteParams($method, $params));
        } catch (Throwable $exception) {
            return (new ApiExceptionRenderer())->toResponse($exception, $this->response);
        }
    }

    /**
     * Convertit les segments d'URL selon le type déclaré des paramètres de l'action.
     * Un identifiant attendu entier mais non numérique (ou hors bornes) donne un 404.
     *
     * @param list<mixed> $params
     *
     * @return list<mixed>
     */
    private function castRouteParams(string $method, array $params): array
    {
        $reflection = new ReflectionMethod($this, $method);

        foreach ($reflection->getParameters() as $index => $parameter) {
            if (! array_key_exists($index, $params)) {
                break;
            }

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
                $value = (string) $params[$index];

                if (preg_match('/\A[1-9]\d{0,17}\z/', $value) !== 1) {
                    throw ApiException::notFound();
                }

                $params[$index] = (int) $value;
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed>|list<mixed>|object|null $data
     * @param array<string, mixed>|null                    $meta
     */
    protected function ok(mixed $data = null, string $message = 'Opération effectuée avec succès.', ?array $meta = null): ResponseInterface
    {
        return ApiResponse::success($this->response, $data, $message, 200, $meta);
    }

    /**
     * @param array<string, mixed>|list<mixed>|object|null $data
     */
    protected function created(mixed $data = null, string $message = 'Ressource créée avec succès.'): ResponseInterface
    {
        return ApiResponse::success($this->response, $data, $message, 201);
    }

    protected function noContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204)->setBody('');
    }

    /** Utilisateur authentifié (renseigné par le filtre « jwt »). */
    protected function auth(): AuthContext
    {
        return service('authContext');
    }

    /** Restaurant du contexte (renseigné par le filtre « restaurant »). */
    protected function restaurantId(): int
    {
        return $this->auth()->restaurant()->restaurantId;
    }

    /**
     * Corps de la requête décodé (JSON ou formulaire), sans validation.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 400 INVALID_JSON
     */
    protected function input(): array
    {
        try {
            $input = $this->request->is('json')
                ? $this->request->getJSON(true)
                : $this->request->getPost();
        } catch (Throwable) {
            throw ApiException::badRequest('Le corps de la requête n\'est pas un JSON valide.', [], 'INVALID_JSON');
        }

        return is_array($input) ? $input : [];
    }

    /**
     * Valide le corps de la requête (JSON ou formulaire) avec les règles CI4.
     *
     * @param array<string, array<string, mixed>|string> $rules
     *
     * @return array<string, mixed> Uniquement les champs déclarés dans $rules
     *
     * @throws ApiException 400 INVALID_JSON | 422 VALIDATION_FAILED
     */
    protected function validatedInput(array $rules): array
    {
        return InputValidator::validate($this->input(), $rules);
    }

    /**
     * Paramètre de requête booléen (?featured=true) ; null si absent.
     */
    protected function queryBool(string $name): ?bool
    {
        $value = $this->request->getGet($name);

        if ($value === null || $value === '') {
            return null;
        }

        $bool = is_string($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        return $bool ?? throw ApiException::validation([$name => "Le paramètre {$name} doit valoir true ou false."]);
    }

    /** Paramètre de requête texte (tronqué à 100 caractères) ; chaîne vide si absent. */
    protected function queryString(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    /**
     * Image privée (preuve de livraison) servie à un utilisateur autorisé : jamais mise en cache partagé.
     */
    protected function privateImage(string $path): ResponseInterface
    {
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

        $this->response->removeHeader('Cache-Control');

        return $this->response
            ->setHeader('Content-Type', $types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'")
            ->setHeader('Content-Disposition', 'inline')
            ->setBody((string) file_get_contents($path));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, int>         $meta
     */
    protected function paginated(array $items, array $meta, string $message = 'Opération effectuée avec succès.'): ResponseInterface
    {
        return $this->ok($items, $message, ['pagination' => $meta]);
    }
}
