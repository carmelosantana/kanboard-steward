<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Plugin\Steward\Plugin;

/** Harbor D7: Steward's layout wrapper includes the wrapper registered before it, not core's layout. */
class LayoutChainTest extends StewardTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        $this->container['configModel']->save(['steward_branding' => '1', 'steward_site_name' => 'Chained Site']);
        $this->container['memoryCache']->flush();
    }

    private function bootWithPriorWrapper(): void
    {
        $this->container['template']->setTemplateOverride('layout', 'Steward:../Test/fixtures/prior-layout');
        (new Plugin($this->container))->initialize();
        $this->container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
    }

    private function layout(): string
    {
        return $this->container['template']->render('layout', ['no_layout' => true, 'content_for_layout' => 'x']);
    }

    public function testStewardWrapsThePreviouslyRegisteredLayout()
    {
        $this->bootWithPriorWrapper();
        $html = $this->layout();

        $this->assertStringContainsString('data-prior-wrapper="1"', $html);
        $this->assertMatchesRegularExpression('#<title>\s*Chained Site\s*</title>#', $html);
    }

    public function testApplyingBrandingTwiceDoesNotWrapItself()
    {
        $this->bootWithPriorWrapper();
        $this->container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
        $html = $this->layout();

        $this->assertSame(1, substr_count($html, '<html'));
        $this->assertSame(1, substr_count($html, 'data-prior-wrapper="1"'));
    }

    public function testWithoutAPriorWrapperStewardStillWrapsCore()
    {
        (new Plugin($this->container))->initialize();
        $this->container['dispatcher']->dispatch(new \Symfony\Contracts\EventDispatcher\Event(), 'app.bootstrap');
        $html = $this->layout();

        $this->assertSame(1, substr_count($html, '<html'));
        $this->assertMatchesRegularExpression('#<title>\s*Chained Site\s*</title>#', $html);
    }
}
