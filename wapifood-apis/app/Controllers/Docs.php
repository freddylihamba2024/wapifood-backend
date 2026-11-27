<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\WapiFood;

/**
 * Documentation de l'API (Swagger UI + spécification OpenAPI).
 *
 * Désactivée en production (Config\WapiFood::$docsEnabled) : renvoie alors 404.
 */
final class Docs extends BaseController
{
    private const SPEC_PATH = ROOTPATH . 'docs/api/openapi.yaml';

    public function index(): string
    {
        $this->ensureEnabled();

        return view('docs/swagger', ['specUrl' => site_url('docs/openapi.yaml')]);
    }

    public function spec(): ResponseInterface
    {
        $this->ensureEnabled();

        if (! is_file(self::SPEC_PATH)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', 'application/yaml; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody((string) file_get_contents(self::SPEC_PATH));
    }

    private function ensureEnabled(): void
    {
        if (! config(WapiFood::class)->docsEnabled) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
