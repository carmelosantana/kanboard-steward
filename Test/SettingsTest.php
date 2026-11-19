<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Steward\Controller\StewardSettingsController;
use Kanboard\Plugin\Steward\Model\FeatureFlags;
use Kanboard\Plugin\Steward\Model\SettingsSchema;
use Kanboard\Plugin\Steward\Plugin;

/** Renders only the page body: the full config layout needs a real logged-in session. */
class StewardBodyOnlyLayoutHelper extends \Kanboard\Helper\LayoutHelper
{
    public function config($template, array $params)
    {
        return $this->template->render($template, $params);
    }
}

class SettingsTest extends Base
{
    private string $html = '';
    private array $flashes = [];

    protected function setUp(): void
    {
        parent::setUp();
        // save() writes a settings.changed audit row.
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['helper']->register('layout', StewardBodyOnlyLayoutHelper::class);
    }

    private function stub(array $formValues, bool $admin = true): void
    {
        $session = $this->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['isAdmin', 'getId', 'isLogged', 'getRole'])->getMock();
        $session->method('isAdmin')->willReturn($admin);
        $session->method('getId')->willReturn(1);
        $session->method('isLogged')->willReturn(true);
        $session->method('getRole')->willReturn($admin ? Role::APP_ADMIN : Role::APP_MANAGER);
        unset($this->container['userSession']);
        $this->container['userSession'] = $session;

        $request = $this->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues'])->getMock();
        $request->expects($this->atMost(1))->method('getValues')->willReturn($formValues);
        unset($this->container['request']);
        $this->container['request'] = $request;

        $flash = $this->getMockBuilder(\Kanboard\Core\Session\FlashMessage::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['success', 'failure'])->getMock();
        $flash->method('success')->willReturnCallback(function ($m) { $this->flashes['success'] = $m; });
        $flash->method('failure')->willReturnCallback(function ($m) { $this->flashes['failure'] = $m; });
        unset($this->container['flash']);
        $this->container['flash'] = $flash;

