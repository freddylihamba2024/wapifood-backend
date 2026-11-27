<?php

declare(strict_types=1);

namespace App\Modules\Deliveries\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\PhoneNumber;
use App\Common\Validation\InputValidator;
use App\Libraries\Upload\UploadService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Deliveries\Models\DeliveryModel;
use App\Modules\Orders\Presenters\OrderPresenter;
use App\Modules\Orders\Repositories\OrderRepository;
use App\Modules\Users\Presenters\UserPresenter;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;

/**
 * Livraison d'une commande par le restaurant (managed_by = restaurant ; les livreurs WapiFood
 * de wf_couriers sont hors périmètre du MVP).
 *
 * La livraison suit la commande ; elle est créée avec une commande « delivery » :
 *
 *   pending ──(livreur indiqué)──► assigned
 *      └────────────┴──(commande out_for_delivery)──► in_transit ──(commande completed)──► delivered
 *                                                         └──(échec déclaré)──► failed  (commande annulée)
 *   commande refusée ou annulée avant le départ ──► cancelled
 *
 * Le livreur du restaurant n'a pas de compte : son nom et son téléphone suffisent (M10), et restent
 * facultatifs (Ph7-5). La preuve de livraison (photo) est privée : jamais servie par /media.
 */
final class DeliveryService
{
    /** Statuts de commande pendant lesquels le livreur peut être indiqué ou changé. */
    private const ASSIGNABLE_ORDER_STATUSES = ['accepted', 'preparing', 'ready', 'out_for_delivery'];

    private BaseConnection $db;

