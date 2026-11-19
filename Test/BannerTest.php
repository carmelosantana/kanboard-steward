<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';
require_once __DIR__.'/support/BrandingDoubles.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardBannerController;
use Kanboard\Plugin\Steward\Core\InlineMarkdown;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\Banner;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\PublicPage;
use Kanboard\Plugin\Steward\Model\SettingsSchema;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;

class StewardBannerLogSink extends \Kanboard\Core\Log\Base
{
    public function log($level, $message, array $context = []): void {}
}

/** Spec §7 announcement banner: renderer, schedule, dismissal, where it shows. */
class BannerTest extends Base
{
    private const ADMIN = 1;
    private const NOW = 1900000000; // 2030-03-17

    private int $now = self::NOW;
    private int $ana;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        \Kanboard\Plugin\Steward\Schema\version_2($this->container['db']->getConnection());
        $this->container['configModel']->save(['steward_banner' => '1']);
        $this->container['memoryCache']->flush();
        (new Plugin($this->container))->initialize();
        unset($this->container['stewardBanner']);
        $this->container['stewardBanner'] = fn ($c) => new Banner($c, fn () => $this->now);
        $this->container['helper']->register('layout', StewardBrandingBodyOnlyLayoutHelper::class);

        $logger = new Logger();
        $logger->setLogger(new StewardBannerLogSink());
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $this->ana = (new UserModel($this->container))->create(['username' => 'ana', 'name' => 'Ana', 'password' => 'secret-password']);
        $this->loginAs(self::ADMIN);
    }

    protected function tearDown(): void
    {
        session_set('flash', []);
        parent::tearDown();
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['userSession']->setPostAuthenticationAsValidated();
        $this->container['memoryCache']->flush();
    }

    private function logout(): void
    {
        session_set('user', []);
        session_remove('user');
        $this->container['memoryCache']->flush();
    }

    private function md(string $text): string
    {
        return InlineMarkdown::render($text);
    }

    private function banner(): Banner
    {
        return $this->container['stewardBanner'];
    }

    /** Stores settings directly, as a saved form would. */
    private function set(array $values): void
    {
        $this->container['configModel']->save($values + ['steward_banner_message' => 'Maintenance tonight']);
        $this->container['memoryCache']->flush();
    }

    private function setting(string $key): string
    {
        $this->container['memoryCache']->flush();
        return (string) $this->container['configModel']->getOption($key, '');
    }

    private function call(string $action, array $post, bool $csrf = true, string $method = 'POST'): StewardBrandingResponse
    {
        if ($csrf) {
            $post['csrf_token'] = $this->container['token']->getCSRFToken();
        }
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => $method],
            ['controller' => 'StewardBannerController', 'action' => $action, 'plugin' => 'Steward'], $post, [], []);
        $response = new StewardBrandingResponse($this->container);
        unset($this->container['response']);
        $this->container['response'] = $response;
        (new StewardBannerController($this->container))->$action();
        return $response;
    }

    private function save(array $post, bool $csrf = true, string $method = 'POST'): StewardBrandingResponse
    {
        return $this->call('save', $post + [
            'steward_banner_message' => 'Maintenance tonight',
            'steward_banner_level' => 'info',
            'steward_banner_start' => '',
            'steward_banner_end' => '',
        ], $csrf, $method);
    }

    private function dismiss(array $post, bool $csrf = true, string $method = 'POST'): StewardBrandingResponse
    {
        return $this->call('dismiss', $post, $csrf, $method);
    }

    private function forbidden(callable $fn, string $label = ''): void
    {
        try {
            $fn();
            $this->fail('expected AccessForbiddenException '.$label);
        } catch (AccessForbiddenException) {
            $this->addToAssertionCount(1);
        }
    }

    private function flash(): array
    {
        return session_exists('flash') ? (array) session_get('flash') : [];
    }

    private function auditRows(): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->eq('event', 'banner.changed')->asc('id')->findAll();
    }

    private function hook(string $name): string
    {
        return $this->container['helper']->hook->render($name, []);
    }

    private function dismissed(int $userId): string
    {
        return (string) $this->container['userMetadataModel']->get($userId, Banner::DISMISSED_KEY, '');
    }

    // --- renderer ---------------------------------------------------------------------------------------

    public function testInlineFormattingIsTheOnlyMarkupProduced(): void
    {
        $this->assertSame('a <em>b</em> <em>c</em> <strong>d</strong> <strong>e</strong> <code>f</code>',
            $this->md('a *b* _c_ **d** __e__ `f`'));
        $this->assertSame('See <a href="https://example.com/a?b=1&amp;c=2" rel="noopener noreferrer">the <em>docs</em></a>.',
            $this->md('See [the *docs*](https://example.com/a?b=1&c=2).'));
        $this->assertSame('<a href="http://example.com" rel="noopener noreferrer">x</a>', $this->md('[x](http://example.com)'));
        $this->assertSame('<code>**not bold** &lt;b&gt;</code>', $this->md('`**not bold** <b>`'));
        $this->assertSame('snake_case_name stays', $this->md('snake_case_name stays'));
        $this->assertSame('one two three', $this->md("one\ntwo\r\nthree"));
        $this->assertSame('', $this->md(''));
    }

    public function testNestedFragmentsAreRestoredAndControlBytesNeverSurvive(): void
    {
        $cases = [
            '[`v2`](https://x.example)' => '<a href="https://x.example" rel="noopener noreferrer"><code>v2</code></a>',
            '*see `cfg`*' => '<em>see <code>cfg</code></em>',
            '[**big** _news_](https://x.example)' => '<a href="https://x.example" rel="noopener noreferrer"><strong>big</strong> <em>news</em></a>',
            '**[`a`](https://x.example)**' => '<strong><a href="https://x.example" rel="noopener noreferrer"><code>a</code></a></strong>',
            "a\x010\x01b `c`" => 'a 0 b <code>c</code>',
            "\x010\x01" => '0',
        ];
        foreach ($cases as $in => $out) {
            $html = $this->md($in);
            $this->assertSame($out, trim($html), json_encode($in));
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x7f]/', $html, json_encode($in));
        }
    }

    public function testHtmlImagesBlocksAndMermaidRenderAsText(): void
    {
        $cases = [
            '<img src=x onerror=alert(1)>' => '&lt;img src=x onerror=alert(1)&gt;',
            '<script>alert(1)</script>' => '&lt;script&gt;alert(1)&lt;/script&gt;',
            '![alt](https://example.com/x.png)' => '![alt](https://example.com/x.png)',
            '# Heading' => '# Heading',
            '- item' => '- item',
            '1. item' => '1. item',
            '> quote' => '&gt; quote',
            '```js alert(1)```' => '```js alert(1)```',
            "```mermaid\ngraph TD; A-->B\n```" => '```mermaid graph TD; A--&gt;B ```',
            '"quotes" & \'apos\'' => '&quot;quotes&quot; &amp; &#039;apos&#039;',
        ];
        foreach ($cases as $in => $out) {
            $html = $this->md($in);
            $this->assertSame($out, $html, $in);
            $this->assertDoesNotMatchRegularExpression('/<(?!\/?(em|strong|code|a)[ >])/', $html, $in);
        }
    }

    public function testOnlyHttpLinksWithAHostBecomeLinks(): void
    {
        foreach ([
            '[x](javascript:alert(1))',
            '[x](javascript:alert)',
            '[x](JaVaScRiPt:alert)',
            '[x](&#106;avascript:alert)',
            '[x](data:text/html,hi)',
            '[x](//evil.example)',
            '[x](/local/path)',
            '[x](http:/nohost)',
            '[x](https://)',
            '[x](mailto:a@b.c)',
            '[x](vbscript:msgbox)',
            '[x]( javascript:alert)',
        ] as $in) {
            $html = $this->md($in);
            $this->assertStringNotContainsString('<a', $html, $in);
            $this->assertStringNotContainsString('href', $html, $in);
        }
        $this->assertSame('[x](javascript:alert)', $this->md('[x](javascript:alert)'));
        $this->assertSame('<a href="https://ok.example/&quot;onmouseover=x" rel="noopener noreferrer">x</a>',
            $this->md('[x](https://ok.example/"onmouseover=x)'));
    }

    // --- settings and schedule --------------------------------------------------------------------------

    public function testSchemaOwnsBannerKeysButSettingsFormCannotSetThem(): void
    {
        $defaults = SettingsSchema::defaults();
        foreach (array_keys(SettingsSchema::BANNER) as $key) {
            $this->assertArrayHasKey($key, $defaults);
            $this->assertStringStartsWith('steward_banner_', $key);
        }
        $this->assertSame([], SettingsSchema::filter(array_fill_keys(array_keys(SettingsSchema::BANNER), 'x')));
    }

    public function testCurrentIsNullWithFeatureOffOrNoMessage(): void
    {
        $this->assertNull($this->banner()->current());
        $this->set(['steward_banner_message' => 'Hi']);
        $this->assertNotNull($this->banner()->current());
        $this->set(['steward_banner' => '0', 'steward_banner_message' => 'Hi']);
        $this->assertNull($this->banner()->current());
    }

    public function testCurrentShape(): void
    {
        $this->set(['steward_banner_message' => 'Hello **all**', 'steward_banner_level' => 'warning',
            'steward_banner_dismissible' => '0', 'steward_banner_login' => '1', 'steward_banner_public' => '0']);
        $banner = $this->banner()->current();
        $this->assertSame('warning', $banner['level']);
        $this->assertSame('Hello <strong>all</strong>', $banner['html']);
        $this->assertFalse($banner['dismissible']);
        $this->assertTrue($banner['login']);
        $this->assertFalse($banner['public']);
        $this->assertSame(substr(hash('sha256', 'Hello **all**|warning||'), 0, 16), $banner['version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $banner['version']);
    }

    public function testScheduleWindow(): void
    {
        $this->set(['steward_banner_start' => (string) (self::NOW + 60), 'steward_banner_end' => (string) (self::NOW + 3600)]);
        $this->assertNull($this->banner()->current(), 'before start');
        $this->now = self::NOW + 60;
        $this->assertNotNull($this->banner()->current(), 'at start');
        $this->now = self::NOW + 3599;
        $this->assertNotNull($this->banner()->current(), 'before end');
        $this->now = self::NOW + 3600;
        $this->assertNull($this->banner()->current(), 'at end');

        $this->set(['steward_banner_start' => '', 'steward_banner_end' => (string) (self::NOW + 3600)]);
        $this->now = self::NOW;
        $this->assertNotNull($this->banner()->current(), 'open start');
        $this->set(['steward_banner_start' => (string) (self::NOW - 10), 'steward_banner_end' => '']);
        $this->now = self::NOW + 10 ** 6;
        $this->assertNotNull($this->banner()->current(), 'open end');
    }

    public function testVersionChangesWithMessageLevelAndTimes(): void
    {
        $this->set([]);
        $versions = [$this->banner()->current()['version']];
        $this->set(['steward_banner_message' => 'Other']);
        $versions[] = $this->banner()->current()['version'];
        $this->set(['steward_banner_message' => 'Other', 'steward_banner_level' => 'warning']);
        $versions[] = $this->banner()->current()['version'];
        $this->set(['steward_banner_message' => 'Other', 'steward_banner_level' => 'warning', 'steward_banner_end' => (string) (self::NOW + 99)]);
        $versions[] = $this->banner()->current()['version'];
        $this->assertCount(4, array_unique($versions));

        $this->set(['steward_banner_message' => 'Other', 'steward_banner_level' => 'warning', 'steward_banner_end' => (string) (self::NOW + 99), 'steward_banner_login' => '1']);
        $this->assertSame($versions[3], $this->banner()->current()['version'], 'where it shows is not part of the version');
    }

    // --- save -------------------------------------------------------------------------------------------

    public function testSaveStoresSettingsAndAuditsChangedKeys(): void
    {
        $response = $this->save([
            'steward_banner_message' => 'Maintenance at **10pm**',
            'steward_banner_level' => 'warning',
            'steward_banner_start' => '2030-01-01 10:00',
            'steward_banner_end' => '2030-01-02 10:00',
            'steward_banner_dismissible' => '1',
            'steward_banner_login' => '1',
            'steward_banner_public' => '0',
            'steward_switching' => '1',
        ]);
        $this->assertStringContainsString('StewardBannerController', $response->headers()['Location'] ?? '');
        $this->assertSame('Maintenance at **10pm**', $this->setting('steward_banner_message'));
        $this->assertSame('warning', $this->setting('steward_banner_level'));
        $this->assertSame((string) strtotime('2030-01-01 10:00'), $this->setting('steward_banner_start'));
        $this->assertSame((string) strtotime('2030-01-02 10:00'), $this->setting('steward_banner_end'));
        $this->assertSame('1', $this->setting('steward_banner_login'));
        $this->assertNotSame('1', $this->setting('steward_switching'), 'only banner keys');
        $this->assertArrayHasKey('success', $this->flash());

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::ADMIN, (int) $rows[0]['actor_user_id']);
        $details = json_decode($rows[0]['details'], true);
        $this->assertContains('steward_banner_message', $details['keys']);
        $this->assertContains('steward_banner_start', $details['keys']);
        $this->assertNotContains('steward_banner_public', $details['keys'], 'unchanged');
        $this->assertSame(23, $details['message_length']);
        $this->assertStringNotContainsString('Maintenance', $rows[0]['details']);

        // Same values again: nothing changed, nothing audited.
        session_set('flash', []);
        $this->save([
            'steward_banner_message' => 'Maintenance at **10pm**', 'steward_banner_level' => 'warning',
            'steward_banner_start' => '2030-01-01 10:00', 'steward_banner_end' => '2030-01-02 10:00',
            'steward_banner_dismissible' => '1', 'steward_banner_login' => '1', 'steward_banner_public' => '0',
        ]);
        $this->assertCount(1, $this->auditRows());
    }

    public function testMessageOver500CharsIsRejected(): void
    {
        $this->save(['steward_banner_message' => str_repeat('é', 500)]);
        $this->assertSame(str_repeat('é', 500), $this->setting('steward_banner_message'), '500 multibyte chars fit');

        session_set('flash', []);
        $this->save(['steward_banner_message' => str_repeat('a', 501), 'steward_banner_level' => 'warning']);
        $this->assertSame(str_repeat('é', 500), $this->setting('steward_banner_message'));
        $this->assertSame('info', $this->setting('steward_banner_level'), 'nothing written');
        $this->assertArrayHasKey('failure', $this->flash());
        $this->assertCount(1, $this->auditRows());
    }

    public function testBadTimesAreRejected(): void
    {
        foreach ([
            ['steward_banner_start' => '2030-01-02 10:00', 'steward_banner_end' => '2030-01-01 10:00'],
            ['steward_banner_start' => '2030-01-01 10:00', 'steward_banner_end' => '2030-01-01 10:00'],
            ['steward_banner_start' => 'next tuesday'],
            ['steward_banner_end' => 'soon'],
        ] as $case) {
            session_set('flash', []);
            $this->save($case + ['steward_banner_message' => 'Changed']);
            $this->assertArrayHasKey('failure', $this->flash(), json_encode($case));
            $this->assertSame('', $this->setting('steward_banner_message'), json_encode($case));
        }
        $this->assertSame([], $this->auditRows());
    }

    public function testUnknownLevelFallsBackToInfo(): void
    {
        $this->save(['steward_banner_level' => '"><script>']);
        $this->assertSame('info', $this->setting('steward_banner_level'));
    }

    public function testSaveNeedsAdminPostAndCsrf(): void
    {
        $this->forbidden(fn () => $this->save([], false), 'no csrf');
        $this->forbidden(fn () => $this->save([], true, 'GET'), 'GET');
        $this->loginAs($this->ana);
        $this->forbidden(fn () => $this->save([]), 'user');
        $this->forbidden(fn () => $this->call('show', [], false, 'GET'), 'user show');
        $this->assertSame('', $this->setting('steward_banner_message'));
    }

    public function testShowRendersFormWithEscapedValues(): void
    {
        $this->set(['steward_banner_message' => '<b>"x"</b>']);
        $html = $this->call('show', [], false, 'GET')->body();
        foreach (['steward_banner_message', 'steward_banner_level', 'steward_banner_start', 'steward_banner_end',
            'steward_banner_dismissible', 'steward_banner_login', 'steward_banner_public'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, $field);
        }
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('maxlength="500"', $html);
        $this->assertStringContainsString('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>"x"</b>', $html);
    }

    public function testFeatureOffAnswersTheGates404(): void
    {
        $this->container['configModel']->save(['steward_banner' => '0']);
        $this->container['memoryCache']->flush();
        foreach (['show' => 'GET', 'save' => 'POST', 'dismiss' => 'POST'] as $action => $method) {
            $response = $this->call($action, [], true, $method);
            $this->assertSame(404, $response->code(), $action);
            $this->assertSame(FeatureGate::NOT_FOUND_BODY, $response->body(), $action);
        }
        $this->assertSame('', $this->setting('steward_banner_message'));
    }

    public function testWiring(): void
    {
        $map = $this->container['applicationAccessMap'];
        $this->assertSame([Role::APP_ADMIN], $map->getRoles('StewardBannerController', 'show'));
        $this->assertSame([Role::APP_ADMIN], $map->getRoles('StewardBannerController', 'save'));
        $this->assertSame($map->getRoleHierarchy(Role::APP_USER), $map->getRoles('StewardBannerController', 'dismiss'));
        $this->assertTrue($this->container['stewardAcl']->verify());

        $gate = new FeatureGate($this->container);
        foreach (['show', 'save', 'dismiss'] as $action) {
            $this->assertSame('banner', $gate->featureFor('StewardBannerController', $action));
        }

        $table = new SwitchActionTable();
        $this->assertSame('blocked', $table->classify('Steward', 'StewardBannerController', 'show'));
        $this->assertSame('blocked', $table->classify('Steward', 'StewardBannerController', 'save'));
        $this->assertSame('write', $table->classify('Steward', 'StewardBannerController', 'dismiss'));

        $sidebar = $this->container['template']->render('Steward:config/sidebar', []);
        $this->assertStringContainsString('StewardBannerController', $sidebar);
    }

    // --- dismissal --------------------------------------------------------------------------------------

    public function testDismissalHidesUntilTheMessageChanges(): void
    {
        $this->set(['steward_banner_message' => 'First']);
        $this->loginAs($this->ana);
        $html = $this->hook('template:layout:top');
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('StewardBannerController', $html);
        $version = $this->banner()->current()['version'];
        $this->assertStringContainsString('value="'.$version.'"', $html);

        $response = $this->dismiss(['version' => $version, 'redirect' => '/board/1']);
        $this->assertSame('/board/1', $response->headers()['Location'] ?? null);
        $this->assertSame($version, $this->dismissed($this->ana));
        $this->assertStringNotContainsString('First', $this->hook('template:layout:top'));

        $this->loginAs(self::ADMIN);
        $this->assertStringContainsString('First', $this->hook('template:layout:top'), 'per user');

        $this->set(['steward_banner_message' => 'Second']);
        $this->loginAs($this->ana);
        $this->assertStringContainsString('Second', $this->hook('template:layout:top'), 'edited message shows again');
    }

    public function testDismissRedirectsOnlyToALocalPath(): void
    {
        $this->set([]);
        $version = $this->banner()->current()['version'];
        foreach (['https://evil.example/', '//evil.example', '/\\evil.example', "/\tx", 'board', ''] as $redirect) {
            $response = $this->dismiss(['version' => $version, 'redirect' => $redirect]);
            $location = $response->headers()['Location'] ?? '';
            $this->assertStringNotContainsString('evil', $location, $redirect);
            $this->assertStringContainsString('DashboardController', $location, $redirect);
        }
    }

    public function testNotDismissibleMeansNoButtonAndDismissRefused(): void
    {
        $this->set(['steward_banner_dismissible' => '0']);
        $this->loginAs($this->ana);
        $html = $this->hook('template:layout:top');
        $this->assertStringContainsString('Maintenance tonight', $html);
        $this->assertStringNotContainsString('<form', $html);

        $version = $this->banner()->current()['version'];
        $this->forbidden(fn () => $this->dismiss(['version' => $version]));
        $this->assertSame('', $this->dismissed($this->ana));
    }

    public function testDismissRefusesStaleVersionGetAndMissingCsrf(): void
    {
        $this->set([]);
        $this->loginAs($this->ana);
        $version = $this->banner()->current()['version'];
        $this->forbidden(fn () => $this->dismiss(['version' => 'deadbeefdeadbeef']), 'stale');
        $this->forbidden(fn () => $this->dismiss(['version' => $version], false), 'csrf');
        $this->forbidden(fn () => $this->dismiss(['version' => $version], true, 'GET'), 'GET');
        $this->assertSame('', $this->dismissed($this->ana));

        $this->set(['steward_banner_start' => (string) (self::NOW + 100)]);
        $this->forbidden(fn () => $this->dismiss(['version' => $this->banner()->version()]), 'not showing');
        $this->assertSame('', $this->dismissed($this->ana));
    }

    // --- where it shows ---------------------------------------------------------------------------------

    public function testSignedInPagesShowItBelowTheSwitchBanner(): void
    {
        $this->set(['steward_banner_message' => 'Hello *team* <b>x</b> [bad](javascript:alert(1))']);
        $html = $this->hook('template:layout:top');
        $this->assertStringContainsString('steward-banner-info', $html);
        $this->assertStringContainsString('Hello <em>team</em> &lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x', $html);
        $this->assertStringNotContainsString('href="javascript', $html);

        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();
        $cookie = $this->getMockBuilder(RememberMeCookie::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['remove', 'write'])->getMock();
        $cookie->method('remove')->willReturn(true);
        unset($this->container['rememberMeCookie']);
        $this->container['rememberMeCookie'] = $cookie;
        $this->assertNull($this->container['stewardSwitching']->start($this->ana));

        $html = $this->hook('template:layout:top');
        $switch = strpos($html, 'You are viewing Kanboard as');
        $announcement = strpos($html, 'Hello <em>team</em>');
        $this->assertNotFalse($switch);
        $this->assertNotFalse($announcement);
        $this->assertLessThan($announcement, $switch, 'switch banner first');
    }

    public function testLoginPageOnlyWhenLoginIsOnAndNeverAForm(): void
    {
        $this->set([]);
        $this->logout();
        $this->assertStringNotContainsString('Maintenance tonight', $this->hook('template:auth:login-form:before'));

        $this->set(['steward_banner_login' => '1']);
        $html = $this->hook('template:auth:login-form:before');
        $this->assertStringContainsString('Maintenance tonight', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testPublicPagesOnlyWhenPublicIsOnAndNeverAForm(): void
    {
        $this->container['helper']->register('layout', \Kanboard\Helper\LayoutHelper::class);
        $this->set([]);
        $this->logout();
        $this->assertStringNotContainsString('Maintenance tonight', (new PublicPage($this->container))->directory());

        $this->set(['steward_banner_public' => '1']);
        $html = (new PublicPage($this->container))->directory();
        $this->assertStringContainsString('Maintenance tonight', $html);
        $this->assertStringContainsString('steward-banner-info', $html);
        $this->assertStringNotContainsString('<form', $html);

        // Signed in, dismissible: still no form on a public page.
        $this->loginAs($this->ana);
        $this->assertStringNotContainsString('<form', (new PublicPage($this->container))->directory());
    }

    public function testNotShownOutsideWindowAnywhere(): void
    {
        $this->set(['steward_banner_login' => '1', 'steward_banner_public' => '1', 'steward_banner_end' => (string) (self::NOW - 1)]);
        $this->assertStringNotContainsString('Maintenance tonight', $this->hook('template:layout:top'));
        $this->logout();
        $this->assertStringNotContainsString('Maintenance tonight', $this->hook('template:auth:login-form:before'));
    }
}
