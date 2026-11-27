<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Common\Auth\RestaurantContext;
use App\Modules\Restaurants\Services\RestaurantAccessService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Permissions;

/**
 * @internal
 */
final class RestaurantPermissionsTest extends CIUnitTestCase
{
    private function context(string $role): RestaurantContext
    {
        $service = new RestaurantAccessService(new Permissions(), db_connect());

        return new RestaurantContext(1, $role, 'active', $service->permissionsFor($role));
    }

    public function testOwnerHasEveryPermission(): void
    {
        $owner = $this->context('owner');

        foreach ([...(new Permissions())->ownerOnly, 'orders.manage', 'catalog.manage'] as $permission) {
            $this->assertTrue($owner->can($permission), $permission);
        }
    }

    public function testOwnerOnlyPermissionsAreNeverDelegated(): void
    {
        foreach (['manager', 'staff', 'cashier'] as $role) {
            foreach ((new Permissions())->ownerOnly as $permission) {
                $this->assertFalse($this->context($role)->can($permission), "{$role} ne doit pas avoir {$permission}");
            }
        }
    }

    public function testRoleScopes(): void
    {
        $this->assertTrue($this->context('manager')->can('catalog.manage'));
        $this->assertTrue($this->context('staff')->can('orders.manage'));
        $this->assertFalse($this->context('staff')->can('catalog.manage'));
        $this->assertTrue($this->context('cashier')->can('payments.record'));
        $this->assertFalse($this->context('cashier')->can('orders.manage'));
        $this->assertFalse($this->context('inconnu')->can('orders.view'));
    }
}