    public function __construct(
        private readonly DeliveryModel $deliveries = new DeliveryModel(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly UploadService $uploads = new UploadService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * À la création d'une commande en livraison (dans la transaction de l'appelant).
     */
    public function createFor(int $orderId, string $deliveryFee): void
    {
        $this->deliveries->insert([
            'order_id'        => $orderId,
            'managed_by'      => 'restaurant',
            'delivery_status' => 'pending',
            'delivery_fee'    => $deliveryFee,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forOrder(int $orderId): ?array
    {
        return $this->db->table('wf_deliveries')->where('order_id', $orderId)->get()->getRowArray();
    }

    /**
     * Suit le changement de statut de la commande (dans la transaction de OrderService).
     */
    public function syncWithOrder(int $orderId, string $orderStatus, bool $failed = false): void
    {
        $now     = Time::now('UTC')->toDateTimeString();
        $builder = $this->db->table('wf_deliveries')->where('order_id', $orderId);

        match (true) {
            $failed                            => $builder->where('delivery_status', 'in_transit')->update(['delivery_status' => 'failed']),
            $orderStatus === 'out_for_delivery' => $builder->whereIn('delivery_status', ['pending', 'assigned'])->update(['delivery_status' => 'in_transit', 'pickup_at' => $now]),
            $orderStatus === 'completed'        => $builder->where('delivery_status', 'in_transit')->update(['delivery_status' => 'delivered', 'delivered_at' => $now]),
            in_array($orderStatus, ['cancelled', 'rejected'], true) => $builder->whereIn('delivery_status', ['pending', 'assigned'])->update(['delivery_status' => 'cancelled']),
            default                            => null,
        };
    }

    /**
     * Le restaurant indique (ou change) le livreur : nom, téléphone, note.
     *
     * @param array<string, mixed> $input { courier_name, courier_phone?, notes? }
     *
     * @return array<string, mixed> Livraison mise à jour
     */
    public function assign(int $restaurantId, int $userId, int $orderId, array $input): array
    {
        $data     = InputValidator::validate($input, self::courierRules(required: true));
        $order    = $this->orders->findForRestaurant($restaurantId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');
        $delivery = $this->deliveryOf($order);

        if (! in_array($order['status'], self::ASSIGNABLE_ORDER_STATUSES, true) || ! in_array($delivery['delivery_status'], ['pending', 'assigned', 'in_transit'], true)) {
            throw ApiException::conflict(
                'Le livreur ne peut être indiqué que pour une commande acceptée et pas encore livrée.',
                ['order_status' => $order['status'], 'delivery_status' => $delivery['delivery_status']],
                'DELIVERY_NOT_ASSIGNABLE',
            );
        }

        $changes = self::courierChanges($data);

        if ($delivery['delivery_status'] === 'pending') {
            $changes['delivery_status'] = 'assigned';
        }

        $this->deliveries->update((int) $delivery['id'], $changes);
        $this->audit->log('delivery.courier_assigned', $userId, 'order', $orderId, ['courier_name' => $changes['courier_name']]);

        return self::present($this->forOrder($orderId), OrderPresenter::AUDIENCE_RESTAURANT);
    }

    /**
     * Livreur indiqué en même temps que le départ en livraison (PATCH status), dans la transaction de
     * OrderService. Sans nom de livreur, la livraison part sans livreur renseigné (Ph7-5).
     *
     * @param array<string, mixed> $input
     */
    public function assignOnDeparture(int $orderId, array $input): void
    {
        if (trim((string) ($input['courier_name'] ?? '')) === '') {
            return;
        }

        $data = InputValidator::validate($input, self::courierRules(required: true));

        $this->db->table('wf_deliveries')->where('order_id', $orderId)->update(self::courierChanges($data));
    }

    /**
     * Photo de preuve de livraison (privée), une fois la commande partie.
     *
     * @return array<string, mixed>
     */
    public function storeProof(int $restaurantId, int $userId, int $orderId, ?UploadedFile $file): array
    {
        $order    = $this->orders->findForRestaurant($restaurantId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');
        $delivery = $this->deliveryOf($order);

        if (! in_array($delivery['delivery_status'], ['in_transit', 'delivered'], true)) {
            throw ApiException::conflict('La preuve de livraison s\'ajoute une fois la commande partie en livraison.', ['delivery_status' => $delivery['delivery_status']], 'DELIVERY_PROOF_NOT_ALLOWED');
        }

        $path = $this->uploads->storeImage($file, 'delivery-proofs');
        $this->deliveries->update((int) $delivery['id'], ['proof_url' => $path]);
        $this->uploads->delete($delivery['proof_url']);
        $this->audit->log('delivery.proof_added', $userId, 'order', $orderId);

        return self::present($this->forOrder($orderId), OrderPresenter::AUDIENCE_RESTAURANT);
    }

    /**
     * Chemin absolu de la photo de preuve, pour l'équipe du restaurant ($restaurantId) ou l'administration (null).
     */
    public function proofPath(?int $restaurantId, int $orderId): string
    {
        $order = $restaurantId === null ? $this->orders->find($orderId) : $this->orders->findForRestaurant($restaurantId, $orderId);

        if ($order === null) {
            throw ApiException::notFound('Commande introuvable.');
        }

        return $this->uploads->resolve($this->forOrder($orderId)['proof_url'] ?? null)
            ?? throw ApiException::notFound('Aucune preuve de livraison pour cette commande.', 'DELIVERY_PROOF_NOT_FOUND');
    }

    /**
     * @param array<string, mixed>|null $delivery
     *
     * @return array<string, mixed>|null
     */
    public static function present(?array $delivery, string $audience): ?array
    {
        if ($delivery === null) {
            return null;
        }

        $data = [
            'status'        => $delivery['delivery_status'],
            'managed_by'    => $delivery['managed_by'],
            'courier_name'  => $delivery['courier_name'],
            'courier_phone' => $delivery['courier_phone'],
            'picked_up_at'  => UserPresenter::isoDate($delivery['pickup_at']),
            'delivered_at'  => UserPresenter::isoDate($delivery['delivered_at']),
        ];

        if ($audience === OrderPresenter::AUDIENCE_RESTAURANT) {
            $data['notes']     = $delivery['notes'];
            $data['has_proof'] = $delivery['proof_url'] !== null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function deliveryOf(array $order): array
    {
        if ($order['order_type'] !== 'delivery') {
            throw ApiException::conflict('Cette commande est à retirer sur place : elle n\'a pas de livraison.', [], 'ORDER_NOT_DELIVERY');
        }

        return $this->forOrder((int) $order['id']) ?? throw ApiException::notFound('Livraison introuvable.');
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private static function courierRules(bool $required): array
    {
        return [
            'courier_name'  => ['label' => 'nom du livreur', 'rules' => ($required ? 'required' : 'permit_empty') . '|string|min_length[2]|max_length[150]'],
            'courier_phone' => ['label' => 'téléphone du livreur', 'rules' => 'permit_empty|wf_phone'],
            'notes'         => ['label' => 'note', 'rules' => 'permit_empty|string|max_length[1000]'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function courierChanges(array $data): array
    {
        $phone = trim((string) ($data['courier_phone'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        return [
            'courier_name'  => trim((string) $data['courier_name']),
            'courier_phone' => $phone === '' ? null : PhoneNumber::normalize($phone),
            'notes'         => $notes === '' ? null : $notes,
        ];
    }
}
