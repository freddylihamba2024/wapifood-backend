<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Support\Money;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Customers\Presenters\CustomerPresenter;
use App\Modules\Customers\Services\CustomerAddressService;
use App\Modules\Deliveries\Services\DeliveryService;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Orders\Models\OrderItemModel;
use App\Modules\Orders\Models\OrderModel;
use App\Modules\Orders\Models\OrderStatusHistoryModel;
use App\Modules\Orders\Presenters\OrderPresenter;
use App\Modules\Orders\Repositories\OrderRepository;
use App\Modules\Orders\Validators\OrderRules;
use App\Modules\Payments\Models\PaymentModel;
use App\Modules\Payments\Providers\PaymentInitiation;
use App\Modules\Products\Repositories\ProductRepository;
use App\Modules\Promotions\Models\PromotionUsageModel;
use App\Modules\Promotions\Services\PromotionService;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use App\Modules\Restaurants\Services\OpeningHoursService;
use App\Modules\Users\Repositories\UserRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\WapiFood;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Devis et création des commandes client.
 *
 * Contrôles, dans l'ordre : format → restaurant visible et non fermé temporairement → type de commande
 * accepté → moyen de paiement activé (D4) → adresse appartenant au client (livraison) → horaires
 * (maintenant, ou à l'heure souhaitée) → articles (appartenance, disponibilité, variantes, options)
 * → minimum de commande → code promo (s'il est fourni) → total attendu par le client (s'il l'envoie).
 *
 * Code promo : vérifié sans verrou pour le devis, puis à nouveau SOUS VERROU dans la transaction de
 * création (limites d'utilisation). Si la remise a changé entre-temps → 409 PRICE_CHANGED.
 *
 * Idempotence (M7) : avec la même clé, une nouvelle tentative renvoie la commande déjà créée,
 * y compris quand deux requêtes arrivent en même temps (index unique client + clé).
 */
final class OrderPlacementService
{
    private const ORDER_NUMBER_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const MAX_NUMBER_ATTEMPTS   = 5;
    private const DUPLICATE_KEY_ERROR   = 1062;

    private BaseConnection $db;

    public function __construct(
        private readonly RestaurantRepository $restaurants = new RestaurantRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly CustomerAddressService $addresses = new CustomerAddressService(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly OpeningHoursService $hours = new OpeningHoursService(),
        private readonly OrderCalculator $calculator = new OrderCalculator(),
        private readonly OrderService $orderService = new OrderService(),
        private readonly CommissionService $commissions = new CommissionService(),
        private readonly PromotionService $promotions = new PromotionService(),
        private readonly DeliveryService $deliveries = new DeliveryService(),
        private readonly NotificationDispatcher $notifications = new NotificationDispatcher(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * Calcule le panier sans rien enregistrer (mêmes contrôles que la création).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function quote(int $customerId, array $input): array
    {
        $context = $this->prepare($customerId, $input, checkExpectedTotal: false);
        $totals  = $context['totals'];

        return [
            'restaurant_id'   => (int) $context['restaurant']['id'],
            'order_type'      => $context['order_type'],
            'payment_method'  => $context['payment_method'],
            'requested_for'   => $context['requested_for']?->format('Y-m-d\TH:i:s\Z'),
            'currency'        => $totals['currency'],
            'items'           => array_map(OrderPresenter::item(...), $totals['lines']),
            'subtotal'        => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'delivery_fee'    => $totals['delivery_fee'],
            'tax_amount'      => $totals['tax_amount'],
            'total_amount'    => $totals['total_amount'],
            'promotion'       => $context['promotion'] === null ? null : [
                'code'            => $context['promotion']['promotion']['code'],
                'title'           => $context['promotion']['promotion']['title'],
                'funded_by'       => $context['promotion']['funded_by'],
                'discount_amount' => Money::fromCents($context['promotion']['discount_cents']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{order: array<string, mixed>, replayed: bool}
     */
    public function place(int $customerId, array $input, ?string $idempotencyKey): array
    {
        if ($idempotencyKey !== null) {
            if (preg_match(OrderRules::IDEMPOTENCY_KEY, $idempotencyKey) !== 1) {
                throw ApiException::validation(['idempotency_key' => 'La clé d\'idempotence doit contenir de 8 à 64 caractères (lettres, chiffres, « - » ou « _ »).']);
            }

            $existing = $this->orders->findByIdempotencyKey($customerId, $idempotencyKey);

            if ($existing !== null) {
                return $this->replay($existing, $input);
            }
        }

        $context = $this->prepare($customerId, $input, checkExpectedTotal: true);
        $user    = $this->users->find($customerId) ?? throw ApiException::unauthorized();

        for ($attempt = 1; ; $attempt++) {
            try {
                $orderId = $this->insert($customerId, $user, $context, $idempotencyKey, $this->newOrderNumber());

                break;
            } catch (DatabaseException $exception) {
                if ((int) $exception->getCode() !== self::DUPLICATE_KEY_ERROR) {
                    throw $exception;
                }

                // Requête simultanée avec la même clé : la première a gagné, on renvoie sa commande.
                if ($idempotencyKey !== null && str_contains($exception->getMessage(), 'uq_wf_orders_idempotency')) {
                    $existing = $this->orders->findByIdempotencyKey($customerId, $idempotencyKey) ?? throw $exception;

                    return $this->replay($existing, $input);
                }

                // Collision de numéro de commande (très improbable) : nouveau tirage.
                if ($attempt >= self::MAX_NUMBER_ATTEMPTS || ! str_contains($exception->getMessage(), 'uq_wf_orders_number')) {
                    throw $exception;
                }
            }
        }

        $this->initiatePayment($orderId, $context['payment_method']);

        return ['order' => $this->orderService->showForCustomer($customerId, $orderId), 'replayed' => false];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $input
     *
     * @return array{order: array<string, mixed>, replayed: bool}
     */
    private function replay(array $existing, array $input): array
    {
        $restaurantId = $input['restaurant_id'] ?? null;

        if (! is_scalar($restaurantId) || (string) $restaurantId !== (string) $existing['restaurant_id']) {
            throw ApiException::conflict(
                'Cette clé d\'idempotence a déjà été utilisée pour une autre commande.',
                [],
                'IDEMPOTENCY_KEY_REUSED',
            );
        }

        return [
            'order'    => $this->orderService->showForCustomer((int) $existing['customer_id'], (int) $existing['id']),
            'replayed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     restaurant: array<string, mixed>,
     *     order_type: string,
     *     payment_method: string,
     *     address: array<string, mixed>|null,
     *     requested_for: DateTimeImmutable|null,
     *     data: array<string, mixed>,
     *     totals: array<string, mixed>,
     *     promotion: array{promotion: array<string, mixed>, discount_cents: int, funded_by: string}|null
     * }
     */
    private function prepare(int $customerId, array $input, bool $checkExpectedTotal): array
    {
        $data  = InputValidator::validate($input, OrderRules::order());
        $items = OrderRules::items($input['items'] ?? null);

        $restaurant = $this->restaurants->findPublic((int) $data['restaurant_id'])
            ?? throw ApiException::notFound('Restaurant introuvable.', 'RESTAURANT_NOT_FOUND');

        if ($restaurant['status'] === 'paused') {
            throw ApiException::conflict('Ce restaurant est temporairement fermé et ne prend pas de commandes.', [], 'RESTAURANT_CLOSED');
        }

        $orderType = (string) $data['order_type'];

        if (($orderType === 'delivery' && ! $restaurant['accepts_delivery']) || ($orderType === 'pickup' && ! $restaurant['accepts_pickup'])) {
            throw new ApiException(
                $orderType === 'delivery' ? 'Ce restaurant ne propose pas la livraison.' : 'Ce restaurant ne propose pas le retrait sur place.',
                422,
                ['order_type' => 'Type de commande non proposé par ce restaurant.'],
                'ORDER_TYPE_UNAVAILABLE',
            );
        }

        $paymentMethod = (string) (($data['payment_method'] ?? '') !== '' ? $data['payment_method'] : 'cash');

        if (service('paymentProviders')->get($paymentMethod) === null) {
            throw new ApiException(
                'Ce moyen de paiement n\'est pas encore disponible. Seul le paiement en espèces est accepté pour le moment.',
                422,
                ['payment_method' => 'Moyen de paiement indisponible.'],
                'PAYMENT_METHOD_UNAVAILABLE',
            );
        }

        $address = null;

        if ($orderType === 'delivery') {
            if (($data['delivery_address_id'] ?? '') === '' || $data['delivery_address_id'] === null) {
                throw ApiException::validation(['delivery_address_id' => 'Choisissez une adresse de livraison.']);
            }

            $address = $this->addresses->find($customerId, (int) $data['delivery_address_id'])
                ?? throw ApiException::validation(['delivery_address_id' => 'Adresse de livraison introuvable.']);
        }

        $requestedFor = $this->requestedFor($data['requested_for'] ?? null, $restaurant);
        $hours        = $this->restaurants->hoursFor([(int) $restaurant['id']])[(int) $restaurant['id']];

        if (! $this->hours->isOpenAt($hours, (string) $restaurant['timezone'], $requestedFor ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
            throw ApiException::conflict(
                $requestedFor === null
                    ? 'Le restaurant est fermé en ce moment. Vous pouvez programmer la commande pour une heure d\'ouverture.'
                    : 'Le restaurant est fermé à l\'heure demandée.',
                [],
                'RESTAURANT_CLOSED',
            );
        }

        $products  = $this->products->findOrderable((int) $restaurant['id'], array_column($items, 'product_id'));
        $promoCode = trim((string) ($data['promo_code'] ?? ''));
        $promotion = null;

        $discountFor = $promoCode === '' ? null : function (int $subtotalCents) use ($promoCode, $restaurant, $customerId, &$promotion): int {
            $promotion = $this->promotions->apply($promoCode, $restaurant, $customerId, $subtotalCents);

            return $promotion['discount_cents'];
        };

        $totals = $this->calculator->calculate($restaurant, $products, $items, $orderType, $discountFor);

        if ($checkExpectedTotal && ($data['expected_total'] ?? '') !== '' && $data['expected_total'] !== null) {
            $expected = Money::normalize(WapiRules::numericString($data['expected_total']));

            if ($expected !== $totals['total_amount']) {
                throw ApiException::conflict(
                    'Les prix ont changé depuis l\'affichage du panier. Vérifiez le nouveau total avant de confirmer.',
                    ['expected_total' => $expected, 'total_amount' => $totals['total_amount']],
                    'PRICE_CHANGED',
                );
            }
        }

        return [
            'restaurant'     => $restaurant,
            'order_type'     => $orderType,
            'payment_method' => $paymentMethod,
            'address'        => $address,
            'requested_for'  => $requestedFor,
            'data'           => $data,
            'totals'         => $totals,
            'promotion'      => $promotion,
        ];
    }

    /**
     * Heure souhaitée (commande programmée) : ISO 8601 avec fuseau explicite, au moins le temps de
     * préparation moyen du restaurant après maintenant, au plus orderScheduleMaxDays jours.
     *
     * @param array<string, mixed> $restaurant
     */
    private function requestedFor(mixed $value, array $restaurant): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $pattern = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})\z/';

        try {
            $moment = preg_match($pattern, (string) $value) === 1 ? new DateTimeImmutable((string) $value) : null;
        } catch (Throwable) {
            $moment = null;
        }

        if ($moment === null) {
            throw ApiException::validation(['requested_for' => 'Date attendue au format ISO 8601 avec fuseau, par exemple 2026-12-01T12:30:00+01:00.']);
        }

        $moment   = $moment->setTimezone(new DateTimeZone('UTC'));
        $now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $earliest = $now->modify('+' . max(1, (int) $restaurant['average_preparation_minutes']) . ' minutes');
        $latest   = $now->modify('+' . config(WapiFood::class)->orderScheduleMaxDays . ' days');

        if ($moment < $earliest) {
            throw ApiException::validation(['requested_for' => 'L\'heure souhaitée doit laisser au restaurant au moins ' . (int) $restaurant['average_preparation_minutes'] . ' minutes de préparation.']);
        }

        if ($moment > $latest) {
            throw ApiException::validation(['requested_for' => 'Une commande ne peut pas être programmée plus de ' . config(WapiFood::class)->orderScheduleMaxDays . ' jours à l\'avance.']);
        }

        return $moment;
    }

    /**
     * Enregistre la commande, ses lignes, son historique et son paiement en une transaction.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context
     */
    private function insert(int $customerId, array $user, array $context, ?string $idempotencyKey, string $orderNumber): int
    {
        $totals  = $context['totals'];
        $address = $context['address'];
        $data    = $context['data'];

        $instructions = trim((string) ($data['delivery_instructions'] ?? ''));

        if ($instructions === '' && $address !== null) {
            $instructions = trim((string) ($address['delivery_instructions'] ?? ''));
        }

        $note = trim((string) ($data['customer_note'] ?? ''));

        $this->db->transBegin();

        try {
            $promotion = $this->lockPromotion($customerId, $context);

            $orders  = new OrderModel();
            $orderId = (int) $orders->insert([
                'order_number'              => $orderNumber,
                'idempotency_key'           => $idempotencyKey,
                'customer_id'               => $customerId,
                'restaurant_id'             => (int) $context['restaurant']['id'],
                'order_type'                => $context['order_type'],
                'delivery_address_id'       => $address === null ? null : (int) $address['id'],
                'customer_name'             => trim($user['first_name'] . ' ' . ($user['last_name'] ?? '')),
                'customer_phone'            => $user['phone'],
                'delivery_address_snapshot' => $address === null ? null : json_encode(CustomerPresenter::addressSnapshot($address), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'delivery_instructions'     => $context['order_type'] === 'delivery' && $instructions !== '' ? $instructions : null,
                'currency'                  => $totals['currency'],
                'subtotal'                  => $totals['subtotal'],
                'discount_amount'           => $totals['discount_amount'],
                'delivery_fee'              => $totals['delivery_fee'],
                'tax_amount'                => $totals['tax_amount'],
                'total_amount'              => $totals['total_amount'],
                'customer_note'             => $note === '' ? null : $note,
                'requested_for'             => $context['requested_for']?->format('Y-m-d H:i:s'),
                'status'                    => 'pending',
                'payment_status'            => 'unpaid',
            ]);

            (new OrderItemModel())->insertBatch(array_map(static fn (array $line): array => [
                'order_id'         => $orderId,
                'product_id'       => $line['product_id'],
                'product_name'     => $line['product_name'],
                'variant_name'     => $line['variant_name'],
                'quantity'         => $line['quantity'],
                'unit_price'       => $line['unit_price'],
                // Encodé en JSON par le cast « ?json-array » du modèle.
                'options_snapshot' => $line['options'] === [] ? null : $line['options'],
                'line_total'       => $line['line_total'],
                'note'             => $line['note'],
            ], $totals['lines']));

            (new OrderStatusHistoryModel())->insert([
                'order_id'   => $orderId,
                'old_status' => null,
                'new_status' => 'pending',
                'changed_by' => $customerId,
                'comment'    => null,
            ]);

            if ($promotion !== null) {
                (new PromotionUsageModel())->insert([
                    'promotion_id'    => (int) $promotion['promotion']['id'],
                    'order_id'        => $orderId,
                    'user_id'         => $customerId,
                    'discount_amount' => $totals['discount_amount'],
                ]);
            }

            if ($context['order_type'] === 'delivery') {
                $this->deliveries->createFor($orderId, $totals['delivery_fee']);
            }

            // Paiement : démarré auprès du moyen de paiement après la transaction (initiatePayment).
            (new PaymentModel())->insert([
                'order_id'           => $orderId,
                'provider'           => $context['payment_method'],
                'payment_method'     => $context['payment_method'],
                'merchant_reference' => 'PAY-' . $orderNumber,
                'amount'             => $totals['total_amount'],
                'currency'           => $totals['currency'],
                'status'             => 'initiated',
            ]);

            // Commission figée au taux actuel du restaurant (D5). Seule une remise à la charge du
            // restaurant réduit l'assiette ; celle d'un code plateforme est absorbée par WapiFood.
            $this->commissions->freeze($orderId, [
                'restaurant_id'   => (int) $context['restaurant']['id'],
                'subtotal'        => $totals['subtotal'],
                'discount_amount' => $promotion !== null && $promotion['funded_by'] === PromotionService::SCOPE_PLATFORM ? '0.00' : $totals['discount_amount'],
                'currency'        => $totals['currency'],
            ], (string) $context['restaurant']['commission_rate']);

            $this->audit->log('order.placed', $customerId, 'order', $orderId, [
                'order_number'  => $orderNumber,
                'restaurant_id' => (int) $context['restaurant']['id'],
                'total_amount'  => $totals['total_amount'],
                'currency'      => $totals['currency'],
                'promo_code'    => $promotion['promotion']['code'] ?? null,
            ]);

            $this->notifications->orderEvent('order.placed', $orderId);

            $this->db->transCommit();
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }

        return $orderId;
    }

    /**
     * Revérifie le code promo sous verrou (dans la transaction de création) : la limite d'utilisation
     * ne peut pas être dépassée par deux commandes simultanées.
     *
     * @param array<string, mixed> $context
     *
     * @return array{promotion: array<string, mixed>, discount_cents: int, funded_by: string}|null
     */
    private function lockPromotion(int $customerId, array $context): ?array
    {
        if ($context['promotion'] === null) {
            return null;
        }

        $locked = $this->promotions->apply(
            (string) $context['promotion']['promotion']['code'],
            $context['restaurant'],
            $customerId,
            Money::toCents($context['totals']['subtotal']),
            lock: true,
        );

        if ($locked['discount_cents'] !== $context['promotion']['discount_cents']) {
            throw ApiException::conflict(
                'La remise du code promo a changé. Vérifiez le nouveau total avant de confirmer.',
                ['discount_amount' => Money::fromCents($locked['discount_cents'])],
                'PRICE_CHANGED',
            );
        }

        return $locked;
    }

    /**
     * Démarre le paiement hors transaction (un appel à un fournisseur externe ne doit jamais tenir
     * de verrou en base). Un échec n'annule pas la commande : le paiement est marqué « failed ».
     */
    private function initiatePayment(int $orderId, string $method): void
    {
        $payment = $this->orders->latestPaymentOf($orderId);
        $order   = $this->db->table('wf_orders')->where('id', $orderId)->get()->getRowArray();

        try {
            $result = service('paymentProviders')->get($method)?->initiate($payment, $order)
                ?? new PaymentInitiation('failed');
        } catch (Throwable $exception) {
            log_message('error', 'Démarrage du paiement {ref} impossible : {message}', ['ref' => $payment['merchant_reference'], 'message' => $exception->getMessage()]);
            $result = new PaymentInitiation('failed');
        }

        (new PaymentModel())->update((int) $payment['id'], array_filter([
            'status'                  => $result->status,
            'provider_transaction_id' => $result->providerTransactionId,
            'raw_response'            => $result->rawResponse,
        ], static fn ($value): bool => $value !== null));

        // Espèces : la commande reste « unpaid » jusqu'à l'encaissement. Paiement électronique : « pending » ou « failed ».
        if ($method !== 'cash' || $result->status === 'failed') {
            $this->db->table('wf_orders')->where('id', $orderId)->update(['payment_status' => $result->status === 'failed' ? 'failed' : 'pending']);
        }
    }

    /** Ex. WF261125-K7Q2MX : date UTC + 6 caractères sans ambiguïté (ni 0/O, ni 1/I/L). */
    private function newOrderNumber(): string
    {
        $suffix = '';
        $max    = strlen(self::ORDER_NUMBER_ALPHABET) - 1;

        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::ORDER_NUMBER_ALPHABET[random_int(0, $max)];
        }

        return 'WF' . gmdate('ymd') . '-' . $suffix;
    }
}
