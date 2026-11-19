<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Plugin;

/** Collects log lines so tests can read what AclCheck reported. */
class StewardTestLogCollector extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

/** Adds rules without touching the real list, to exercise the project map. */
class StewardTestAclCheck extends AclCheck
{
    public array $extra = [];

    protected function expected(): array
    {
        return array_merge_recursive(parent::expected(), $this->extra);
    }
}

class AclCheckTest extends Base
{
    private StewardTestLogCollector $log;

    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
        $this->log = new StewardTestLogCollector();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;
    }

    private function acl(): AclCheck
    {
        return $this->container['stewardAcl'];
    }

    private function errors(): array
    {
        return array_column(array_filter($this->log->lines, fn ($l) => $l[0] === 'error'), 1);
    }

    public function testCleanInstallVerifies(): void
    {
        $this->assertFalse($this->acl()->isBroken());
        $this->assertTrue($this->acl()->verify());
        $this->assertFalse($this->acl()->isBroken());
        $this->assertFalse($this->container['stewardAclBroken']);
        $this->assertSame([], $this->errors());
    }

    public function testEveryStewardRuleComesFromExpected(): void
    {
        $expanded = [];
        foreach (AclCheck::EXPECTED as $map => $rules) {
            foreach ($rules as [$controller, $actions, $role]) {
                foreach ((array) $actions as $action) {
                    $expanded[$map][strtolower($controller)][strtolower($action)] = $role;
                }
            }
        }
        $this->assertNotEmpty($expanded['applicationAccessMap'] ?? []);

        foreach (['applicationAccessMap', 'projectAccessMap'] as $map) {
            $live = (fn () => $this->map)->call($this->container[$map]);
            $ours = array_filter($live, fn ($c) => str_starts_with($c, 'steward'), ARRAY_FILTER_USE_KEY);
            $this->assertSame($expanded[$map] ?? [], $ours, $map.' has Steward rules outside AclCheck::EXPECTED');
        }
    }

    public function testRuleReplacedByLaterPluginBreaksStewardAndIsLogged(): void
    {
        // A plugin loaded after Steward re-registers our settings page as public.
        $this->container['applicationAccessMap']->add('StewardSettingsController', 'show', Role::APP_PUBLIC);

        $this->assertFalse($this->acl()->verify());
        $this->assertTrue($this->acl()->isBroken());
        $this->assertTrue($this->container['stewardAclBroken']);

        $errors = $this->errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('applicationAccessMap', $errors[0]);
        $this->assertStringContainsString('StewardSettingsController::show', $errors[0]);
        $this->assertStringContainsString(Role::APP_PUBLIC, $errors[0]);
        $this->assertStringContainsString(Role::APP_ADMIN, $errors[0]);
    }

    public function testReplacementIgnoresCase(): void
    {
        $this->container['applicationAccessMap']->add('stewardsettingscontroller', 'SAVE', Role::APP_USER);
        $this->assertFalse($this->acl()->verify());
        $this->assertStringContainsString('StewardSettingsController::save', $this->errors()[0]);
    }

    public function testStricterReplacementAlsoCounts(): void
    {
        // Any change is a conflict: Steward cannot tell a hardening from a takeover.
        $this->container['applicationAccessMap']->add('StewardSettingsController', 'show', Role::APP_MANAGER);
        $this->assertFalse($this->acl()->verify());
    }

    public function testWildcardFromAnotherPluginDoesNotOverrideExplicitRules(): void
    {
        $this->container['applicationAccessMap']->add('StewardSettingsController', '*', Role::APP_PUBLIC);
        $this->assertTrue($this->acl()->verify());
    }

    public function testProjectMapIsCheckedToo(): void
    {
        $check = new StewardTestAclCheck($this->container);
        $check->extra = ['projectAccessMap' => [['StewardFakeController', ['header'], Role::PROJECT_MANAGER]]];
        $check->register();
        $this->assertTrue($check->verify());

        $this->container['projectAccessMap']->add('StewardFakeController', 'header', Role::PROJECT_VIEWER);
        $this->assertFalse($check->verify());
        $this->assertStringContainsString('projectAccessMap', $this->errors()[0]);
        $this->assertStringContainsString('StewardFakeController::header', $this->errors()[0]);
    }

    public function testBrokenStaysBrokenForTheRequest(): void
    {
        $this->container['applicationAccessMap']->add('StewardSettingsController', 'show', Role::APP_PUBLIC);
        $this->acl()->verify();
        $this->container['applicationAccessMap']->add('StewardSettingsController', 'show', Role::APP_ADMIN);
        $this->assertTrue($this->acl()->verify(), 'verify reports the live state');
        $this->assertTrue($this->acl()->isBroken(), 'but a conflict seen once is not forgotten');
    }

    public function testCheckRunsBeforeFeatureGateAndAgainAfterOtherStartupListeners(): void
    {
        $dispatcher = $this->container['dispatcher'];
        $later = fn () => null; // stands in for the onStartup of a plugin loaded after Steward
        $dispatcher->addListener('app.bootstrap', $later);

        $order = [];
        foreach ($dispatcher->getListeners('app.bootstrap') as $listener) {
            if ($listener === $later) {
                $order[] = 'later';
            } elseif (is_array($listener) && $listener[0] instanceof AclCheck) {
                $order[] = 'acl';
            } elseif (is_array($listener) && $listener[0] instanceof FeatureGate) {
                $order[] = 'gate';
            }
        }
        $this->assertSame(['acl', 'gate', 'later', 'acl', 'gate'], $order);
        $this->assertLessThan(0, Plugin::LATE_BOOTSTRAP);
    }

    public function testTamperingInAnotherPluginsStartupIsCaught(): void
    {
        // Kanboard registers each plugin's onStartup on app.bootstrap; a plugin loaded after Steward runs after our first check.
        $this->container['dispatcher']->addListener('app.bootstrap', function () {
            $this->container['applicationAccessMap']->add('StewardSettingsController', 'show', Role::APP_PUBLIC);
        });
        // No router context, so FeatureGate stays a no-op and the request is not halted.
        unset($this->container['router']);
        $this->container['router'] = new \Kanboard\Core\Http\Router($this->container);
        $this->container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
        $this->assertTrue($this->acl()->isBroken());
    }
}
