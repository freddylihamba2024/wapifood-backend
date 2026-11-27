<?php

declare(strict_types=1);

namespace App\Common\Http;

use App\Common\Exceptions\ApiException;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Pagination des listes : ?page=1&per_page=20 (100 maximum).
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 100;

    public function __construct(public readonly int $page = 1, public readonly int $perPage = self::DEFAULT_PER_PAGE)
    {
    }

    public static function fromRequest(IncomingRequest $request): self
    {
        $page    = $request->getGet('page') ?? '1';
        $perPage = $request->getGet('per_page') ?? (string) self::DEFAULT_PER_PAGE;

        if (! is_string($page) || ! ctype_digit($page) || (int) $page < 1
            || ! is_string($perPage) || ! ctype_digit($perPage) || (int) $perPage < 1) {
            throw ApiException::validation(['page' => 'page et per_page doivent être des entiers positifs.']);
        }

        return new self((int) $page, min(self::MAX_PER_PAGE, (int) $perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Exécute la requête paginée.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>} [lignes, meta]
     */
    public function run(BaseBuilder $builder): array
    {
        $total = $builder->countAllResults(false);
        $rows  = $builder->limit($this->perPage, $this->offset())->get()->getResultArray();

        return [$rows, $this->meta($total)];
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int}
     */
    public function meta(int $total): array
    {
        return [
            'page'        => $this->page,
            'per_page'    => $this->perPage,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $this->perPage),
        ];
    }
}
