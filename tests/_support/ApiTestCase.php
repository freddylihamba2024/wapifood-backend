<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Config\Uploads;
use Config\WapiFood;

/**
 * Base des tests HTTP de l'API (base wapifood_test, codes OTP capturés, limitation désactivée).
 *
 * @internal
 */
abstract class ApiTestCase extends DatabaseTestCase
{
    use FeatureTestTrait;

    public const PASSWORD = 'Kinshasa-Secure-2026';

    protected CapturingAuthCodeSender $codes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyTables([
            'wf_auth_codes', 'wf_refresh_tokens', 'wf_audit_logs', 'wf_notification_attempts', 'wf_notifications',
            'wf_reviews', 'wf_promotion_usages', 'wf_promotions', 'wf_deliveries',
            'wf_restaurant_ledger_entries', 'wf_restaurant_balances', 'wf_commissions',
            'wf_payment_refunds', 'wf_payment_webhooks',
            'wf_payments', 'wf_order_status_history', 'wf_order_items', 'wf_orders',
            'wf_customer_favorites', 'wf_customer_addresses',
            'wf_product_options', 'wf_product_option_groups', 'wf_product_variants', 'wf_product_images', 'wf_products',
            'wf_menu_categories', 'wf_restaurant_opening_hours', 'wf_restaurant_addresses', 'wf_restaurant_users',
            'wf_restaurants', 'wf_user_roles', 'wf_users',
        ]);
        service('superglobals')->setFilesArray([]);

        $config                   = config(WapiFood::class);
        $config->rateLimitEnabled = false;
        Factories::injectMock('config', WapiFood::class, $config);

        $this->codes = new CapturingAuthCodeSender();
        Services::injectMock('authCodeSender', $this->codes);
    }

    protected function tearDown(): void
    {
        Factories::reset('config');
        self::clearTestUploads((new Uploads())->rootPath());
        parent::tearDown();
    }

    /** Vide le dossier d'upload réservé aux tests (jamais writable/uploads). */
    private static function clearTestUploads(string $path): void
    {
        if (! str_ends_with(rtrim($path, DIRECTORY_SEPARATOR), 'uploads-testing') || ! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function json(string $method, string $path, array $body = [], ?string $token = null, array $headers = []): TestResponse
    {
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // Corps JSON brut, comme l'envoie le frontend (FeatureTestTrait recopierait sinon les
        // paramètres dans $_POST, ce qui n'arrive jamais avec une vraie requête JSON).
        $headers['Content-Type'] = 'application/json';

        return $this->withHeaders($headers)
            ->withBodyFormat('')
            ->withBody(strtoupper($method) === 'GET' ? '' : (string) json_encode($body))
            ->call($method, $path);
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(TestResponse $response): array
    {
        return json_decode((string) $response->getJSON(), true) ?? [];
    }

    /**
     * Inscrit un utilisateur via l'API.
     *
     * @return array{user: array<string, mixed>, tokens: array<string, mixed>, phone: string}
     */
    protected function registerUser(string $accountType = 'customer', bool $verifyPhone = true, ?string $email = null): array
    {
        $phone    = $this->uniquePhone();
        $response = $this->json('POST', 'api/v1/auth/register', array_filter([
            'first_name'   => 'Test',
            'last_name'    => 'Utilisateur',
            'email'        => $email,
            'phone'        => $phone,
            'password'     => self::PASSWORD,
            'account_type' => $accountType,
        ]));

        $response->assertStatus(201);
        $data = $this->body($response)['data'];

        if ($verifyPhone) {
            $this->json('POST', 'api/v1/auth/verify-phone', ['code' => $this->codes->lastCode('phone_verification')], $data['tokens']['access_token'])
                ->assertStatus(200);
            $data['user']['status'] = 'active';
        }

        return ['user' => $data['user'], 'tokens' => $data['tokens'], 'phone' => $phone];
    }

    /** Crée un restaurant de test appartenant à $ownerId et retourne son identifiant. */
    protected function createRestaurant(int $ownerId, string $status = 'active'): int
    {
        $this->db->table('wf_restaurants')->insert([
            'owner_user_id' => $ownerId,
            'name'          => 'Restaurant ' . $ownerId,
            'slug'          => 'resto-' . bin2hex(random_bytes(5)),
            'phone'         => $this->uniquePhone(),
            'status'        => $status,
        ]);
        $id = (int) $this->db->insertID();

        $this->db->table('wf_restaurant_users')->insert([
            'restaurant_id'   => $id,
            'user_id'         => $ownerId,
            'restaurant_role' => 'owner',
        ]);

        return $id;
    }

    /** Compte super administrateur vérifié ; renvoie son jeton d'accès. */
    protected function adminToken(): string
    {
        $admin = $this->registerUser();
        $this->db->table('wf_user_roles')->insert(['user_id' => $admin['user']['id'], 'role_id' => 5]);

        return $admin['tokens']['access_token'];
    }

    /**
     * Propriétaire vérifié + restaurant inscrit via l'API, puis placé au statut demandé.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{token: string, user_id: int, restaurant_id: int, phone: string}
     */
    protected function ownerWithRestaurant(string $status = 'active', array $overrides = []): array
    {
        $owner    = $this->registerUser('restaurant_owner');
        $response = $this->json('POST', 'api/v1/restaurants', $overrides + [
            'name'    => 'Chez Mama Fifi',
            'phone'   => '0812000111',
            'address' => ['address_line' => '12 avenue du Commerce', 'commune' => 'Gombe', 'city' => 'Kinshasa'],
        ], $owner['tokens']['access_token']);

        $response->assertStatus(201);
        $restaurantId = $this->body($response)['data']['id'];

        if ($status !== 'pending') {
            $this->db->table('wf_restaurants')->where('id', $restaurantId)->update(['status' => $status]);
        }

        return [
            'token'         => $owner['tokens']['access_token'],
            'user_id'       => $owner['user']['id'],
            'restaurant_id' => $restaurantId,
            'phone'         => $owner['phone'],
        ];
    }

    /**
     * Simule un fichier téléversé (champ multipart) à partir d'un contenu binaire.
     */
    protected function fakeUpload(string $field, string $contents, string $clientName = 'image.png', string $clientType = 'image/png'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wf_upload_');
        file_put_contents($path, $contents);

        // CI 4.7 lit les fichiers via le service « superglobals » (copie de $_FILES).
        service('superglobals')->setFilesArray([$field => [
            'name'     => $clientName,
            'type'     => $clientType,
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($contents),
        ]]);
    }

    /** Image PNG valide générée avec GD. */
    protected static function pngBytes(int $width = 40, int $height = 30): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 20));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * Requête multipart authentifiée (upload).
     *
     * @param array<string, string> $fields
     */
    protected function upload(string $path, string $token, array $fields = [], array $headers = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token] + $headers)->withBodyFormat('')->withBody('')->call('POST', $path, $fields);
    }

    protected function addMember(int $restaurantId, int $userId, string $role, string $status = 'active'): void
    {
        $this->db->table('wf_restaurant_users')->insert([
            'restaurant_id'   => $restaurantId,
            'user_id'         => $userId,
            'restaurant_role' => $role,
            'status'          => $status,
        ]);
    }
}
