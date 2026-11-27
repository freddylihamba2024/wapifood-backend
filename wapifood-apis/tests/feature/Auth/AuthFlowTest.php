<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Support\ApiTestCase;

/**
 * Parcours complets d'authentification via l'API.
 *
 * @internal
 */
final class AuthFlowTest extends ApiTestCase
{
    // ------------------------------------------------------------------
    // Inscription
    // ------------------------------------------------------------------

    public function testRegisterCreatesPendingCustomerAndSendsPhoneCode(): void
    {
        $response = $this->json('POST', 'api/v1/auth/register', [
            'first_name'   => 'Grace',
            'phone'        => '081 234 5678',
            'email'        => 'Grace@Exemple.CD',
            'password'     => self::PASSWORD,
            'account_type' => 'customer',
        ]);

        $response->assertStatus(201);
        $body = $this->body($response);

        $this->assertTrue($body['success']);
        $this->assertSame('+243812345678', $body['data']['user']['phone']);
        $this->assertSame('grace@exemple.cd', $body['data']['user']['email']);
        $this->assertSame('pending', $body['data']['user']['status']);
        $this->assertSame(['customer'], $body['data']['user']['roles']);
        $this->assertSame('Bearer', $body['data']['tokens']['token_type']);
        $this->assertTrue($body['data']['phone_verification']['sent']);

        $this->assertCount(1, $this->codes->sent);
        $this->assertSame('+243812345678', $this->codes->sent[0]['destination']);

        $raw = (string) $response->getJSON();
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('token_version', $raw);

        $stored = $this->db->table('wf_users')->where('phone', '+243812345678')->get()->getRowArray();
        $this->assertTrue(password_verify(self::PASSWORD, $stored['password_hash']));
    }

    public function testRegisterRestaurantOwner(): void
    {
        $account = $this->registerUser('restaurant_owner', verifyPhone: false);

        $this->assertSame(['restaurant_owner'], $account['user']['roles']);
    }

    public function testRegisterRefusesPrivilegedAccountTypes(): void
    {
        foreach (['super_admin', 'restaurant_staff', 'courier'] as $type) {
            $response = $this->json('POST', 'api/v1/auth/register', [
                'first_name' => 'X', 'phone' => $this->uniquePhone(), 'password' => self::PASSWORD, 'account_type' => $type,
            ]);

            $response->assertStatus(422);
            $this->assertArrayHasKey('account_type', $this->body($response)['errors']);
        }

        $this->assertSame(0, $this->db->table('wf_users')->countAllResults());
    }

    public function testRegisterValidationAndConflicts(): void
    {
        $weak = $this->json('POST', 'api/v1/auth/register', [
            'first_name' => 'X', 'phone' => '0812345678', 'password' => 'faible', 'account_type' => 'customer',
        ]);
        $weak->assertStatus(422);
        $this->assertArrayHasKey('password', $this->body($weak)['errors']);

        $badPhone = $this->json('POST', 'api/v1/auth/register', [
            'first_name' => 'X', 'phone' => '12', 'password' => self::PASSWORD, 'account_type' => 'customer',
        ]);
        $badPhone->assertStatus(422);

        $account   = $this->registerUser(verifyPhone: false);
        $duplicate = $this->json('POST', 'api/v1/auth/register', [
            'first_name' => 'Autre', 'phone' => $account['phone'], 'password' => self::PASSWORD, 'account_type' => 'customer',
        ]);
        $duplicate->assertStatus(409);
        $this->assertSame('PHONE_TAKEN', $this->body($duplicate)['meta']['code']);
    }

    // ------------------------------------------------------------------
    // Connexion
    // ------------------------------------------------------------------

