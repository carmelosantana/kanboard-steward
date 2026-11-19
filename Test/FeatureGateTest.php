<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Router;
use Kanboard\Core\Security\Role;
use Kanboard\Middleware\ApplicationAuthorizationMiddleware;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SettingsSchema;
use Kanboard\Plugin\Steward\Plugin;

final class StewardGateHalted extends \RuntimeException {}

/** The real gate exits after sending the 404; this one throws so the test can see the stop. */
class StewardTestFeatureGate extends FeatureGate
{
    public array $extraMap = [];

    protected function map(): array
    {
        return $this->extraMap + parent::map();
    }

    protected function halt(): never
    {
        throw new StewardGateHalted();
    }
}

class FeatureGateTest extends Base
{
    private const FAKE = 'StewardFakeController';

    /** @var array{0:string,1:int}|null */
    private ?array $sent = null;

    protected function setUp(): void
    {
        parent::setUp();
        (new Plugin($this->container))->initialize();
        $this->container['applicationAccessMap']->add(self::FAKE, ['index'], Role::APP_ADMIN);

        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['html'])->getMock();
        $response->method('html')->willReturnCallback(function ($body, $status = 200) {
            $this->sent = [(string) $body, (int) $status];
        });
        unset($this->container['response']);
        $this->container['response'] = $response;
    }

    private function route(array $get): void
    {
        unset($this->container['request'], $this->container['router']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], $get, [], [], []);
        $this->container['router'] = new Router($this->container);
        $this->container['router']->dispatch();
    }

    private function loginAs(?string $role): void
    {
        $this->container['memoryCache']->flush();
        if ($role === null) {
            session_remove('user');
            return;
        }
        $users = $this->container['userModel'];
        $name = 'u_'.strtolower(str_replace('-', '_', $role));
        $id = $users->getIdByUsername($name) ?: $users->create(['username' => $name, 'role' => $role]);
        $this->container['userSession']->initialize($users->getById($id));
        $this->container['memoryCache']->flush();
    }

    private function gate(string $feature): StewardTestFeatureGate
    {
        $gate = new StewardTestFeatureGate($this->container);
        $gate->extraMap = [self::FAKE.'::*' => $feature];
        return $gate;
    }

    private function setFeature(string $feature, bool $on): void
    {
        $this->container['configModel']->save([SettingsSchema::featureKey($feature) => $on ? '1' : '0']);
        $this->container['memoryCache']->flush();
    }

    /** @return array{0:string,1:int} */
    private function expect404(StewardTestFeatureGate $gate): array
    {
        $this->sent = null;
        try {
            $gate->onBootstrap();
            $this->fail('gate did not stop the request');
        } catch (StewardGateHalted) {
        }
        $this->assertNotNull($this->sent, 'gate halted without a response');
        $this->assertSame(404, $this->sent[1]);
        return $this->sent;
    }

    public function testControllerNamesAreUnique(): void
    {
        $ours = array_map(fn ($f) => basename($f, '.php'), glob(dirname(__DIR__).'/Controller/*.php'));
        $this->assertNotEmpty($ours);

        $others = array_map(fn ($f) => strtolower(basename($f, '.php')), glob(ROOT_DIR.'/app/Controller/*.php'));
        foreach (glob(ROOT_DIR.'/plugins/*', GLOB_ONLYDIR) as $dir) {
            if (basename($dir) === 'Steward') {
                continue;
            }
            foreach (glob($dir.'/Controller/*.php') ?: [] as $file) {
                $others[] = strtolower(basename($file, '.php'));
            }
        }
        $this->assertContains('dashboardcontroller', $others, 'core controllers were not found');

        foreach ($ours as $name) {
            $this->assertStringStartsWith('Steward', $name);
            $this->assertNotContains(strtolower($name), $others, $name.' clashes with another controller');
        }
    }

    public function testEveryControllerActionIsMapped(): void
    {
        $gate = new FeatureGate($this->container);
        $accessMap = (fn () => $this->map)->call($this->container['applicationAccessMap']);
        $checked = 0;

        foreach (glob(dirname(__DIR__).'/Controller/*.php') as $file) {
            $class = 'Kanboard\\Plugin\\Steward\\Controller\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isStatic() || str_starts_with($method->getName(), '__')) {
                    continue;
                }
                $label = $reflection->getShortName().'::'.$method->getName();
                $feature = $gate->featureFor($reflection->getShortName(), $method->getName());
                $this->assertNotNull($feature, $label.' has no FeatureGate::MAP row');
                $this->assertTrue(
                    $feature === FeatureGate::ALWAYS || in_array($feature, SettingsSchema::FEATURES, true),
                    $label.' maps to unknown feature '.$feature
                );
                $this->assertArrayHasKey(
                    strtolower($method->getName()),
                    $accessMap[strtolower($reflection->getShortName())] ?? [],
                    $label.' has no application ACL entry'
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testMapRowsNameRealFeaturesAndControllers(): void
    {
        foreach (FeatureGate::MAP as $key => $feature) {
            [$controller] = explode('::', $key);
            $this->assertTrue(class_exists('Kanboard\\Plugin\\Steward\\Controller\\'.$controller), $key);
            $this->assertTrue($feature === FeatureGate::ALWAYS || in_array($feature, SettingsSchema::FEATURES, true), $key);
        }
    }

    public function testDisabledFeatureAnswersIdentical404ForAnonymousUserAndAdmin(): void
    {
        foreach (SettingsSchema::FEATURES as $feature) {
            $this->setFeature($feature, false);
            $this->route(['controller' => self::FAKE, 'action' => 'index', 'plugin' => 'Steward']);
            $bodies = [];
            foreach ([null, Role::APP_USER, Role::APP_ADMIN] as $role) {
                $this->loginAs($role);
                $bodies[] = $this->expect404($this->gate($feature))[0];
            }
            $this->assertCount(1, array_unique($bodies), $feature.': bodies differ by viewer');
            $this->assertStringNotContainsString($feature, $bodies[0]);
            $this->assertStringNotContainsString('Steward', $bodies[0]);
        }
    }

    public function testEnabledFeaturePassesAndAclAppliesAsNormal(): void
    {
        foreach (SettingsSchema::FEATURES as $feature) {
            $this->setFeature($feature, true);
            $this->route(['controller' => self::FAKE, 'action' => 'index', 'plugin' => 'Steward']);

            $this->loginAs(Role::APP_ADMIN);
            $this->sent = null;
            $this->gate($feature)->onBootstrap();
            $this->assertNull($this->sent, $feature);
            (new ApplicationAuthorizationMiddleware($this->container))->execute();

            $this->loginAs(Role::APP_USER);
            $this->gate($feature)->onBootstrap();
            $this->assertNull($this->sent, $feature);
            try {
                (new ApplicationAuthorizationMiddleware($this->container))->execute();
                $this->fail($feature.': ACL let a user through');
            } catch (AccessForbiddenException) {
            }
        }
    }

    public function testMatchingIgnoresCase(): void
    {
        $this->setFeature('banner', false);
        $this->route(['controller' => 'stewardfakecontroller', 'action' => 'INDEX', 'plugin' => 'steward']);
        $this->expect404($this->gate('banner'));
    }

    public function testUnmappedStewardActionIs404(): void
    {
        $this->route(['controller' => 'StewardNopeController', 'action' => 'index', 'plugin' => 'Steward']);
        $this->expect404($this->gate('banner'));
    }

    public function testAlwaysAvailableControllersPassWithEverythingOff(): void
    {
        foreach (SettingsSchema::FEATURES as $feature) {
            $this->setFeature($feature, false);
        }
        $this->route(['controller' => 'StewardSettingsController', 'action' => 'show', 'plugin' => 'Steward']);
        $this->gate('banner')->onBootstrap();
        $this->assertNull($this->sent);
    }

    public function testOtherPluginsAndCoreAreUntouched(): void
    {
        $this->setFeature('banner', false);
        $this->route(['controller' => self::FAKE, 'action' => 'index', 'plugin' => 'Pulse']);
        $this->gate('banner')->onBootstrap();
        $this->route(['controller' => 'DashboardController', 'action' => 'show']);
        $this->gate('banner')->onBootstrap();
        $this->assertNull($this->sent);
        $this->assertTrue((new FeatureGate($this->container))->check('', 'StewardNopeController', 'index'));
    }

    public function testNoRouterContextIsNoOp(): void
    {
        // API and queue workers dispatch app.bootstrap without ever dispatching the router.
        foreach (SettingsSchema::FEATURES as $feature) {
            $this->setFeature($feature, false);
        }
        unset($this->container['router']);
        $this->container['router'] = new Router($this->container);
        $this->container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
        $this->assertNull($this->sent);
    }

    public function testListenerIsRegisteredOnBootstrap(): void
    {
        $gates = array_filter(
            $this->container['dispatcher']->getListeners('app.bootstrap'),
            fn ($l) => is_array($l) && $l[0] instanceof FeatureGate && $l[1] === 'onBootstrap'
        );
        $this->assertCount(1, $gates);
    }
}
