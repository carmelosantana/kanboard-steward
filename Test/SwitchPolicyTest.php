<?php
require_once __DIR__.'/support/StewardTestCase.php';

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\SwitchPolicy;
use Kanboard\Plugin\Steward\Plugin;

class SwitchPolicyTest extends StewardTestCase
{
    private SwitchPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();
        $this->policy = new SwitchPolicy($this->container);
    }

    private function admin(array $extra = []): array
    {
        return $extra + ['id' => 1, 'role' => Role::APP_ADMIN, 'is_active' => 1, 'twofactor_activated' => 0];
    }

    private function target(array $extra = []): array
    {
        return $extra + ['id' => 2, 'role' => Role::APP_USER, 'is_active' => 1];
    }

    public function testEligibleSwitchIsAllowed(): void
    {
        $this->assertNull($this->policy->canStart($this->admin(), $this->target(), false, false));
    }

    public function testUniformMessage(): void
    {
        $this->assertSame("You can't switch to this user", SwitchPolicy::UNIFORM_MESSAGE);
    }

    public function testDisabledWhenFeatureOff(): void
    {
        $this->container['configModel']->save(['steward_switching' => '0']);
        $this->container['memoryCache']->flush();
        $this->assertSame('disabled', $this->policy->canStart($this->admin(), $this->target(), false, false));
    }

    public function testDisabledBeatsEverything(): void
    {
        $this->container['configModel']->save(['steward_switching' => '0']);
        $this->container['memoryCache']->flush();
        $this->container[AclCheck::FLAG] = true;
        $this->assertSame('disabled', $this->policy->canStart(['id' => 3, 'role' => Role::APP_USER], null, true, false));
    }

    public function testAclConflict(): void
    {
        $this->container[AclCheck::FLAG] = true;
        $this->assertSame('acl_conflict', $this->policy->canStart($this->admin(), $this->target(), false, false));
    }

    public function testAclConflictBeatsNotAdmin(): void
    {
        $this->container[AclCheck::FLAG] = true;
        $this->assertSame('acl_conflict', $this->policy->canStart(['id' => 3, 'role' => Role::APP_USER], null, false, false));
    }

    public function testNotAdminForAppUser(): void
    {
        $this->assertSame('not_admin', $this->policy->canStart($this->admin(['role' => Role::APP_USER]), $this->target(), false, false));
    }

    public function testNotAdminForAppManager(): void
    {
        $this->assertSame('not_admin', $this->policy->canStart($this->admin(['role' => Role::APP_MANAGER]), $this->target(), false, false));
    }

    public function testNotAdminBeatsNotFound(): void
    {
        $this->assertSame('not_admin', $this->policy->canStart($this->admin(['role' => Role::APP_USER]), null, false, false));
    }

    public function testNested(): void
    {
        $this->assertSame('nested', $this->policy->canStart($this->admin(), $this->target(), true, true));
    }

    public function testTwoFactorPendingWhenActorUses2faAndNotValidated(): void
    {
        $this->assertSame('2fa_pending', $this->policy->canStart($this->admin(['twofactor_activated' => 1]), $this->target(), false, false));
    }

    public function testTwoFactorValidatedIsAllowed(): void
    {
        $this->assertNull($this->policy->canStart($this->admin(['twofactor_activated' => 1]), $this->target(), false, true));
    }

    public function testNoTwoFactorPendingWithout2fa(): void
    {
        $this->assertNull($this->policy->canStart($this->admin(['twofactor_activated' => 0]), $this->target(), false, false));
        $this->assertNull($this->policy->canStart($this->admin(['twofactor_activated' => null]), $this->target(), false, false));
    }

    public function testTwoFactorPendingBeatsNotFound(): void
    {
        $this->assertSame('2fa_pending', $this->policy->canStart($this->admin(['twofactor_activated' => 1]), null, false, false));
    }

    public function testNotFound(): void
    {
        $this->assertSame('not_found', $this->policy->canStart($this->admin(), null, false, false));
    }

    public function testInactive(): void
    {
        $this->assertSame('inactive', $this->policy->canStart($this->admin(), $this->target(['is_active' => 0]), false, false));
    }

    public function testInactiveBeatsTargetAdmin(): void
    {
        $this->assertSame('inactive', $this->policy->canStart($this->admin(), $this->target(['is_active' => 0, 'role' => Role::APP_ADMIN]), false, false));
    }

    public function testTargetAdmin(): void
    {
        $this->assertSame('target_admin', $this->policy->canStart($this->admin(), $this->target(['role' => Role::APP_ADMIN]), false, false));
    }

    public function testTargetManagerIsAllowed(): void
    {
        $this->assertNull($this->policy->canStart($this->admin(), $this->target(['role' => Role::APP_MANAGER]), false, false));
    }

    /** The realistic self-switch: the admin's own row is both actor and target, so `self` must beat `target_admin`. */
    public function testSelf(): void
    {
        $this->assertSame('self', $this->policy->canStart($this->admin(), $this->admin(), false, false));
    }

    public function testSelfComparesIdsLoosely(): void
    {
        $this->assertSame('self', $this->policy->canStart($this->admin(['id' => '1']), $this->admin(['id' => 1]), false, false));
    }

    public function testInactiveBeatsSelf(): void
    {
        $this->assertSame('inactive', $this->policy->canStart($this->admin(), $this->admin(['is_active' => 0]), false, false));
    }

    public function testTargetWithoutIdIsNotSelf(): void
    {
        $this->assertSame('target_admin', $this->policy->canStart($this->admin(), ['role' => Role::APP_ADMIN, 'is_active' => 1], false, false));
    }
}
