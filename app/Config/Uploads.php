<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Téléversement de fichiers (images uniquement au MVP).
 *
 * Surcharges `.env` : UPLOAD_MAX_SIZE (Ko), UPLOAD_ALLOWED_TYPES (liste d'extensions).
 */
class Uploads extends BaseConfig
{
    /** Taille maximale d'un fichier, en kilo-octets. */
    public int $maxSizeKb = 2048;

    /** Largeur et hauteur maximales acceptées, en pixels (protection contre les « bombes » de décompression). */
    public int $maxDimension = 4000;

    /**
     * Types autorisés : extension → type MIME réel attendu (détecté par finfo, jamais déclaré par le client).
     *
     * @var array<string, string>
     */
    public array $allowedTypes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * Catégories de fichiers. « public » = servi par GET /media/… ; sinon accessible uniquement via l'API.
     *
     * @var array<string, array{public: bool}>
     */
    public array $categories = [
        'restaurant-logos'  => ['public' => true],
        'restaurant-covers' => ['public' => true],
        'category-images'   => ['public' => true],
        'product-images'    => ['public' => true],
        'avatars'           => ['public' => true],
        'delivery-proofs'   => ['public' => false],
    ];

    public function __construct()
    {
        parent::__construct();

        $maxSize = env('UPLOAD_MAX_SIZE');

        if (is_numeric($maxSize) && (int) $maxSize > 0) {
            $this->maxSizeKb = (int) $maxSize;
        }

        $allowed = env('UPLOAD_ALLOWED_TYPES');

        if (is_string($allowed) && trim($allowed) !== '') {
            $extensions         = array_map(static fn (string $e): string => strtolower(trim($e)), explode(',', $allowed));
            $this->allowedTypes = array_intersect_key($this->allowedTypes, array_flip($extensions));
        }
    }

    /** Dossier de stockage (hors du dossier public). Les tests utilisent un dossier séparé, vidé après chaque test. */
    public function rootPath(): string
    {
        return WRITEPATH . (ENVIRONMENT === 'testing' ? 'uploads-testing' : 'uploads') . DIRECTORY_SEPARATOR;
    }
}
