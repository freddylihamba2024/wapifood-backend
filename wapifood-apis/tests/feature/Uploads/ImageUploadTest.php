<?php

declare(strict_types=1);

namespace Tests\Feature\Uploads;

use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Uploads;
use Tests\Support\ApiTestCase;

/**
 * Téléversement et diffusion des images.
 *
 * @internal
 */
final class ImageUploadTest extends ApiTestCase
{
    /** @var list<string> */
    private array $stored = [];

    protected function tearDown(): void
    {
        foreach ($this->stored as $relative) {
            @unlink((new Uploads())->rootPath() . $relative);
        }

        parent::tearDown();
    }

    private function storedPath(string $url): string
    {
        $relative       = substr($url, strpos($url, '/media/') + strlen('/media/'));
        $this->stored[] = $relative;

        return $relative;
    }

    public function testLogoIsReencodedStoredOutsidePublicAndServed(): void
    {
        $owner = $this->ownerWithRestaurant();

        // Une image PNG suivie d'un script PHP (fichier « polyglotte »).
        $this->fakeUpload('file', self::pngBytes() . '<?php echo "pwned"; ?>', 'logo.php.png');
        $response = $this->upload('api/v1/restaurant/logo', $owner['token']);

        $response->assertStatus(200);
        $url      = $this->body($response)['data']['logo_url'];
        $relative = $this->storedPath($url);

        $this->assertMatchesRegularExpression('#/media/restaurant-logos/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $url);
        $this->assertFileExists((new Uploads())->rootPath() . $relative);
        $this->assertStringNotContainsString('<?php', (string) file_get_contents((new Uploads())->rootPath() . $relative), 'Le ré-encodage supprime le contenu ajouté.');

        $served = $this->get('media/' . $relative);
        $served->assertStatus(200);
        $served->assertHeader('Content-Type', 'image/png');
        $served->assertHeader('X-Content-Type-Options', 'nosniff');
        $served->assertHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    public function testRejectsNonImagesAndDisguisedFiles(): void
    {
        $owner = $this->ownerWithRestaurant();

        $this->fakeUpload('file', '<?php system($_GET["c"]); ?>', 'shell.png', 'image/png');
        $fake = $this->upload('api/v1/restaurant/logo', $owner['token']);
        $fake->assertStatus(422);
        $this->assertSame('UPLOAD_REJECTED', $this->body($fake)['meta']['code']);

        $this->fakeUpload('file', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg', 'image/svg+xml');
        $this->upload('api/v1/restaurant/logo', $owner['token'])->assertStatus(422);

        service('superglobals')->setFilesArray([]);
        $this->upload('api/v1/restaurant/logo', $owner['token'])->assertStatus(422);

        $this->seeInDatabase('wf_restaurants', ['id' => $owner['restaurant_id'], 'logo_url' => null]);
    }

    public function testRejectsOversizedFiles(): void
    {
        $owner = $this->ownerWithRestaurant();

        $this->fakeUpload('file', self::pngBytes() . str_repeat("\0", 2049 * 1024));
        $response = $this->upload('api/v1/restaurant/logo', $owner['token']);

        $response->assertStatus(422);
        $this->assertStringContainsString('volumineux', $this->body($response)['message']);
    }

    public function testProductImagesAndMainImage(): void
    {
        $owner   = $this->ownerWithRestaurant();
        $product = $this->body($this->json('POST', 'api/v1/restaurant/products', ['name' => 'Pondu', 'base_price' => '4'], $owner['token']))['data'];

        $this->fakeUpload('file', self::pngBytes());
        $first = $this->body($this->upload("api/v1/restaurant/products/{$product['id']}/images", $owner['token'], ['alt_text' => 'Assiette de pondu']))['data'];
        $this->fakeUpload('file', self::pngBytes(60, 60));
        $second = $this->body($this->upload("api/v1/restaurant/products/{$product['id']}/images", $owner['token']))['data'];

        foreach ($second['images'] as $image) {
            $this->storedPath($image['url']);
        }

        $this->assertSame($first['images'][0]['url'], $second['image_url'], 'La première image devient l\'image principale.');
        $this->assertSame('Assiette de pondu', $second['images'][0]['alt_text']);

        $after = $this->body($this->json('DELETE', "api/v1/restaurant/products/{$product['id']}/images/{$second['images'][0]['id']}", [], $owner['token']))['data'];
        $this->assertSame($second['images'][1]['url'], $after['image_url']);
        $this->assertFileDoesNotExist((new Uploads())->rootPath() . $this->storedPath($second['images'][0]['url']));
    }

    public function testCannotUploadToAnotherRestaurantsProduct(): void
    {
        $alice   = $this->ownerWithRestaurant();
        $bob     = $this->ownerWithRestaurant();
        $product = $this->body($this->json('POST', 'api/v1/restaurant/products', ['name' => 'Bob', 'base_price' => '4'], $bob['token']))['data'];

        $this->fakeUpload('file', self::pngBytes());
        $this->upload("api/v1/restaurant/products/{$product['id']}/images", $alice['token'])->assertStatus(404);
    }

    public function testMediaRouteRefusesTraversalAndPrivateFiles(): void
    {
        foreach ([
            'media/../.env',
            'media/restaurant-logos/../../.env',
            'media/delivery-proofs/2026/11/' . str_repeat('a', 32) . '.png',
            'media/restaurant-logos/2026/11/inexistant.png',
        ] as $path) {
            try {
                $this->get($path)->assertStatus(404);
            } catch (PageNotFoundException) {
                // « media/../.env » est normalisé en « .env » : aucune route ne correspond.
                $this->addToAssertionCount(1);
            }
        }
    }
}
