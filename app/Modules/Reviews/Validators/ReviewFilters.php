<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Validators;

use App\Common\Exceptions\ApiException;
use App\Modules\Reviews\Services\ReviewService;

/**
 * Filtres des listes d'avis : ?status=published|hidden|pending&rating=1..5[&restaurant_id=…]
 */
final class ReviewFilters
{
    /**
     * @param array<string, mixed> $query
     *
     * @return array{status: string|null, rating: int|null, restaurant_id?: int|null}
     */
    public static function fromQuery(array $query, bool $withRestaurant): array
    {
        $status = $query['status'] ?? '';
        $rating = $query['rating'] ?? '';
        $errors = [];

        if ($status !== '' && ! in_array($status, ReviewService::STATUSES, true)) {
            $errors['status'] = 'Statut inconnu (published, hidden ou pending).';
        }

        if ($rating !== '' && (! is_string($rating) || preg_match('/\A[1-5]\z/', $rating) !== 1)) {
            $errors['rating'] = 'La note doit être un entier de 1 à 5.';
        }

        $filters = ['status' => $status === '' ? null : (string) $status, 'rating' => $rating === '' ? null : (int) $rating];

        if ($withRestaurant) {
            $restaurantId = $query['restaurant_id'] ?? '';

            if ($restaurantId !== '' && (! is_string($restaurantId) || preg_match('/\A[1-9]\d{0,17}\z/', $restaurantId) !== 1)) {
                $errors['restaurant_id'] = 'Identifiant de restaurant invalide.';
            }

            $filters['restaurant_id'] = $restaurantId === '' || isset($errors['restaurant_id']) ? null : (int) $restaurantId;
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $filters;
    }
}
