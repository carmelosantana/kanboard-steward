<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';
require_once __DIR__.'/support/BrandingDoubles.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardAuditController;
use Kanboard\Plugin\Steward\Controller\StewardSettingsController;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Plugin;

/** Swallows log lines so expected errors never reach stderr. */
class StewardAuditPageLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

class AuditControllerTest extends Base
{
    private int $alice;
    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['helper']->register('layout', StewardBrandingBodyOnlyLayoutHelper::class);

        $logger = new Logger();
        $logger->setLogger(new StewardAuditPageLogSink());
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $users = new UserModel($this->container);
        $this->alice = $users->create(['username' => 'alice', 'name' => 'Alice Example']);
        $this->bob = $users->create(['username' => 'bob', 'name' => 'Bob Example', 'role' => Role::APP_MANAGER]);
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function row(string $event, int $createdAt, array $extra = []): void
    {
        $this->container['db']->table(AuditLog::TABLE)->insert($extra + [
            'created_at' => $createdAt,
            'event' => $event,
            'details' => '{}',
        ]);
    }

    private function index(array $get): string
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], $get ?: ['_' => '1'], [], [], []);
        $html = '';
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($out) use (&$html) { $html = (string) $out; });
        unset($this->container['response']);
        $this->container['response'] = $resp;
        (new StewardAuditController($this->container))->index();
        return $html;
    }

    private function markers(string $html): array
    {
        preg_match_all('/mark-([A-Za-z0-9]+)/', $html, $m);
        return $m[1];
    }

    public function testIndexIsAdminOnlyAndAlwaysAvailable(): void
    {
        $this->assertSame([Role::APP_ADMIN], $this->container['applicationAccessMap']->getRoles('StewardAuditController', 'index'));
        $this->assertSame(FeatureGate::ALWAYS, (new FeatureGate($this->container))->featureFor('StewardAuditController', 'index'));
        $this->assertTrue($this->container['stewardAcl']->verify());
    }

    public function testNonAdminGetsForbidden(): void
    {
        $this->row('login.success', time(), ['details' => '{"m":"mark-secret"}']);
        $refused = 0;
        foreach ([$this->alice, $this->bob] as $user) {
            $this->loginAs($user);
            try {
                $this->index([]);
                $this->fail('expected AccessForbiddenException');
            } catch (AccessForbiddenException $e) {
                $refused++;
            }
        }
        $this->assertSame(2, $refused);
    }

    public function testNoDeleteRouteExists(): void
    {
        $public = array_values(array_filter(
            get_class_methods(StewardAuditController::class),
            fn ($m) => (new ReflectionMethod(StewardAuditController::class, $m))->getDeclaringClass()->getName() === StewardAuditController::class
                && (new ReflectionMethod(StewardAuditController::class, $m))->isPublic()
        ));
        $this->assertSame(['index'], $public);

        $acl = array_filter(AclCheck::EXPECTED['applicationAccessMap'], fn ($r) => $r[0] === 'StewardAuditController');
        $this->assertSame([['StewardAuditController', ['index'], Role::APP_ADMIN]], array_values($acl));
        foreach (['remove', 'delete', 'clear', 'prune', 'purge'] as $action) {
            $this->assertNull((new FeatureGate($this->container))->featureFor('StewardAuditController', $action), $action);
        }

        $this->row('login.success', time());
        $this->loginAs(1);
        $html = $this->index([]);
        $this->assertDoesNotMatchRegularExpression('/(href|action)="[^"]*(delete|remove)/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*method="post"/i', $html);
    }

    public function testNewestFirstAndFiltersByEventUserAndDate(): void
    {
        $day = strtotime('2026-11-10 12:00:00');
        $this->row('login.success', $day, ['actor_user_id' => $this->alice, 'details' => '{"m":"mark-A"}']);
        $this->row('login.failure', $day + 86400, ['subject_user_id' => $this->alice, 'details' => '{"m":"mark-B"}']);
        $this->row('login.success', $day + 2 * 86400, ['actor_user_id' => $this->bob, 'details' => '{"m":"mark-C"}']);
        $this->row('settings.changed', $day + 3 * 86400, ['actor_user_id' => 1, 'details' => '{"m":"mark-D"}']);
        $this->loginAs(1);

        $this->assertSame(['D', 'C', 'B', 'A'], $this->markers($this->index([])));
        $this->assertSame(['C', 'A'], $this->markers($this->index(['event' => 'login.success'])));
        $this->assertSame(['B', 'A'], $this->markers($this->index(['user_id' => (string) $this->alice])));
        $this->assertSame(['C', 'B'], $this->markers($this->index(['from' => '2026-11-11', 'to' => '2026-11-12'])));
        $this->assertSame(['C'], $this->markers($this->index(['from' => '2026-11-11', 'to' => '2026-11-12', 'event' => 'login.success'])));
        // A malformed date is ignored, never passed to the query.
        $this->assertSame(['D', 'C', 'B', 'A'], $this->markers($this->index(['from' => "2026-11-11' OR 1=1", 'to' => 'nope'])));

        // The filter form keeps the chosen values and offers the events present.
        $html = $this->index(['event' => 'login.failure', 'from' => '2026-11-11']);
        $this->assertMatchesRegularExpression('/<option value="login.failure" selected/', $html);
        $this->assertStringContainsString('<option value="settings.changed"', $html);
        $this->assertStringContainsString('value="2026-11-11"', $html);
    }

    public function testPaginatesFiftyPerPage(): void
    {
        $base = time() - 10000;
        for ($i = 1; $i <= 120; $i++) {
            $this->row('login.success', $base + $i, ['details' => '{"m":"mark-'.$i.'"}']);
        }
        $this->loginAs(1);

        $page1 = $this->markers($this->index([]));
        $this->assertCount(50, $page1);
        $this->assertSame('120', $page1[0]);
        $this->assertSame('71', $page1[49]);

        $page3 = $this->markers($this->index(['page' => '3']));
        $this->assertCount(20, $page3);
        $this->assertSame('20', $page3[0]);
        $this->assertSame('1', $page3[19]);

        // Pager links keep the filters.
        $html = $this->index(['event' => 'login.success']);
        $this->assertMatchesRegularExpression('/href="[^"]*event=login.success[^"]*page=2/', $html);
    }

    public function testEscapesAttackerControlledValues(): void
    {
        $this->row('login.failure', time(), [
            'user_agent' => '"><img src=x onerror=1>',
            'ip' => '<b>ip</b>',
            'details' => json_encode(['username' => '<script>alert(1)</script>']),
        ]);
        $this->loginAs(1);
        $html = $this->index([]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>ip</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=1&gt;', $html);
    }

    /** Spec §2: every write of one switch carries its switch_id; the page shows it short and filters by it. */
    public function testShowsAndFiltersBySwitchId(): void
    {
        $one = str_repeat('a1', 32);
        $two = str_repeat('b2', 32);
        $t = time() - 100;
        $this->row('login.success', $t, ['details' => '{"m":"mark-A"}']);
        $this->row('switch.start', $t + 1, ['switch_id' => $one, 'details' => '{"m":"mark-B"}']);
        $this->row('switch.write', $t + 2, ['switch_id' => $one, 'details' => '{"m":"mark-C"}']);
        $this->row('switch.start', $t + 3, ['switch_id' => $two, 'details' => '{"m":"mark-D"}']);
        $this->row('switch.end', $t + 4, ['switch_id' => $one, 'details' => '{"m":"mark-E"}']);
        $this->loginAs(1);

        $html = $this->index([]);
        $this->assertStringContainsString('<th>'.t('Switch').'</th>', $html);
        $this->assertSame(1, preg_match_all('/<td class="steward-audit-switch"><\/td>/', $html));
        $link = '<a href="/?controller=StewardAuditController&amp;action=index&amp;plugin=Steward&amp;switch_id='.$one.'" title="'.$one.'">a1a1a1a1</a>';
        $this->assertSame(3, substr_count($html, $link));
        $this->assertSame(1, substr_count($html, '>b2b2b2b2</a>'));

        $this->assertSame(['E', 'C', 'B'], $this->markers($this->index(['switch_id' => $one])));
        $this->assertSame(['D'], $this->markers($this->index(['switch_id' => $two])));
        // Exact match only: a prefix finds nothing.
        $this->assertSame([], $this->markers($this->index(['switch_id' => 'a1a1a1a1'])));
        // Kept in the form and in the pager links.
        $this->assertStringContainsString('value="'.$one.'"', $this->index(['switch_id' => $one]));
    }

    public function testSwitchIdIsEscaped(): void
    {
        $evil = '"><b>x</b>&<i>';
        $this->row('plugin.x.write', time(), ['switch_id' => $evil, 'details' => '{"m":"mark-Z"}']);
        $this->loginAs(1);

        $html = $this->index([]);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('title="&quot;&gt;&lt;b&gt;x&lt;/b&gt;&amp;&lt;i&gt;"', $html);
        $this->assertStringContainsString('>&quot;&gt;&lt;b&gt;x&lt;/</a>', $html);

        $filtered = $this->index(['switch_id' => $evil]);
        $this->assertSame(['Z'], $this->markers($filtered));
        $this->assertStringNotContainsString('<b>x</b>', $filtered);
    }

    public function testTruncatedDetailsShownAsEscapedRawText(): void
    {
        $this->row('plugin.x.y', time(), ['details' => '{"note":"<i>cut']);
        $this->loginAs(1);
        $html = $this->index([]);
        $this->assertStringContainsString('{&quot;note&quot;:&quot;&lt;i&gt;cut', $html);
        $this->assertStringNotContainsString('<i>cut', $html);
    }

    public function testShowsUsernamesForActorAndSubject(): void
    {
        $this->row('login.success', time(), ['actor_user_id' => $this->alice, 'subject_user_id' => 999]);
        $this->loginAs(1);
        $html = $this->index([]);
        $this->assertStringContainsString('alice', $html);
        $this->assertStringContainsString('#999', $html);
    }

    public function testSettingsSaveAuditsChangedKeyNamesOnly(): void
    {
        $this->container['configModel']->save(['steward_switch_idle_minutes' => '45']);
        $this->container['memoryCache']->flush();
        $this->loginAs(1);

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues'])->getMock();
        $request->expects($this->once())->method('getValues')->willReturn([
            'steward_banner' => '1',
            'steward_markdown' => '1', // unchanged default
            'steward_switch_idle_minutes' => '45', // unchanged stored value
            'steward_audit_retention_days' => '90',
            'api_token' => 'stolen',
        ]);
        unset($this->container['request']);
        $this->container['request'] = $request;
        unset($this->container['response']);
        $this->container['response'] = $this->createMock(Response::class);

        (new StewardSettingsController($this->container))->save();

        $rows = $this->container['db']->table(AuditLog::TABLE)->eq('event', 'settings.changed')->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['actor_user_id']);
        $this->assertSame(['keys' => ['steward_banner', 'steward_audit_retention_days']], json_decode($rows[0]['details'], true));
        $this->assertStringNotContainsString('90', $rows[0]['details']);
        $this->assertStringNotContainsString('stolen', $rows[0]['details']);
    }

    public function testSettingsSaveWithNothingChangedWritesNoRow(): void
    {
        $this->loginAs(1);
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getValues'])->getMock();
        $request->method('getValues')->willReturn(['steward_markdown' => '1']);
        unset($this->container['request']);
        $this->container['request'] = $request;
        unset($this->container['response']);
        $this->container['response'] = $this->createMock(Response::class);

        (new StewardSettingsController($this->container))->save();

        $this->assertSame(0, $this->container['db']->table(AuditLog::TABLE)->eq('event', 'settings.changed')->count());
    }
}