        $response = $this->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['redirect', 'html'])->getMock();
        $response->method('html')->willReturnCallback(function ($h) { $this->html = $h; });
        unset($this->container['response']);
        $this->container['response'] = $response;
    }

    private function setting(string $key): string
    {
        $this->container['memoryCache']->flush();
        return (string) $this->container['configModel']->get($key, '');
    }

    public function testDefaultsOnFreshInstall(): void
    {
        $defaults = SettingsSchema::defaults();
        $this->assertSame('1', $defaults['steward_markdown']);
        foreach (array_diff(SettingsSchema::FEATURES, ['markdown']) as $feature) {
            $this->assertSame('0', $defaults['steward_'.$feature], $feature);
        }
        $this->assertSame('60', $defaults['steward_switch_idle_minutes']);
        $this->assertSame('180', $defaults['steward_audit_retention_days']);
        $this->assertCount(count(SettingsSchema::FEATURES) + 2 + count(SettingsSchema::BRANDING), $defaults);

        $flags = $this->container['stewardFeatures'];
        $this->assertInstanceOf(FeatureFlags::class, $flags);
        $this->assertTrue($flags->isEnabled('markdown'));
        foreach (array_diff(SettingsSchema::FEATURES, ['markdown']) as $feature) {
            $this->assertFalse($flags->isEnabled($feature), $feature);
        }
        $this->assertFalse($flags->isEnabled('nonsense'));
    }

    public function testStoredSwitchesWin(): void
    {
        $this->container['configModel']->save(['steward_markdown' => '0', 'steward_switching' => '1', 'steward_banner' => '1']);
        $this->container['memoryCache']->flush();
        $flags = $this->container['stewardFeatures'];
        $this->assertFalse($flags->isEnabled('markdown'));
        $this->assertTrue($flags->isEnabled('switching'));
        $this->assertTrue($flags->isEnabled('banner'));
    }

    public function testFilterDropsUnknownKeys(): void
    {
        $this->assertSame(
            ['steward_banner' => '1'],
            SettingsSchema::filter(['steward_banner' => '1', 'application_url' => 'x', 'api_token' => 'y'])
        );
    }

    public function testFilterCastsBooleansAndClampsNumbers(): void
    {
        $this->assertSame(
            [
                'steward_markdown' => '0',
                'steward_switching' => '1',
                'steward_switch_idle_minutes' => '5',
                'steward_audit_retention_days' => '3650',
            ],
            SettingsSchema::filter([
                'steward_markdown' => '',
                'steward_switching' => 'on',
                'steward_switch_idle_minutes' => '1',
                'steward_audit_retention_days' => '99999',
            ])
        );
        $this->assertSame(
            ['steward_switch_idle_minutes' => '480', 'steward_audit_retention_days' => '7'],
            SettingsSchema::filter(['steward_switch_idle_minutes' => '1000', 'steward_audit_retention_days' => '-3'])
        );
        $this->assertSame(['steward_switch_idle_minutes' => '90'], SettingsSchema::filter(['steward_switch_idle_minutes' => '90']));
        // Non-numeric input keeps the stored value rather than collapsing to a bound.
        $this->assertSame([], SettingsSchema::filter(['steward_switch_idle_minutes' => 'abc', 'steward_audit_retention_days' => ['1']]));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testKillSwitchConstant(): void
    {
        $this->assertFalse(REVERSE_PROXY_AUTH);
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();
        $this->assertTrue($this->container['stewardFeatures']->isEnabled('switching'));

        define('STEWARD_DISABLE_SWITCHING', true);
        $this->assertFalse($this->container['stewardFeatures']->isEnabled('switching'));
        $this->assertTrue($this->container['stewardFeatures']->isEnabled('markdown'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testKillSwitchDefinedFalseLeavesSwitchingOn(): void
    {
        define('STEWARD_DISABLE_SWITCHING', false);
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();
        $this->assertTrue($this->container['stewardFeatures']->isEnabled('switching'));
    }

    public function testSettingsActionsAreAdminOnlyInAcl(): void
    {
        foreach (['show', 'save'] as $action) {
            $this->assertSame(
                [Role::APP_ADMIN],
                $this->container['applicationAccessMap']->getRoles('StewardSettingsController', $action),
                $action
            );
        }
    }

    public function testNonAdminGetsForbidden(): void
    {
        foreach (['show', 'save'] as $action) {
            $this->stub(['steward_banner' => '1'], false);
            try {
                (new StewardSettingsController($this->container))->$action();
                $this->fail("expected AccessForbiddenException on $action");
            } catch (AccessForbiddenException $e) {
            }
        }
        $this->assertSame('', $this->setting('steward_banner'));
    }

    public function testShowRendersEverySwitchAndNumber(): void
    {
        $this->container['configModel']->save(['steward_banner' => '1', 'steward_switch_idle_minutes' => '45']);
        $this->container['memoryCache']->flush();
        $this->stub([]);
        (new StewardSettingsController($this->container))->show();

        foreach (SettingsSchema::FEATURES as $feature) {
            $this->assertStringContainsString('name="steward_'.$feature.'"', $this->html, $feature);
        }
        $this->assertMatchesRegularExpression('/type="checkbox" name="steward_banner"[^>]*checked/', $this->html);
        $this->assertMatchesRegularExpression('/type="checkbox" name="steward_markdown"[^>]*checked/', $this->html);
        $this->assertDoesNotMatchRegularExpression('/type="checkbox" name="steward_explore"[^>]*checked/', $this->html);
        $this->assertStringContainsString('id="form-steward_switch_idle_minutes"', $this->html);
        $this->assertStringContainsString('value="45"', $this->html);
        $this->assertStringContainsString('id="form-steward_audit_retention_days"', $this->html);
        $this->assertStringContainsString('name="csrf_token"', $this->html);
    }

    public function testSaveWritesOnlySchemaKeys(): void
    {
        $this->container['configModel']->save(['application_url' => 'http://kb.example/']);
        $this->stub([
            'steward_banner' => '1',
            'steward_markdown' => '0',
            'steward_switch_idle_minutes' => '9999',
            'application_url' => 'http://evil.example/',
            'api_token' => 'stolen',
        ]);
        (new StewardSettingsController($this->container))->save();

        $this->assertSame('1', $this->setting('steward_banner'));
        $this->assertSame('0', $this->setting('steward_markdown'));
        $this->assertSame('480', $this->setting('steward_switch_idle_minutes'));
        $this->assertSame('http://kb.example/', $this->setting('application_url'));
        $this->assertNotSame('stolen', $this->setting('api_token'));
        $this->assertArrayHasKey('success', $this->flashes);
    }

    public function testSaveWithoutCsrfIsForbidden(): void
    {
        $this->stub([]);
        try {
            (new StewardSettingsController($this->container))->save();
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
        }
        $this->assertSame('', $this->setting('steward_banner'));
    }
}
