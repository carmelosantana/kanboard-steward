<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Steward\Plugin;

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
    public function testReverseProxyDisablesSwitching(): void
    {
        $this->assertTrue(REVERSE_PROXY_AUTH);
        $this->assertFalse(defined('STEWARD_DISABLE_SWITCHING'));
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1', 'steward_banner' => '1']);
        $this->container['memoryCache']->flush();

        $this->assertFalse($this->container['stewardFeatures']->isEnabled('switching'));
        $this->assertTrue($this->container['stewardFeatures']->isEnabled('banner'));
    }
}
