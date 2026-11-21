<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Steward\Plugin;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * REVERSE_PROXY_AUTH is defined by core's constants.php from the environment when Base.php loads, so the
 * child process must inherit REVERSE_PROXY_AUTH=true. setUpBeforeClass runs in the parent before the child
 * is spawned; tearDownAfterClass clears it so no other suite sees it.
 */
class ReverseProxySwitchingTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        putenv('REVERSE_PROXY_AUTH=true');
    }

    public static function tearDownAfterClass(): void
    {
        putenv('REVERSE_PROXY_AUTH');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReverseProxyDisablesSwitching(): void
    {
        $this->assertTrue(REVERSE_PROXY_AUTH);
        $this->assertFalse(defined('STEWARD_DISABLE_SWITCHING'));
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1', 'steward_banner' => '1', 'steward_login_links' => '1']);
        $this->container['memoryCache']->flush();

        $this->assertFalse($this->container['stewardFeatures']->isEnabled('switching'));
        $this->assertFalse($this->container['stewardFeatures']->isEnabled('login_links'));
        $this->assertTrue($this->container['stewardFeatures']->forcedOff('login_links'));
        $this->assertTrue($this->container['stewardFeatures']->isEnabled('banner'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReverseProxyHidesTheSwitchButton(): void
    {
        $this->assertTrue(REVERSE_PROXY_AUTH);
        require_once __DIR__.'/../Schema/Sqlite.php';
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();
        $ana = $this->container['userModel']->create(['username' => 'ana', 'password' => 'secret-password']);
        $this->container['userSession']->initialize($this->container['userModel']->getById(1));

        $this->assertNotEmpty($this->container['hook']->getListeners('template:user:sidebar:actions'));
        $html = $this->container['helper']->hook->render('template:user:sidebar:actions', ['user' => $this->container['userModel']->getById($ana)]);
        $this->assertStringNotContainsString('StewardSwitchController', $html);
    }
}
