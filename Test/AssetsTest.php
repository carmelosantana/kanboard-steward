<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Steward\Plugin;

/** Spec §4 Markdown, client side: highlight.js and the Markdown script/styles load only with the `markdown` feature. */
class AssetsTest extends Base
{
    private const JS = ['plugins/Steward/Assets/vendor/highlight.min.js', 'plugins/Steward/Assets/js/steward-markdown.js'];
    private const CSS = ['plugins/Steward/Assets/vendor/highlight-github.css', 'plugins/Steward/Assets/css/markdown.css'];

    private function boot(bool $markdown): void
    {
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        $this->container['configModel']->save(['steward_markdown' => $markdown ? '1' : '0']);
        $this->container['memoryCache']->flush();
        (new Plugin($this->container))->initialize();
    }

    private function hooked(string $hook): array
    {
        return array_column($this->container['hook']->getListeners($hook), 'template');
    }

    public function testMarkdownAssetsAreRegisteredWhenMarkdownIsOn(): void
    {
        $this->boot(true);

        $js = $this->hooked('template:layout:js');
        $this->assertSame(self::JS, array_values(array_intersect($js, self::JS)), 'highlight.js loads before the script that calls it');
        $this->assertSame(self::CSS, array_values(array_intersect($this->hooked('template:layout:css'), self::CSS)));
        foreach (array_merge(self::JS, self::CSS) as $file) {
            $this->assertFileExists(dirname(__DIR__).'/'.substr($file, strlen('plugins/Steward/')));
        }
    }

    /** Mermaid is ~3.5 MB: steward-markdown.js injects it only on pages that hold a diagram. */
    public function testMermaidIsNeverAPageAsset(): void
    {
        $this->boot(true);

        foreach (array_merge($this->hooked('template:layout:js'), $this->hooked('template:layout:css')) as $file) {
            $this->assertStringNotContainsString('mermaid', $file);
        }
    }

    public function testNoMarkdownAssetsWhenMarkdownIsOff(): void
    {
        $this->boot(false);

        $this->assertSame([], array_intersect($this->hooked('template:layout:js'), self::JS));
        $this->assertSame([], array_intersect($this->hooked('template:layout:css'), self::CSS));
    }

    /** Core's CSP has no 'unsafe-inline' for scripts: every script tag Steward adds has a src and no body. */
    public function testLayoutScriptsAreExternalOnly(): void
    {
        $this->boot(true);

        $html = $this->container['helper']->hook->asset('js', 'template:layout:js');

        preg_match_all('!<script\b([^>]*)>(.*?)</script>!s', $html, $scripts, PREG_SET_ORDER);
        $this->assertCount(count($this->hooked('template:layout:js')), $scripts);
        foreach ($scripts as $script) {
            $this->assertMatchesRegularExpression('/\bsrc="[^"]+"/', $script[1]);
            $this->assertSame('', $script[2]);
        }
        $this->assertStringContainsString('plugins/Steward/Assets/js/steward-markdown.js?', $html);
    }

    /** The same registration serves any layout that renders template:layout:js/css (core's public board does). */
    public function testRegistrationIsOneReusableMethod(): void
    {
        $this->boot(false);
        $plugin = new Plugin($this->container);

        $plugin->registerMarkdownAssets();

        $this->assertSame(self::JS, array_values(array_intersect($this->hooked('template:layout:js'), self::JS)));
        $this->assertSame(self::CSS, array_values(array_intersect($this->hooked('template:layout:css'), self::CSS)));
    }
}
