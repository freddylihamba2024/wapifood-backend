<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Customer;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Orders\Services\OrderPlacementService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Validators\OrderRules;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Commandes du client : /api/v1/customer/orders
 *
 * Création : en-tête « Idempotency-Key » recommandé. Une nouvelle tentative avec la même clé renvoie
 * la commande déjà créée (200, en-tête « Idempotent-Replayed: true ») au lieu d'en créer une seconde.
 */
final class OrderController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new OrderService())->listForCustomer(
            $this->auth()->userId(),
            OrderRules::listFilters($this->request->getGet() ?? [], forRestaurant: false),
            Pagination::fromRequest($this->request),
        );

        return $this->paginated($items, $meta);
    }

    public function show(int $orderId): ResponseInterface
    {
        return $this->ok((new OrderService())->showForCustomer($this->auth()->userId(), $orderId));
    }

    public function quote(): ResponseInterface
    {
        return $this->ok((new OrderPlacementService())->quote($this->auth()->userId(), $this->input()), 'Panier calculé.');
    }

    public function create(): ResponseInterface
    {
        $key    = trim($this->request->getHeaderLine('Idempotency-Key'));
        $result = (new OrderPlacementService())->place($this->auth()->userId(), $this->input(), $key === '' ? null : $key);

        if ($result['replayed']) {
            $this->response->setHeader('Idempotent-Replayed', 'true');

            return $this->ok($result['order'], 'Cette commande a déjà été enregistrée.');
        }

        return $this->created($result['order'], 'Commande envoyée au restaurant.');
    }

    public function cancel(int $orderId): ResponseInterface
    {
        return $this->ok((new OrderService())->cancelByCustomer($this->auth()->userId(), $orderId, $this->input()), 'Commande annulée.');
    }
}
