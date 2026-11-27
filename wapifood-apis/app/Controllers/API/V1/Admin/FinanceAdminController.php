<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Admin;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Commissions\Services\FinanceService;
use App\Modules\Deliveries\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Presenters\UserPresenter;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Administration financière : soldes, journal, règlements, ajustements, remboursements (super_admin).
 */
final class FinanceAdminController extends BaseApiController
{
    public function balances(): ResponseInterface
    {
        [$items, $meta] = (new FinanceService())->adminBalances($this->currencyFilter(), $this->queryBool('only_due') ?? false, Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function ledger(int $restaurantId): ResponseInterface
    {
        [$items, $meta] = (new FinanceService())->ledger($restaurantId, $this->currencyFilter(), Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
    }

    public function settlement(int $restaurantId): ResponseInterface
    {
        return $this->created((new FinanceService())->recordSettlement($this->auth()->userId(), $restaurantId, $this->input()), 'Règlement enregistré.');
    }

    public function adjustment(int $restaurantId): ResponseInterface
    {
        return $this->created((new FinanceService())->recordAdjustment($this->auth()->userId(), $restaurantId, $this->input()), 'Ajustement enregistré.');
    }

    public function order(int $orderId): ResponseInterface
    {
        $order            = (new OrderService())->showForAdmin($orderId);
        $order['refunds'] = array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'amount'     => $r['amount'],
            'currency'   => $r['currency'],
            'status'     => $r['status'],
            'reason'     => $r['reason'],
            'created_at' => UserPresenter::isoDate($r['created_at']),
        ], (new PaymentService())->refundsOf($orderId));

        return $this->ok($order);
    }

    public function refund(int $orderId): ResponseInterface
    {
        return $this->created((new PaymentService())->refund($this->auth()->userId(), $orderId, $this->input()), 'Remboursement enregistré.');
    }

    /** Preuve de livraison d'une commande (support, litiges). */
    public function deliveryProof(int $orderId): ResponseInterface
    {
        return $this->privateImage((new DeliveryService())->proofPath(null, $orderId));
    }

    private function currencyFilter(): ?string
    {
        $currency = $this->queryString('currency');

        if ($currency !== '' && ! in_array($currency, ['USD', 'CDF'], true)) {
            throw ApiException::validation(['currency' => 'Devise attendue : USD ou CDF.']);
        }

        return $currency === '' ? null : $currency;
    }
}
