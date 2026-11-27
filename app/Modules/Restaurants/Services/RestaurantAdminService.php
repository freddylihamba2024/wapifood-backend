<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Common\Support\Money;
use App\Common\Validation\InputValidator;
use App\Common\Validation\WapiRules;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Restaurants\Models\RestaurantModel;
use App\Modules\Restaurants\Presenters\RestaurantPresenter;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use App\Modules\Reviews\Services\ReviewService;
use DateTimeImmutable;

/**
 * Administration des restaurants par WapiFood : validation, rejet, suspension, commission.
 *
 * Transitions autorisées :
 *   pending   → active (approve) | rejected (reject)
 *   active, paused → suspended (suspend)
 *   suspended → active (reactivate)
 */
final class RestaurantAdminService
{
    public const STATUSES = ['pending', 'active', 'paused', 'rejected', 'suspended'];

    public function __construct(
        private readonly RestaurantModel $restaurants = new RestaurantModel(),
        private readonly RestaurantRepository $repository = new RestaurantRepository(),
        private readonly OpeningHoursService $hours = new OpeningHoursService(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function list(array $filters, Pagination $pagination): array
    {
        if (($filters['status'] ?? '') !== '' && ! in_array($filters['status'], self::STATUSES, true)) {
            throw ApiException::validation(['status' => 'Statut inconnu.']);
        }

        [$rows, $meta] = $this->repository->searchAdmin($filters, $pagination);
        $rows          = (new ReviewService())->withRatings($rows);
        $ids           = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $addresses     = $this->repository->addressesFor($ids);
        $hours         = $this->repository->hoursFor($ids);
        $now           = new DateTimeImmutable();

        $items = array_map(fn (array $r): array => $this->present($r, $addresses[(int) $r['id']], $hours[(int) $r['id']], $now), $rows);

        return [$items, $meta];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $restaurantId): array
    {
        $row = db_connect()->table('wf_restaurants r')
            ->select('r.*, u.first_name AS owner_first_name, u.last_name AS owner_last_name, u.phone AS owner_phone, u.email AS owner_email')
            ->join('wf_users u', 'u.id = r.owner_user_id')
            ->where('r.id', $restaurantId)
            ->where('r.deleted_at', null)
            ->get()
            ->getRowArray() ?? throw ApiException::notFound('Restaurant introuvable.');

        return $this->present(
            (new ReviewService())->withRatings([$row])[0],
            $this->repository->addressesFor([$restaurantId])[$restaurantId],
            $this->repository->hoursFor([$restaurantId])[$restaurantId],
            new DateTimeImmutable(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $restaurantId, int $adminId): array
    {
        $this->transition($restaurantId, ['pending'], [
            'status'        => 'active',
            'status_reason' => null,
            'approved_at'   => gmdate('Y-m-d H:i:s'),
            'approved_by'   => $adminId,
        ], 'restaurant.approved', $adminId);

        return $this->show($restaurantId);
    }

    /**
     * @param array<string, mixed> $input { reason }
     *
     * @return array<string, mixed>
     */
    public function reject(int $restaurantId, int $adminId, array $input): array
    {
        $reason = $this->reason($input);
        $this->transition($restaurantId, ['pending'], ['status' => 'rejected', 'status_reason' => $reason], 'restaurant.rejected', $adminId, $reason);

        return $this->show($restaurantId);
    }

    /**
     * @param array<string, mixed> $input { reason }
     *
     * @return array<string, mixed>
     */
    public function suspend(int $restaurantId, int $adminId, array $input): array
    {
        $reason = $this->reason($input);
        $this->transition($restaurantId, ['active', 'paused'], ['status' => 'suspended', 'status_reason' => $reason], 'restaurant.suspended', $adminId, $reason);

        return $this->show($restaurantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivate(int $restaurantId, int $adminId): array
    {
        $this->transition($restaurantId, ['suspended'], ['status' => 'active', 'status_reason' => null], 'restaurant.reactivated', $adminId);

        return $this->show($restaurantId);
    }

    /**
     * Le nouveau taux s'applique aux commandes futures uniquement (le taux est figé sur chaque commande).
     *
     * @param array<string, mixed> $input { commission_rate }
     *
     * @return array<string, mixed>
     */
    public function setCommissionRate(int $restaurantId, int $adminId, array $input): array
    {
        $data       = InputValidator::validate($input, [
            'commission_rate' => ['label' => 'taux de commission', 'rules' => 'required|wf_money'],
        ]);
        $rate       = Money::normalize(WapiRules::numericString($data['commission_rate']));
        $restaurant = $this->repository->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');

        if (Money::toCents($rate) > 10000) {
            throw ApiException::validation(['commission_rate' => 'Le taux de commission doit être compris entre 0 et 100.']);
        }

        $this->restaurants->update($restaurantId, ['commission_rate' => $rate]);
        $this->audit->log('restaurant.commission_changed', $adminId, 'restaurant', $restaurantId, [
            'from' => $restaurant['commission_rate'],
            'to'   => $rate,
        ]);

        return $this->show($restaurantId);
    }

    /**
     * @param list<string>         $allowedFrom
     * @param array<string, mixed> $changes
     */
    private function transition(int $restaurantId, array $allowedFrom, array $changes, string $action, int $adminId, ?string $reason = null): void
    {
        $restaurant = $this->repository->find($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');

        if (! in_array($restaurant['status'], $allowedFrom, true)) {
            throw ApiException::conflict(
                "Action impossible : le restaurant est au statut « {$restaurant['status']} ».",
                ['status' => $restaurant['status']],
                'INVALID_STATUS_TRANSITION',
            );
        }

        $this->restaurants->update($restaurantId, $changes);
        $this->audit->log($action, $adminId, 'restaurant', $restaurantId, array_filter([
            'from'   => $restaurant['status'],
            'to'     => $changes['status'],
            'reason' => $reason,
        ]));

        // Actions : restaurant.approved | rejected | suspended | reactivated (Config\Notifications::$events).
        (new NotificationDispatcher())->restaurantEvent($action, $restaurantId, ['reason' => $reason]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reason(array $input): string
    {
        $data = InputValidator::validate($input, [
            'reason' => ['label' => 'motif', 'rules' => 'required|string|min_length[5]|max_length[500]'],
        ]);

        return trim((string) $data['reason']);
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $addresses
     * @param list<array<string, mixed>> $hours
     *
     * @return array<string, mixed>
     */
    private function present(array $row, array $addresses, array $hours, DateTimeImmutable $now): array
    {
        return RestaurantPresenter::management($row, $addresses, $hours, $this->hours->isOpenAt($hours, $row['timezone'], $now)) + [
            'owner' => [
                'id'         => (int) $row['owner_user_id'],
                'first_name' => $row['owner_first_name'],
                'last_name'  => $row['owner_last_name'],
                'phone'      => $row['owner_phone'],
                'email'      => $row['owner_email'],
            ],
        ];
    }
}
