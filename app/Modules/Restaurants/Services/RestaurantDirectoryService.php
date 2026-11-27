<?php

declare(strict_types=1);

namespace App\Modules\Restaurants\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Modules\Menus\Services\CategoryService;
use App\Modules\Products\Services\ProductService;
use App\Modules\Restaurants\Presenters\RestaurantPresenter;
use App\Modules\Restaurants\Repositories\RestaurantRepository;
use App\Modules\Reviews\Services\ReviewService;
use DateTimeImmutable;

/**
 * Consultation publique des restaurants et de leurs menus (sans authentification).
 *
 * Seuls les restaurants validés sont visibles (actifs, ou fermés temporairement) ;
 * un restaurant en attente, refusé ou suspendu est introuvable (404).
 */
final class RestaurantDirectoryService
{
    public function __construct(
        private readonly RestaurantRepository $repository = new RestaurantRepository(),
        private readonly OpeningHoursService $hours = new OpeningHoursService(),
        private readonly ReviewService $reviews = new ReviewService(),
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function list(array $filters, Pagination $pagination): array
    {
        [$rows, $meta] = $this->repository->searchPublic($filters, $pagination);
        $rows          = $this->reviews->withRatings($rows);
        $ids           = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $addresses     = $this->repository->addressesFor($ids);
        $hours         = $this->repository->hoursFor($ids);
        $now           = new DateTimeImmutable();

        $items = array_map(fn (array $r): array => RestaurantPresenter::summary(
            $r,
            $addresses[(int) $r['id']][0] ?? null,
            $this->hours->isOpenAt($hours[(int) $r['id']], $r['timezone'], $now),
        ), $rows);

        return [$items, $meta];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $restaurantId): array
    {
        $restaurant = $this->visible($restaurantId);
        $hours      = $this->repository->hoursFor([$restaurantId])[$restaurantId];

        return RestaurantPresenter::publicDetail(
            $this->reviews->withRatings([$restaurant])[0],
            $this->repository->addressesFor([$restaurantId])[$restaurantId],
            $hours,
            $this->hours->isOpenAt($hours, $restaurant['timezone'], new DateTimeImmutable()),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(int $restaurantId): array
    {
        $this->visible($restaurantId);

        return (new CategoryService())->list($restaurantId, activeOnly: true);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function products(int $restaurantId, array $filters, Pagination $pagination): array
    {
        $this->visible($restaurantId);

        return (new ProductService())->list($restaurantId, $filters, $pagination, publicOnly: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function product(int $restaurantId, int $productId): array
    {
        $this->visible($restaurantId);

        return (new ProductService())->show($restaurantId, $productId, publicOnly: true);
    }

    /**
     * Avis publiés du restaurant, avec sa note moyenne.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: array{average: string|null, count: int}}
     */
    public function reviews(int $restaurantId, Pagination $pagination): array
    {
        $this->visible($restaurantId);

        return $this->reviews->listPublic($restaurantId, $pagination);
    }

    /**
     * @return array<string, mixed>
     */
    private function visible(int $restaurantId): array
    {
        return $this->repository->findPublic($restaurantId) ?? throw ApiException::notFound('Restaurant introuvable.');
    }
}
