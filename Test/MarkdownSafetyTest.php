<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Log\Logger;
use Kanboard\Helper\TextHelper;
use Kanboard\Plugin\Steward\Core\StewardMarkdown;
use Kanboard\Plugin\Steward\Helper\StewardTextHelper;
use Kanboard\Plugin\Steward\Plugin;

/** Collects log lines so an expected error never reaches stderr. */
class StewardMarkdownLogCollector extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

/** Spec §4 Markdown, safety: escaping, the URL allow-list, public-mode #N links and the heading fix. */
class MarkdownSafetyTest extends Base
{
    /** Each vector is used as a link target and as an image source; none may survive as an href/src. */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'entity' => ['java&#x73;cript:alert(1)'],
            'decimal entity colon' => ['javascript&#58;alert(1)'],
            'control char' => ["\x01javascript:alert(1)"],
            'leading space' => [' javascript:alert(1)'],
            'embedded tab' => ["java\tscript:alert(1)"],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data html' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'data png' => ['data:image/png;base64,iVBORw0KGgo='],
            'protocol relative' => ['//evil.example/x'],
            'backslash protocol relative' => ['\\\\evil.example/x'],
            'mixed slash protocol relative' => ['/\\evil.example/x'],
            'ftp' => ['ftp://example.com/file'],
        ];
    }

    public static function safeUrls(): array
    {
        return [
            'http' => ['http://example.com/a', 'http://example.com/a'],
            'https' => ['https://example.com/a?b=1', 'https://example.com/a?b=1'],
            'mailto' => ['mailto:someone@example.com', 'mailto:someone@example.com'],
            'absolute path' => ['/relative/path', '/relative/path'],
            'query' => ['?controller=TaskViewController&action=show&task_id=1', '?controller=TaskViewController&action=show&task_id=1'],
            'fragment' => ['#frag', '#frag'],
            'relative path' => ['path/x', 'path/x'],
        ];
    }

    private function parser(bool $public = false, ?string $token = null, bool $plain = false): StewardMarkdown
    {
        return new StewardMarkdown($this->container, $public, $token, $plain);
    }

    /** @return list<array{name:string,attr:string,value:string}> every href/src in the HTML, entities decoded. */
    private function urls(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>');
        libxml_clear_errors();
        $found = [];
        foreach ((new DOMXPath($doc))->query('//@href | //@src') as $attr) {
            $found[] = ['name' => $attr->ownerElement->nodeName, 'attr' => $attr->nodeName, 'value' => $attr->value];
        }
        return $found;
    }

    private function text(string $html): string
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>');
        libxml_clear_errors();
        return $doc->textContent;
    }

    /** What a browser would read as the scheme: strip C0 controls and spaces, lower-case. */
    private static function browserScheme(string $url): ?string
    {
        $url = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $url));
        if (str_starts_with($url, '//') || str_starts_with($url, '\\\\') || str_starts_with($url, '/\\') || str_starts_with($url, '\\/')) {
            return '//';
        }
        return preg_match('/^([a-z][a-z0-9+.\-]*):/', $url, $m) ? $m[1] : null;
    }

    private function assertNoUnsafeUrl(string $html): void
    {
        foreach ($this->urls($html) as $url) {
            $scheme = self::browserScheme($url['value']);
            $this->assertTrue(
                $scheme === null || in_array($scheme, ['http', 'https', 'mailto'], true),
                'Unsafe '.$url['attr'].' survived: '.var_export($url['value'], true).' in '.$html
            );
        }
    }

    private function createProject(string $name, bool $public): int
    {
        $id = $this->container['projectModel']->create(['name' => $name]);
        if ($public) {
            $this->assertTrue($this->container['projectModel']->enablePublicAccess($id));
        }
        return $id;
    }

    private function createTask(int $projectId, string $title, string $description = ''): int
    {
        return $this->container['taskCreationModel']->create([
            'project_id' => $projectId,
            'title' => $title,
            'description' => $description,
        ]);
    }

    private function token(int $projectId): string
    {
        return $this->container['projectModel']->getById($projectId)['token'];
    }

    private function useRequest(array $get): void
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], $get + ['_' => '1']);
    }

    private function boot(bool $markdown): void
    {
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        $this->container['configModel']->save(['steward_markdown' => $markdown ? '1' : '0']);
        $this->container['memoryCache']->flush();
        (new Plugin($this->container))->initialize();
    }

    // --- escaping and URLs -----------------------------------------------------------------------------

    public function testRawHtmlIsEscaped(): void
    {
        $html = $this->parser()->text("<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @dataProvider unsafeUrls */
    public function testUnsafeLinkBecomesItsText(string $url): void
    {
        $html = $this->parser()->text('Go [click **here**]('.$url.') now');

        $this->assertNoUnsafeUrl($html);
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringContainsString('click <strong>here</strong>', $html);
        // A tab is expanded to spaces before inline parsing, so that vector never becomes a link at all.
        $this->assertMatchesRegularExpression('/Go \\[?click here/', $this->text($html));
    }

    /** @dataProvider unsafeUrls */
    public function testUnsafeReferenceLinkBecomesItsText(string $url): void
    {
        $html = $this->parser()->text("See [docs][1].\n\n[1]: ".ltrim($url, " "));

        $this->assertNoUnsafeUrl($html);
        $this->assertStringContainsString('docs', $this->text($html));
    }

    /** @dataProvider unsafeUrls */
    public function testUnsafeImageBecomesItsAltText(string $url): void
    {
        $html = $this->parser()->text('Look ![a <b>cat</b>]('.$url.') here');

        $this->assertNoUnsafeUrl($html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('a &lt;b&gt;cat&lt;/b&gt;', $html);
    }

    public function testUnsafeAutolinkIsNeutralised(): void
    {
        $html = $this->parser()->text('<javascript://%0aalert(1)>');

        $this->assertNoUnsafeUrl($html);
        $this->assertStringNotContainsString('<a', $html);
    }

    /** @dataProvider safeUrls */
    public function testSafeLinkIsKept(string $url, string $expected): void
    {
        $html = $this->parser()->text('[click]('.$url.')');
        $urls = $this->urls($html);

        $this->assertCount(1, $urls, $html);
        $this->assertSame(['name' => 'a', 'attr' => 'href', 'value' => $expected], $urls[0]);
        $this->assertSame('click', trim($this->text($html)));
    }

    /** @dataProvider safeUrls */
    public function testSafeImageIsKept(string $url, string $expected): void
    {
        $html = $this->parser()->text('![pic]('.$url.')');
        $urls = $this->urls($html);

        $this->assertCount(1, $urls, $html);
        $this->assertSame(['name' => 'img', 'attr' => 'src', 'value' => $expected], $urls[0]);
    }

    public function testBareUrlIsStillAutolinked(): void
    {
        $html = $this->parser()->text('Visit https://example.com/x today');

        $this->assertSame([['name' => 'a', 'attr' => 'href', 'value' => 'https://example.com/x']], $this->urls($html));
    }

    public function testSafetyHoldsWhenSafeModeIsSwitchedOffAgain(): void
    {
        $parser = $this->parser();
        $parser->setSafeMode(false);

        $html = $parser->text('[x](javascript:alert(1)) ![y](data:image/png;base64,AAAA)');

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame('x y', trim($this->text($html)));
    }

    /** Task 17 adds an id and a trailing md-anchor link to every heading; these tests check what is inside. */
    private function withoutAnchor(string $html): string
    {
        $html = (string) preg_replace('!<(h[1-6]) id="md-[a-z0-9-]+">!', '<$1>', $html);
        return (string) preg_replace('! <a class="md-anchor" href="#md-[a-z0-9-]+">#</a>(</h[1-6]>)!', '$1', $html);
    }

    // --- headings ----------------------------------------------------------------------------------------

    public function testHeadingWithTaskReferenceKeepsItsText(): void
    {
        $projectId = $this->createProject('A', false);
        $taskId = $this->createTask($projectId, 'Task');

        $html = $this->withoutAnchor($this->parser()->text('## Notes on #'.$taskId));

        $this->assertMatchesRegularExpression('!<h2>Notes on <a href="[^"]*task_id='.$taskId.'[^"]*">#'.$taskId.'</a></h2>!', $html);
    }

    public function testLineStartingWithTaskReferenceKeepsTheRestOfTheLine(): void
    {
        $projectId = $this->createProject('A', false);
        $taskId = $this->createTask($projectId, 'Task');

        $html = $this->withoutAnchor($this->parser()->text('#'.$taskId.' is done'));

        $this->assertStringContainsString('#'.$taskId.' is done', $this->text($html));
        $this->assertStringContainsString('task_id='.$taskId, $html);
        $this->assertStringNotContainsString('<h1', $html);
    }

    public function testPlainHeadingIsUnchanged(): void
    {
        $this->assertSame('<h1>Title</h1>', $this->withoutAnchor($this->parser()->text('# Title')));
        $this->assertSame('<h3>Title</h3>', $this->withoutAnchor($this->parser()->text('### Title ###')));
        $this->assertSame('<p>####### Seven</p>', $this->parser()->text('####### Seven'));
    }

    public function testHeadingStartingWithTaskReferenceKeepsTheHashAndLinks(): void
    {
        $taskId = $this->createTask($this->createProject('A', false), 'Task');

        $html = $this->withoutAnchor($this->parser()->text('## #'.$taskId.' notes'));

        $this->assertMatchesRegularExpression('!^<h2><a href="[^"]*task_id='.$taskId.'[^"]*">#'.$taskId.'</a> notes</h2>$!', $html);
    }

    /** Parsedown 1.7 makes `##42 x` an h2 (no space needed); the last marker hash belongs to the #N. */
    public function testHeadingWithoutSpaceBeforeTaskReferenceKeepsTheHash(): void
    {
        $taskId = $this->createTask($this->createProject('A', false), 'Task');

        $html = $this->withoutAnchor($this->parser()->text('##'.$taskId.' x'));

        $this->assertMatchesRegularExpression('!^<h2><a href="[^"]*task_id='.$taskId.'[^"]*">#'.$taskId.'</a> x</h2>$!', $html);
    }

    public function testHeadingTaskReferenceFollowsPublicAndPlainRules(): void
    {
        $projectA = $this->createProject('A', true);
        $projectB = $this->createProject('B', true);
        $taskA = $this->createTask($projectA, 'In A');

        $matching = $this->withoutAnchor($this->parser(true, $this->token($projectA))->text('## #'.$taskA.' notes'));
        $this->assertStringContainsString('token='.$this->token($projectA), $matching);
        $this->assertSame('#'.$taskA.' notes', trim($this->text($matching)));

        $this->assertSame('<h2>#'.$taskA.' notes</h2>', $this->withoutAnchor($this->parser(true, $this->token($projectB))->text('## #'.$taskA.' notes')));
        $this->assertSame('<h2>#'.$taskA.' notes</h2>', $this->withoutAnchor($this->parser(true, null)->text('## #'.$taskA.' notes')));
        $this->assertSame('<h2>#'.$taskA.' notes</h2>', $this->withoutAnchor($this->parser(false, null, true)->text('## #'.$taskA.' notes')));
        $this->assertSame('<h2>#'.$taskA.' x</h2>', $this->withoutAnchor($this->parser(false, null, true)->text('##'.$taskA.' x')));
    }

    // --- task references and mentions --------------------------------------------------------------------

    public function testPublicModeLinksOnlyTasksOfTheRequestProject(): void
    {
        $projectA = $this->createProject('A', true);
        $projectB = $this->createProject('B', true);
        $taskA = $this->createTask($projectA, 'In A');
        $taskB = $this->createTask($projectB, 'In B');

        $html = $this->parser(true, $this->token($projectA))->text('See #'.$taskA.' and #'.$taskB);

        $urls = $this->urls($html);
        $this->assertCount(1, $urls, $html);
        $this->assertStringContainsString('token='.$this->token($projectA), $urls[0]['value']);
        $this->assertStringContainsString('task_id='.$taskA, $urls[0]['value']);
        $this->assertStringNotContainsString($this->token($projectB), $html);
        $this->assertStringContainsString('See #'.$taskA.' and #'.$taskB, $this->text($html));
    }

    public function testPublicModeWithoutRequestTokenLinksNothing(): void
    {
        $projectA = $this->createProject('A', true);
        $taskA = $this->createTask($projectA, 'In A');

        $html = $this->parser(true, null)->text('See #'.$taskA);

        $this->assertSame([], $this->urls($html));
        $this->assertStringNotContainsString($this->token($projectA), $html);
    }

    public function testHelperTakesTheTokenFromTheRequest(): void
    {
        $projectA = $this->createProject('A', true);
        $projectB = $this->createProject('B', true);
        $taskA = $this->createTask($projectA, 'In A');
        $taskB = $this->createTask($projectB, 'In B');
        $this->useRequest(['token' => $this->token($projectA)]);

        $html = (new StewardTextHelper($this->container))->markdown('#'.$taskA.' #'.$taskB, true);

        $this->assertCount(1, $this->urls($html), $html);
        $this->assertStringContainsString('token='.$this->token($projectA), $html);
        $this->assertStringNotContainsString($this->token($projectB), $html);
    }

    public function testHelperIgnoresANonStringToken(): void
    {
        $projectA = $this->createProject('A', true);
        $taskA = $this->createTask($projectA, 'In A');
        $this->useRequest(['token' => [$this->token($projectA)]]);

        $html = (new StewardTextHelper($this->container))->markdown('#'.$taskA, true);

        $this->assertSame([], $this->urls($html));
    }

    public function testMarkdownPublicKeepsReferencesAndMentionsAsText(): void
    {
        $projectA = $this->createProject('A', true);
        $taskA = $this->createTask($projectA, 'In A');
        $this->useRequest(['token' => $this->token($projectA)]);

        $html = (new StewardTextHelper($this->container))->markdownPublic('#'.$taskA.' by @admin');

        $this->assertSame([], $this->urls($html));
        $this->assertSame('#'.$taskA.' by @admin', trim($this->text($html)));
    }

    public function testPrivateModeKeepsCoreLinksAndMentions(): void
    {
        $projectA = $this->createProject('A', false);
        $taskA = $this->createTask($projectA, 'In A');

        $steward = (new StewardTextHelper($this->container))->markdown("See #$taskA by @admin\nline two");
        $core = (new TextHelper($this->container))->markdown("See #$taskA by @admin\nline two");

        $this->assertSame($core, $steward);
        $this->assertStringContainsString('class="user-mention-link"', $steward);
        $this->assertStringContainsString('<br />', $steward);
    }

    public function testHelperRendersEmptyTextAsEmpty(): void
    {
        $helper = new StewardTextHelper($this->container);

        $this->assertSame('', $helper->markdown(null));
        $this->assertSame('', $helper->markdown(''));
        $this->assertSame('', $helper->markdownPublic(''));
        // A project with no description has null there.
        $this->assertSame('', $helper->markdownPublic(null));
    }

    // --- registration and the notification email ------------------------------------------------------

    public function testHelperIsRegisteredOnlyWhenMarkdownIsOn(): void
    {
        $this->boot(true);
        $this->assertInstanceOf(StewardTextHelper::class, $this->container['helper']->text);
    }

    public function testCoreHelperStaysWhenMarkdownIsOff(): void
    {
        $this->boot(false);
        $this->assertNotInstanceOf(StewardTextHelper::class, $this->container['helper']->text);
        $this->assertInstanceOf(TextHelper::class, $this->container['helper']->text);
    }

    /**
     * A plugin loaded before Steward that reads helper->text in its initialize() freezes the Pimple entry, so
     * register() throws FrozenServiceException. Core's Loader would catch it and skip the rest of Steward's
     * initialize() (ACL, feature gate, switch guard), so Steward must carry on without the swap.
     */
    public function testFrozenTextHelperDoesNotAbortInitialize(): void
    {
        $log = new StewardMarkdownLogCollector();
        $logger = new Logger();
        $logger->setLogger($log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;
        $this->container['helper']->text->e('freeze');

        $this->boot(true);

        $this->assertSame([['error', 'Steward: text helper already in use before Steward loaded; safer Markdown is off']], $log->lines);

        $this->assertNotInstanceOf(StewardTextHelper::class, $this->container['helper']->text);
        $this->assertNotEmpty($this->container['hook']->getListeners('template:config:sidebar'));
    }

    public function testTaskCreatedEmailUsesTheSafeParser(): void
    {
        $this->boot(true);
        $projectId = $this->createProject('A', true);
        $taskId = $this->createTask($projectId, 'Mail', "Hi [click me](JaVaScRiPt:alert(1)) and ![img](data:image/png;base64,AAAA)\n\n<script>x</script>");

        $html = $this->container['template']->render('notification/task_create', [
            'task' => $this->container['taskFinderModel']->getDetails($taskId),
        ]);

        $this->assertNoUnsafeUrl($html);
        $this->assertStringContainsString('click me', $html);
        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringNotContainsString('javascript', strtolower($html));
    }
}
