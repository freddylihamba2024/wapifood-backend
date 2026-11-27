<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use App\Common\Exceptions\ApiException;
use App\Modules\Orders\Services\OrderStateMachine;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class OrderStateMachineTest extends CIUnitTestCase
{
    private const C = OrderStateMachine::ACTOR_CUSTOMER;
    private const R = OrderStateMachine::ACTOR_RESTAURANT;

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function transitions(): iterable
    {
        // Restaurant : parcours nominal livraison
        yield 'accepter' => ['pending', 'accepted', 'delivery', self::R, true];
        yield 'refuser' => ['pending', 'rejected', 'delivery', self::R, true];
        yield 'préparer' => ['accepted', 'preparing', 'delivery', self::R, true];
        yield 'annuler acceptée' => ['accepted', 'cancelled', 'delivery', self::R, true];
        yield 'prête' => ['preparing', 'ready', 'delivery', self::R, true];
        yield 'en livraison' => ['ready', 'out_for_delivery', 'delivery', self::R, true];
        yield 'livrée' => ['out_for_delivery', 'completed', 'delivery', self::R, true];

        // Le type de commande décide de la sortie de « ready »
        yield 'retrait : remise au client' => ['ready', 'completed', 'pickup', self::R, true];
        yield 'retrait : pas de livraison' => ['ready', 'out_for_delivery', 'pickup', self::R, false];
        yield 'livraison : pas de remise directe' => ['ready', 'completed', 'delivery', self::R, false];

        // Interdits au restaurant
        yield 'sauter la préparation' => ['pending', 'ready', 'delivery', self::R, false];
        yield 'annuler en préparation' => ['preparing', 'cancelled', 'delivery', self::R, false];
        yield 'annuler en attente (refuser)' => ['pending', 'cancelled', 'delivery', self::R, false];
        yield 'retour arrière' => ['preparing', 'accepted', 'delivery', self::R, false];
        yield 'rouvrir terminée' => ['completed', 'pending', 'delivery', self::R, false];
        yield 'rouvrir annulée' => ['cancelled', 'accepted', 'delivery', self::R, false];

        // Client : seulement annuler une commande en attente
        yield 'client annule en attente' => ['pending', 'cancelled', 'pickup', self::C, true];
        yield 'client annule acceptée' => ['accepted', 'cancelled', 'pickup', self::C, false];
        yield 'client accepte' => ['pending', 'accepted', 'pickup', self::C, false];
        yield 'client termine' => ['ready', 'completed', 'pickup', self::C, false];
    }

    #[DataProvider('transitions')]
    public function testTransition(string $from, string $to, string $type, string $actor, bool $allowed): void
    {
        $this->assertSame($allowed, (new OrderStateMachine())->canTransition($from, $to, $type, $actor));
    }

    public function testFinalStatusesHaveNoNextStatus(): void
    {
        $machine = new OrderStateMachine();

        foreach (OrderStateMachine::FINAL_STATUSES as $status) {
            $this->assertTrue($machine->isFinal($status));

            foreach ([self::C, self::R] as $actor) {
                $this->assertSame([], $machine->nextStatuses($status, 'delivery', $actor));
            }
        }
    }

    public function testAssertTransitionReportsAllowedStatuses(): void
    {
        try {
            (new OrderStateMachine())->assertTransition('accepted', 'completed', 'delivery', self::R);
            $this->fail('Transition interdite acceptée.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('INVALID_STATUS_TRANSITION', $exception->getErrorCode());
            $this->assertSame(['preparing', 'cancelled'], $exception->getErrors()['allowed']);
        }
    }

    public function testReasonRequiredOnlyForRestaurantRefusalOrCancellation(): void
    {
        $machine = new OrderStateMachine();

        $this->assertTrue($machine->requiresReason('rejected', self::R));
        $this->assertTrue($machine->requiresReason('cancelled', self::R));
        $this->assertFalse($machine->requiresReason('cancelled', self::C));
        $this->assertFalse($machine->requiresReason('accepted', self::R));
    }
}