    public function testLoginByPhoneOrEmail(): void
    {
        $account = $this->registerUser(email: 'client@wapifood.cd');

        $byPhone = $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => self::PASSWORD]);
        $byPhone->assertStatus(200);
        $this->assertNotEmpty($this->body($byPhone)['data']['tokens']['access_token']);

        $byEmail = $this->json('POST', 'api/v1/auth/login', ['identifier' => 'CLIENT@wapifood.cd', 'password' => self::PASSWORD]);
        $byEmail->assertStatus(200);

        $this->assertNotNull($this->db->table('wf_users')->where('id', $account['user']['id'])->get()->getRow()->last_login_at);
    }

    public function testLoginFailuresDoNotRevealWhetherAccountExists(): void
    {
        $account = $this->registerUser();

        $wrongPassword = $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => 'Mauvais-Mot-2026']);
        $unknownUser   = $this->json('POST', 'api/v1/auth/login', ['identifier' => '0899000000', 'password' => 'Mauvais-Mot-2026']);

        $wrongPassword->assertStatus(401);
        $unknownUser->assertStatus(401);
        $this->assertSame($this->body($wrongPassword), $this->body($unknownUser));
    }

    public function testAccountIsLockedAfterRepeatedFailures(): void
    {
        $account = $this->registerUser();

        for ($i = 0; $i < 5; $i++) {
            $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => 'Mauvais-Mot-2026']);
        }

        $locked = $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => self::PASSWORD]);

        $locked->assertStatus(429);
        $this->assertSame('ACCOUNT_LOCKED', $this->body($locked)['meta']['code']);
    }

    public function testSuspendedAccountIsRejectedEverywhere(): void
    {
        $account = $this->registerUser();
        $this->db->table('wf_users')->where('id', $account['user']['id'])->update(['status' => 'suspended']);

        $me = $this->json('GET', 'api/v1/auth/me', [], $account['tokens']['access_token']);
        $me->assertStatus(403);
        $this->assertSame('ACCOUNT_SUSPENDED', $this->body($me)['meta']['code']);

        $login = $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => self::PASSWORD]);
        $login->assertStatus(403);

        $refresh = $this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $account['tokens']['refresh_token']]);
        $refresh->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Jetons et sessions
    // ------------------------------------------------------------------

    public function testRefreshRotatesTokens(): void
    {
        $account = $this->registerUser();

        $response = $this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $account['tokens']['refresh_token']]);
        $response->assertStatus(200);
        $tokens = $this->body($response)['data']['tokens'];

        $this->assertNotSame($account['tokens']['refresh_token'], $tokens['refresh_token']);
        $this->json('GET', 'api/v1/auth/me', [], $tokens['access_token'])->assertStatus(200);

        // Seul le hash est stocké.
        $this->dontSeeInDatabase('wf_refresh_tokens', ['token_hash' => $tokens['refresh_token']]);
        $this->seeInDatabase('wf_refresh_tokens', ['token_hash' => hash('sha256', $tokens['refresh_token'])]);
    }

    public function testReusingARotatedRefreshTokenRevokesTheSession(): void
    {
        $account = $this->registerUser();
        $first   = $account['tokens']['refresh_token'];

        $rotated = $this->body($this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $first]))['data']['tokens'];

        $reuse = $this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $first]);
        $reuse->assertStatus(401);
        $this->assertSame('REFRESH_TOKEN_REUSED', $this->body($reuse)['meta']['code']);

        // Le jeton légitime suivant et les jetons d'accès sont révoqués aussi.
        $this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $rotated['refresh_token']])->assertStatus(401);
        $this->json('GET', 'api/v1/auth/me', [], $rotated['access_token'])->assertStatus(401);

        $this->seeInDatabase('wf_audit_logs', ['action' => 'auth.refresh_token_reuse_detected']);
    }

    public function testInvalidRefreshTokens(): void
    {
        foreach (['', 'court', str_repeat('a', 64), "'; DROP TABLE wf_users; --"] as $token) {
            $this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $token])->assertStatus($token === '' ? 422 : 401);
        }
    }

    public function testLogoutRevokesCurrentSessionOnly(): void
    {
        $account = $this->registerUser();
        $other   = $this->body($this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => self::PASSWORD]))['data']['tokens'];

        $this->json('POST', 'api/v1/auth/logout', [], $account['tokens']['access_token'])->assertStatus(200);

        $this->json('GET', 'api/v1/auth/me', [], $account['tokens']['access_token'])->assertStatus(401);
        $this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $account['tokens']['refresh_token']])->assertStatus(401);
        $this->json('GET', 'api/v1/auth/me', [], $other['access_token'])->assertStatus(200);
    }

    public function testLogoutAllDevices(): void
    {
        $account = $this->registerUser();
        $other   = $this->body($this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => self::PASSWORD]))['data']['tokens'];

        $this->json('POST', 'api/v1/auth/logout', ['all_devices' => true], $account['tokens']['access_token'])->assertStatus(200);

        $this->json('GET', 'api/v1/auth/me', [], $other['access_token'])->assertStatus(401);
        $this->json('POST', 'api/v1/auth/refresh', ['refresh_token' => $other['refresh_token']])->assertStatus(401);
    }

    public function testSessionsCanBeListedAndRevokedOnlyByTheirOwner(): void
    {
        $alice = $this->registerUser();
        $bob   = $this->registerUser();
        $this->json('POST', 'api/v1/auth/login', ['identifier' => $alice['phone'], 'password' => self::PASSWORD]);

        $sessions = $this->body($this->json('GET', 'api/v1/auth/sessions', [], $alice['tokens']['access_token']))['data'];
        $this->assertCount(2, $sessions);
        $this->assertCount(1, array_filter($sessions, static fn (array $s): bool => $s['current']));

        $other = current(array_filter($sessions, static fn (array $s): bool => ! $s['current']));

        // Bob ne peut pas fermer une session d'Alice.
        $this->json('DELETE', 'api/v1/auth/sessions/' . $other['id'], [], $bob['tokens']['access_token'])->assertStatus(404);

        $this->json('DELETE', 'api/v1/auth/sessions/' . $other['id'], [], $alice['tokens']['access_token'])->assertStatus(204);
        $this->assertCount(1, $this->body($this->json('GET', 'api/v1/auth/sessions', [], $alice['tokens']['access_token']))['data']);
    }

    public function testTamperedAccessTokenIsRejected(): void
    {
        $account = $this->registerUser();
        [$header, $payload, $signature] = explode('.', $account['tokens']['access_token']);

        $claims        = json_decode(base64_decode(strtr($payload, '-_', '+/'), true), true);
        $claims['sub'] = '999999';
        $forged        = $header . '.' . rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=') . '.' . $signature;

        $response = $this->json('GET', 'api/v1/auth/me', [], $forged);
        $response->assertStatus(401);
        $this->assertSame('TOKEN_INVALID', $this->body($response)['meta']['code']);

        $this->json('GET', 'api/v1/auth/me')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Vérification du téléphone (OTP)
    // ------------------------------------------------------------------

    public function testPhoneVerificationActivatesAccount(): void
    {
        $account = $this->registerUser(verifyPhone: false);
        $token   = $account['tokens']['access_token'];

        $this->json('POST', 'api/v1/auth/verify-phone', ['code' => '000000'], $token)->assertStatus(422);

        $response = $this->json('POST', 'api/v1/auth/verify-phone', ['code' => $this->codes->lastCode()], $token);
        $response->assertStatus(200);
        $this->assertSame('active', $this->body($response)['data']['status']);
        $this->assertTrue($this->body($response)['data']['phone_verified']);

        // Un code ne sert qu'une fois.
        $this->json('POST', 'api/v1/auth/verify-phone', ['code' => $this->codes->lastCode()], $token)->assertStatus(409);
    }

    public function testOtpIsInvalidatedAfterTooManyAttempts(): void
    {
        $account = $this->registerUser(verifyPhone: false);
        $token   = $account['tokens']['access_token'];
        $good    = $this->codes->lastCode();

        for ($i = 0; $i < 5; $i++) {
            $this->json('POST', 'api/v1/auth/verify-phone', ['code' => $good === '111111' ? '222222' : '111111'], $token);
        }

        $this->json('POST', 'api/v1/auth/verify-phone', ['code' => $good], $token)->assertStatus(422);
    }

    public function testOtpResendIsThrottled(): void
    {
        $account = $this->registerUser(verifyPhone: false);

        $response = $this->json('POST', 'api/v1/auth/verify-phone/resend', [], $account['tokens']['access_token']);

        $response->assertStatus(429);
        $this->assertSame('OTP_COOLDOWN', $this->body($response)['meta']['code']);
    }

    // ------------------------------------------------------------------
    // Mots de passe
    // ------------------------------------------------------------------

    public function testForgotPasswordIsSilentForUnknownAccounts(): void
    {
        $known   = $this->registerUser();
        $sent    = count($this->codes->sent);
        $unknown = $this->json('POST', 'api/v1/auth/forgot-password', ['identifier' => '0899000000']);
        $valid   = $this->json('POST', 'api/v1/auth/forgot-password', ['identifier' => $known['phone']]);

        $unknown->assertStatus(200);
        $valid->assertStatus(200);
        $this->assertSame($this->body($unknown)['message'], $this->body($valid)['message']);
        $this->assertCount($sent + 1, $this->codes->sent);
    }

    public function testResetPasswordRevokesAllSessions(): void
    {
        $account = $this->registerUser();

        $this->json('POST', 'api/v1/auth/forgot-password', ['identifier' => $account['phone']])->assertStatus(200);
        $code = $this->codes->lastCode('password_reset');

        $this->json('POST', 'api/v1/auth/reset-password', [
            'identifier' => $account['phone'], 'code' => '000000', 'password' => 'Nouveau-Secret-2026',
        ])->assertStatus(422);

        $this->json('POST', 'api/v1/auth/reset-password', [
            'identifier' => $account['phone'], 'code' => $code, 'password' => 'Nouveau-Secret-2026',
        ])->assertStatus(200);

        $this->json('GET', 'api/v1/auth/me', [], $account['tokens']['access_token'])->assertStatus(401);
        $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => self::PASSWORD])->assertStatus(401);
        $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => 'Nouveau-Secret-2026'])->assertStatus(200);
    }

    public function testChangePasswordKeepsOnlyTheNewSession(): void
    {
        $account = $this->registerUser();

        $this->json('POST', 'api/v1/auth/change-password', [
            'current_password' => 'Pas-Le-Bon-2026', 'new_password' => 'Nouveau-Secret-2026',
        ], $account['tokens']['access_token'])->assertStatus(422);

        $response = $this->json('POST', 'api/v1/auth/change-password', [
            'current_password' => self::PASSWORD, 'new_password' => 'Nouveau-Secret-2026',
        ], $account['tokens']['access_token']);

        $response->assertStatus(200);
        $newTokens = $this->body($response)['data']['tokens'];

        $this->json('GET', 'api/v1/auth/me', [], $account['tokens']['access_token'])->assertStatus(401);
        $this->json('GET', 'api/v1/auth/me', [], $newTokens['access_token'])->assertStatus(200);
        $this->seeInDatabase('wf_audit_logs', ['action' => 'auth.password_changed', 'user_id' => $account['user']['id']]);
    }

    public function testAuditLogNeverContainsSecrets(): void
    {
        $account = $this->registerUser();
        $this->json('POST', 'api/v1/auth/login', ['identifier' => $account['phone'], 'password' => self::PASSWORD]);

        $dump = (string) json_encode($this->db->table('wf_audit_logs')->get()->getResultArray());

        $this->assertStringNotContainsString(self::PASSWORD, $dump);
        $this->assertStringNotContainsString($account['tokens']['refresh_token'], $dump);
        $this->assertStringNotContainsString($account['tokens']['access_token'], $dump);
    }
}
