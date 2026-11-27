<?php

declare(strict_types=1);

namespace App\Libraries\Upload;

use App\Common\Exceptions\ApiException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Uploads;
use GdImage;
use RuntimeException;

/**
 * Service centralisé des fichiers téléversés (images).
 *
 * Sécurité :
 * - type MIME réel (finfo) + liste blanche ; l'extension et le type déclarés par le client sont ignorés ;
 * - taille et dimensions maximales ;
 * - l'image est décodée puis RÉ-ENCODÉE avec GD : métadonnées (EXIF, GPS) supprimées,
 *   fichiers polyglottes (image + script) neutralisés ;
 * - nom généré côté serveur (aléatoire), extension dérivée du type réel ;
 * - stockage dans writable/uploads (hors du dossier public, jamais exécutable).
 *
 * Retourne un chemin relatif (« product-images/2026/11/ab12….webp ») à enregistrer en base.
 */
final class UploadService
{
    /** @var array<string, string> type MIME → extension de sortie */
    private const OUTPUT_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly Uploads $config = new Uploads())
    {
    }

    /**
     * @throws ApiException 422 si le fichier est absent ou refusé
     */
    public function storeImage(?UploadedFile $file, string $category, string $field = 'file'): string
    {
        if (! isset($this->config->categories[$category])) {
            throw new RuntimeException("Catégorie d'upload inconnue : {$category}");
        }

        $source = $this->validatedSource($file, $field);
        $mime   = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($source);

        if (! in_array($mime, $this->config->allowedTypes, true)) {
            throw $this->reject($field, 'Type de fichier non autorisé. Formats acceptés : ' . implode(', ', array_keys($this->config->allowedTypes)) . '.');
        }

        $size = @getimagesize($source);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw $this->reject($field, 'Le fichier n\'est pas une image valide.');
        }

        if ($size[0] > $this->config->maxDimension || $size[1] > $this->config->maxDimension) {
            throw $this->reject($field, "Image trop grande (maximum {$this->config->maxDimension} × {$this->config->maxDimension} pixels).");
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));

        if (! $image instanceof GdImage) {
            throw $this->reject($field, 'Le fichier n\'est pas une image valide.');
        }

        $relative = sprintf('%s/%s/%s.%s', $category, gmdate('Y/m'), bin2hex(random_bytes(16)), self::OUTPUT_EXTENSIONS[$mime]);
        $target   = $this->config->rootPath() . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('Impossible de créer le dossier de stockage des fichiers.');
        }

        try {
            $written = match ($mime) {
                'image/jpeg' => imagejpeg($image, $target, 85),
                'image/png'  => $this->writePng($image, $target),
                'image/webp' => imagewebp($image, $target, 85),
            };
        } finally {
            imagedestroy($image);
        }

        if (! $written) {
            throw new RuntimeException('Échec de l\'enregistrement de l\'image.');
        }

        chmod($target, 0644);

        return $relative;
    }

    /**
     * Supprime un fichier précédemment stocké. Ignore silencieusement les chemins invalides
     * ou externes (aucune suppression possible hors de writable/uploads).
     */
    public function delete(?string $relativePath): void
    {
        $path = $this->resolve($relativePath);

        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Chemin absolu d'un fichier stocké, ou null s'il est invalide ou hors du dossier d'upload.
     */
    public function resolve(?string $relativePath, bool $publicOnly = false): ?string
    {
        if ($relativePath === null || preg_match('#\A([a-z-]+)/\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp)\z#', $relativePath, $match) !== 1) {
            return null;
        }

        $category = $this->config->categories[$match[1]] ?? null;

        if ($category === null || ($publicOnly && ! $category['public'])) {
            return null;
        }

        $root = realpath($this->config->rootPath());
        $path = realpath($this->config->rootPath() . $relativePath);

        return ($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR)) ? $path : null;
    }

    /** URL publique d'un fichier stocké (les URL externes complètes sont renvoyées telles quelles). */
    public static function url(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return preg_match('#\Ahttps?://#i', $stored) === 1 ? $stored : site_url('media/' . $stored);
    }

    private function validatedSource(?UploadedFile $file, string $field): string
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw $this->reject($field, 'Aucun fichier reçu.');
        }

        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || $file->getSize() > $this->config->maxSizeKb * 1024) {
            throw $this->reject($field, "Fichier trop volumineux (maximum {$this->config->maxSizeKb} Ko).");
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw $this->reject($field, 'Le téléversement a échoué. Réessayez.');
        }

        $source = $file->getTempName();

        // is_uploaded_file() garantit que le fichier vient bien d'un envoi HTTP (impossible à simuler en test).
        if ($source === '' || ! is_file($source) || (ENVIRONMENT !== 'testing' && ! is_uploaded_file($source))) {
            throw $this->reject($field, 'Le téléversement a échoué. Réessayez.');
        }

        return $source;
    }

    private function writePng(GdImage $image, string $target): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, $target, 6);
    }

    private function reject(string $field, string $message): ApiException
    {
        return new ApiException($message, 422, [$field => $message], 'UPLOAD_REJECTED');
    }
}
