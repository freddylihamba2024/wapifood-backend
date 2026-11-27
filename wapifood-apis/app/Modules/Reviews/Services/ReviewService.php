<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Common\Exceptions\ApiException;
use App\Common\Http\Pagination;
use App\Common\Validation\InputValidator;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Orders\Repositories\OrderRepository;
use App\Modules\Reviews\Models\ReviewModel;
use App\Modules\Users\Presenters\UserPresenter;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;
use Config\WapiFood;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Avis clients.
 *
 * - Un avis par commande (index unique), uniquement pour une commande TERMINÉE du client,
 *   dans les reviewWindowDays jours qui suivent (Ph7-6). Ni modification ni suppression par le client.
 * - Modération a posteriori (Ph7-3) : publié immédiatement ; l'administration peut le masquer
 *   (motif obligatoire) ou le republier. Un avis masqué n'apparaît plus publiquement ni dans la note.
 * - Public : prénom et initiale du nom de l'auteur seulement.
 */
final class ReviewService
{
    public const STATUSES = ['published', 'hidden', 'pending'];

    private const DUPLICATE_KEY_ERROR = 1062;

    private BaseConnection $db;

    public function __construct(
        private readonly ReviewModel $reviews = new ReviewModel(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    // ------------------------------------------------------------------
    // Client
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input { rating, comment? }
     *
     * @return array<string, mixed>
     */
    public function create(int $customerId, int $orderId, array $input): array
    {
        $data  = InputValidator::validate($input, [
            'rating'  => ['label' => 'note', 'rules' => 'required|wf_int|greater_than_equal_to[1]|less_than_equal_to[5]'],
            'comment' => ['label' => 'commentaire', 'rules' => 'permit_empty|string|max_length[1000]'],
        ]);
        $order = $this->orders->findForCustomer($customerId, $orderId) ?? throw ApiException::notFound('Commande introuvable.');

        if ($this->findByOrder($orderId) !== null) {
            throw self::alreadyReviewed();
        }

        $this->assertReviewable($order);

        $comment = trim((string) ($data['comment'] ?? ''));

        try {
            $id = (int) $this->reviews->insert([
                'order_id'      => $orderId,
                'customer_id'   => $customerId,
                'restaurant_id' => (int) $order['restaurant_id'],
                'rating'        => (int) $data['rating'],
                'comment'       => $comment === '' ? null : $comment,
                'status'        => 'published',
            ]);
        } catch (DatabaseException $exception) {
            // Deux envois simultanés : l'index unique (order_id) garde le premier.
            throw (int) $exception->getCode() === self::DUPLICATE_KEY_ERROR ? self::alreadyReviewed() : $exception;
        }

        $this->audit->log('review.created', $customerId, 'review', $id, ['order_id' => $orderId, 'rating' => (int) $data['rating']]);
        (new NotificationDispatcher())->orderEvent('review.created', $orderId, ['rating' => (int) $data['rating']]);

        return self::present($this->detailed()->where('rv.id', $id)->get()->getRowArray(), 'customer');
    }

    /**
     * État de l'avis dans le détail d'une commande côté client.
     *
     * @param array<string, mixed> $order
     *
     * @return array{review: array<string, mixed>|null, can_review: bool}
     */
    public function reviewStateForOrder(array $order): array
    {
        $review = $this->detailed()->where('rv.order_id', (int) $order['id'])->get()->getRowArray();

        return [
            'review'     => $review === null ? null : self::present($review, 'customer'),
            'can_review' => $review === null && $this->reviewable($order),
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function listForCustomer(int $customerId, Pagination $pagination): array
    {
        [$rows, $meta] = $pagination->run($this->detailed()->where('rv.customer_id', $customerId)->orderBy('rv.id', 'DESC'));

        return [array_map(static fn (array $r): array => self::present($r, 'customer'), $rows), $meta];
    }

    // ------------------------------------------------------------------
    // Public et restaurant
    // ------------------------------------------------------------------

    /**
     * Avis publiés d'un restaurant (le contrôle de visibilité du restaurant est fait par l'appelant).
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: array{average: string|null, count: int}}
     */
    public function listPublic(int $restaurantId, Pagination $pagination): array
    {
        $builder       = $this->detailed()->where('rv.restaurant_id', $restaurantId)->where('rv.status', 'published')->orderBy('rv.id', 'DESC');
        [$rows, $meta] = $pagination->run($builder);

        return [
            array_map(static fn (array $r): array => self::present($r, 'public'), $rows),
            $meta,
            $this->ratingsFor([$restaurantId])[$restaurantId],
        ];
    }

    /**
     * Tous les avis du restaurant, masqués compris (avec leur statut).
     *
     * @param array{status?: string|null, rating?: int|null} $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: array{average: string|null, count: int}}
     */
    public function listForRestaurant(int $restaurantId, array $filters, Pagination $pagination): array
    {
        $builder = $this->detailed()->where('rv.restaurant_id', $restaurantId)->orderBy('rv.id', 'DESC');
        $this->filter($builder, $filters);
        [$rows, $meta] = $pagination->run($builder);

        return [
            array_map(static fn (array $r): array => self::present($r, 'restaurant'), $rows),
            $meta,
            $this->ratingsFor([$restaurantId])[$restaurantId],
        ];
    }

    /**
     * Note moyenne (1 décimale) et nombre d'avis publiés, par restaurant.
     *
     * @param list<int> $restaurantIds
     *
     * @return array<int, array{average: string|null, count: int}>
     */
    public function ratingsFor(array $restaurantIds): array
    {
        $ratings = array_fill_keys($restaurantIds, ['average' => null, 'count' => 0]);

        if ($restaurantIds === []) {
            return $ratings;
        }

        $rows = $this->db->table('wf_reviews')
            ->select('restaurant_id, COUNT(*) AS n, SUM(rating) AS total')
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('status', 'published')
            ->groupBy('restaurant_id')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $count = (int) $row['n'];
            // Moyenne arrondie au dixième, en entiers : (10 × total + n/2) / n.
            $tenths = intdiv(10 * (int) $row['total'] * 2 + $count, 2 * $count);

            $ratings[(int) $row['restaurant_id']] = ['average' => intdiv($tenths, 10) . '.' . ($tenths % 10), 'count' => $count];
        }

        return $ratings;
    }

    /**
     * Ajoute la note (rating_average, rating_count) à des lignes wf_restaurants, en une requête.
     *
     * @param list<array<string, mixed>> $restaurants
     *
     * @return list<array<string, mixed>>
     */
    public function withRatings(array $restaurants): array
    {
        $ratings = $this->ratingsFor(array_map(static fn (array $r): int => (int) $r['id'], $restaurants));

        return array_map(static fn (array $r): array => $r + [
            'rating_average' => $ratings[(int) $r['id']]['average'],
            'rating_count'   => $ratings[(int) $r['id']]['count'],
        ], $restaurants);
    }

    // ------------------------------------------------------------------
    // Administration
    // ------------------------------------------------------------------

    /**
     * @param array{status?: string|null, rating?: int|null, restaurant_id?: int|null} $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function listForAdmin(array $filters, Pagination $pagination): array
    {
        $builder = $this->detailed()->orderBy('rv.id', 'DESC');
        $this->filter($builder, $filters);

        if (($filters['restaurant_id'] ?? null) !== null) {
            $builder->where('rv.restaurant_id', $filters['restaurant_id']);
        }

        [$rows, $meta] = $pagination->run($builder);

        return [array_map(static fn (array $r): array => self::present($r, 'admin'), $rows), $meta];
    }

    /**
     * Masque ou republie un avis. Le motif est obligatoire pour masquer.
     *
     * @param array<string, mixed> $input { status: hidden|published, reason? }
     *
     * @return array<string, mixed>
     */
    public function moderate(int $adminId, int $reviewId, array $input): array
    {
        $data   = InputValidator::validate($input, [
            'status' => ['label' => 'statut', 'rules' => 'required|in_list[hidden,published]'],
            'reason' => ['label' => 'motif', 'rules' => 'permit_empty|string|max_length[500]'],
        ]);
        $review = $this->reviews->find($reviewId) ?? throw ApiException::notFound('Avis introuvable.');
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($data['status'] === 'hidden' && mb_strlen($reason) < 3) {
            throw ApiException::validation(['reason' => 'Indiquez le motif du masquage.']);
        }

        $this->reviews->update($reviewId, [
            'status'            => $data['status'],
            'moderated_at'      => Time::now('UTC')->toDateTimeString(),
            'moderated_by'      => $adminId,
            'moderation_reason' => $reason === '' ? null : $reason,
        ]);

        $this->audit->log('review.moderated', $adminId, 'review', $reviewId, [
            'from'   => $review['status'],
            'to'     => $data['status'],
            'reason' => $reason === '' ? null : $reason,
        ]);

        return self::present($this->detailed()->where('rv.id', $reviewId)->get()->getRowArray(), 'admin');
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $order
     */
    private function assertReviewable(array $order): void
    {
        if ($order['status'] !== 'completed') {
            throw ApiException::conflict('Vous pourrez donner votre avis une fois la commande terminée.', ['current_status' => $order['status']], 'ORDER_NOT_COMPLETED');
        }

        if (! $this->reviewable($order)) {
            throw ApiException::conflict(
                'Le délai pour donner un avis sur cette commande est dépassé (' . config(WapiFood::class)->reviewWindowDays . ' jours).',
                [],
                'REVIEW_WINDOW_CLOSED',
            );
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    private function reviewable(array $order): bool
    {
        if ($order['status'] !== 'completed' || $order['completed_at'] === null) {
            return false;
        }

        $deadline = (new DateTimeImmutable((string) $order['completed_at'], new DateTimeZone('UTC')))
            ->modify('+' . config(WapiFood::class)->reviewWindowDays . ' days');

        return new DateTimeImmutable('now', new DateTimeZone('UTC')) <= $deadline;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByOrder(int $orderId): ?array
    {
        return $this->reviews->where('order_id', $orderId)->first();
    }

    private function detailed(): BaseBuilder
    {
        return $this->db->table('wf_reviews rv')
            ->select('rv.*, o.order_number, u.first_name, u.last_name, r.name AS restaurant_name')
            ->join('wf_orders o', 'o.id = rv.order_id')
            ->join('wf_users u', 'u.id = rv.customer_id')
            ->join('wf_restaurants r', 'r.id = rv.restaurant_id');
    }

    /**
     * @param array{status?: string|null, rating?: int|null} $filters
     */
    private function filter(BaseBuilder $builder, array $filters): void
    {
        if (($filters['status'] ?? null) !== null) {
            $builder->where('rv.status', $filters['status']);
        }

        if (($filters['rating'] ?? null) !== null) {
            $builder->where('rv.rating', $filters['rating']);
        }
    }

    /**
     * @param array<string, mixed> $row  Avis joint à la commande, au client et au restaurant
     * @param string               $view public | customer | restaurant | admin
     *
     * @return array<string, mixed>
     */
    public static function present(array $row, string $view): array
    {
        $initial = trim((string) ($row['last_name'] ?? '')) === '' ? '' : ' ' . mb_strtoupper(mb_substr(trim((string) $row['last_name']), 0, 1)) . '.';

        $data = [
            'id'         => (int) $row['id'],
            'rating'     => (int) $row['rating'],
            'comment'    => $row['comment'],
            'author'     => trim((string) $row['first_name']) . $initial,
            'created_at' => UserPresenter::isoDate($row['created_at']),
        ];

        if ($view === 'public') {
            return $data;
        }

        $data['status']       = $row['status'];
        $data['order_id']     = (int) $row['order_id'];
        $data['order_number'] = $row['order_number'];

        if ($view === 'customer' || $view === 'admin') {
            $data['restaurant'] = ['id' => (int) $row['restaurant_id'], 'name' => $row['restaurant_name']];
        }

        if ($view === 'admin') {
            $data['customer_id']       = (int) $row['customer_id'];
            $data['moderated_at']      = UserPresenter::isoDate($row['moderated_at']);
            $data['moderation_reason'] = $row['moderation_reason'];
        }

        return $data;
    }

    private static function alreadyReviewed(): ApiException
    {
        return ApiException::conflict('Vous avez déjà donné votre avis sur cette commande.', [], 'REVIEW_ALREADY_EXISTS');
    }
}
