<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Modules\Auth\Delivery\ChannelAuthCodeSender;
use App\Modules\Notifications\Channels\ChannelException;
use App\Modules\Notifications\Channels\NotificationChannelRegistry;
use App\Modules\Notifications\Services\NotificationTemplates;
use App\Common\Exceptions\ApiException;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Email;
use Config\Services;
use Config\Notifications;
use InvalidArgumentException;
use Tests\Support\FakeNotificationChannel;

/**
 * Textes des notifications, délais de reprise et envoi des codes OTP par les canaux.
 *
 * @internal
 */
final class NotificationTemplatesTest extends CIUnitTestCase
{
    public function testTemplatesAreRenderedAndMissingVariablesRemovedCleanly(): void
    {
        $this->assertSame(
            ['title' => 'Commande refusée', 'message' => 'Chez Fifi a refusé votre commande WF1-ABC : Rupture de stock'],
            NotificationTemplates::render('order.rejected', ['restaurant' => 'Chez Fifi', 'order' => 'WF1-ABC', 'reason' => 'Rupture de stock']),
        );
        $this->assertSame(
            'Chez Fifi a refusé votre commande WF1-ABC.',
            NotificationTemplates::render('order.rejected', ['restaurant' => 'Chez Fifi', 'order' => 'WF1-ABC', 'reason' => null])['message'],
        );

        $this->expectException(InvalidArgumentException::class);
        NotificationTemplates::render('order.unknown', []);
    }

    public function testEveryConfiguredEventHasATemplateAndShortMessages(): void
    {
        $vars = ['order' => 'WF261126-K7Q2MX', 'restaurant' => 'Restaurant Chez Mama Fifi', 'reason' => str_repeat('m', 60), 'amount' => '12.50', 'currency' => 'USD', 'rating' => 5];

        foreach ((new Notifications())->events as $event => $audiences) {
            $this->assertTrue(NotificationTemplates::exists($event), $event);

            if (in_array('sms', array_merge(...array_values($audiences)), true)) {
                $this->assertLessThanOrEqual(160, mb_strlen(NotificationTemplates::render($event, $vars)['message']), $event);
            }
        }
    }

    public function testRetryDelaysGrowThenStayAtTheLastValue(): void
    {
        $config = new Notifications();

        $this->assertSame([60, 300, 900, 3600, 3600], array_map($config->retryDelaySeconds(...), [1, 2, 3, 4, 9]));
    }

    public function testOtpIsSentThroughAnActiveChannel(): void
    {
        $sms    = new FakeNotificationChannel('sms');
        $sender = new ChannelAuthCodeSender(new NotificationChannelRegistry([$sms]));

        $this->assertTrue($sender->supports('sms'));
        $this->assertFalse($sender->supports('email'));
        $this->assertFalse($sender->supports('in_app'));

        $sender->send('sms', '+243991234567', 'phone_verification', '482913');
        $this->assertSame('+243991234567', $sms->sent[0]['recipient']);
        $this->assertStringContainsString('482913', $sms->sent[0]['message']);
        $this->assertStringContainsString('vérifier votre numéro', $sms->sent[0]['message']);

        $sms->failures[] = new ChannelException('Fournisseur injoignable');

        try {
            $sender->send('sms', '+243991234567', 'password_reset', '111111');
            $this->fail('503 attendu.');
        } catch (ApiException $exception) {
            $this->assertSame([503, 'OTP_DELIVERY_UNAVAILABLE'], [$exception->getStatusCode(), $exception->getErrorCode()]);
        }
    }

    public function testChannelsAreEnabledOnlyWhenConfigured(): void
    {
        $notifications                   = new Notifications();
        $notifications->smsProvider      = 'log';
        $notifications->whatsappProvider = 'unprovider';
        $email                           = new Email();
        $email->fromEmail                = '';
        Factories::injectMock('config', Notifications::class, $notifications);
        Factories::injectMock('config', Email::class, $email);

        try {
            $registry = Services::notificationChannels(false);
            $this->assertSame(['in_app', 'sms'], $registry->enabled());
            $this->assertSame('log', $registry->get('sms')?->provider());
            $this->assertNull($registry->get('whatsapp'));

            $email->fromEmail = 'noreply@wapifood.test';
            $email->protocol  = 'smtp';
            $email->SMTPHost  = 'smtp.wapifood.test';
            $this->assertTrue(Services::notificationChannels(false)->isEnabled('email'));
        } finally {
            Factories::reset('config');
        }
    }
}
