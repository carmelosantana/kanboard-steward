<?php
require_once __DIR__.'/support/StewardTestCase.php';

use Kanboard\Plugin\Steward\Plugin;

// Tag == plugin.json == getPluginVersion(): drift across the three is the most common release defect.
class PluginVersionTest extends StewardTestCase
{
    public function testVersionsAgree(): void
    {
        $json = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true);
        $this->assertSame('1.0.0', $json['version']);
        $this->assertSame($json['version'], (new Plugin($this->container))->getPluginVersion());
        $this->assertSame([], $json['requires']);

        foreach ($json['recommends'] as $recommend) {
            $this->assertSame(['plugin', 'min_version', 'reason'], array_keys($recommend));
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $recommend['min_version']);
        }
    }
}
