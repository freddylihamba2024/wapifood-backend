<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Deliveries\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Validators\OrderRules;
use App\Modules\Payments\Services\PaymentService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Commandes reçues par le restaurant : /api/v1/restaurant/orders
 */
final class OrderController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        [$items, $meta] = (new OrderService())->listForRestaurant(
            $this->restaurantId(),
            OrderRules::listFilters($this->request->getGet() ?? [], forRestaurant: true),
            Pagination::fromRequest($this->request),
        );

        return $this->paginated($items, $meta);
    }

    public function show(int $orderId): ResponseInterface
    {
        return $this->ok((new OrderService())->showForRestaurant($this->restaurantId(), $orderId));
    }

    public function status(int $orderId): ResponseInterface
    {
        return $this->ok(
            (new OrderService())->changeStatusByRestaurant($this->restaurantId(), $this->auth()->userId(), $orderId, $this->input()),
            'Statut de la commande mis à jour.',
        );
    }

    /** Livreur du restaurant (nom, téléphone, note) : permission deliveries.manage. */
    public function delivery(int $orderId): ResponseInterface
    {
        (new DeliveryService())->assign($this->restaurantId(), $this->auth()->userId(), $orderId, $this->input());

        return $this->ok((new OrderService())->showForRestaurant($this->restaurantId(), $orderId), 'Livreur enregistré.');
    }

    /** Livraison impossible : la commande est annulée avec le motif (communiqué au client). */
    public function deliveryFailure(int $orderId): ResponseInterface
    {
        return $this->ok(
            (new OrderService())->failDeliveryByRestaurant($this->restaurantId(), $this->auth()->userId(), $orderId, $this->input()),
            'Échec de livraison enregistré : la commande est annulée.',
        );
    }

    /** Photo de preuve de livraison (privée). Champ multipart « file ». */
    public function uploadDeliveryProof(int $orderId): ResponseInterface
    {
        (new DeliveryService())->storeProof($this->restaurantId(), $this->auth()->userId(), $orderId, $this->request->getFile('file'));

        return $this->ok((new OrderService())->showForRestaurant($this->restaurantId(), $orderId), 'Preuve de livraison enregistrée.');
    }

    public function deliveryProof(int $orderId): ResponseInterface
    {
        return $this->privateImage((new DeliveryService())->proofPath($this->restaurantId(), $orderId));
    }

    /** Le restaurant confirme avoir encaissé les espèces (permission payments.record). */
    public function cashPayment(int $orderId): ResponseInterface
    {
        (new PaymentService())->recordCash($this->restaurantId(), $this->auth()->userId(), $orderId);

        return $this->ok((new OrderService())->showForRestaurant($this->restaurantId(), $orderId), 'Encaissement enregistré.');
    }
}
