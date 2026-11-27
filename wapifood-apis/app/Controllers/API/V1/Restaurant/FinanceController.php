<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Controllers\API\BaseApiController;
use App\Modules\Commissions\Services\FinanceService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Finances du restaurant : /api/v1/restaurant/finance (permission finance.view, propriétaire).
 */
final class FinanceController extends BaseApiController
{
    /** Commission due à WapiFood et commission en attente, par devise. */
    public function balances(): ResponseInterface
    {
        return $this->ok((new FinanceService())->balances($this->restaurantId()));
    }

    public function ledger(): ResponseInterface
    {
        [$items, $meta] = (new FinanceService())->ledger($this->restaurantId(), $this->currencyFilter(), Pagination::fromRequest($this->request));

        return $this->paginated($items, $meta);
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
