<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Upload\UploadService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Diffusion des images publiques stockées dans writable/uploads : GET /media/{chemin}.
 *
 * Seuls les chemins générés par UploadService et les catégories publiques sont servis
 * (aucune traversée de répertoire possible, aucun fichier privé comme les preuves de livraison).
 */
final class Media extends BaseController
{
    /** @var array<string, string> */
    private const TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function show(string ...$segments): ResponseInterface
    {
        $relative = implode('/', $segments);
        $path     = (new UploadService())->resolve($relative, publicOnly: true);

        if ($path === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Noms de fichiers aléatoires et jamais réécrits : mise en cache longue durée sans risque.
        $this->response->removeHeader('Cache-Control');

        return $this->response
            ->setHeader('Content-Type', self::TYPES[pathinfo($path, PATHINFO_EXTENSION)])
            ->setHeader('Content-Length', (string) filesize($path))
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'")
            ->setHeader('Content-Disposition', 'inline')
            ->setBody((string) file_get_contents($path));
    }
}
