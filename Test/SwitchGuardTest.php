<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\Runner;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Http\Router;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Queue\JobHandler;
use Kanboard\Core\Security\Role;
use Kanboard\Core\Session\SessionManager;
use Kanboard\Job\BaseJob;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Model\SwitchGuard;
use Kanboard\Plugin\Steward\Model\SwitchService;
use Kanboard\Plugin\Steward\Plugin;
use SimpleQueue\Job;
use Symfony\Contracts\EventDispatcher\Event;

class StewardGuardLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

/** A request with a JSON body: PHPUnit has no php://input. */
class StewardGuardJsonRequest extends Request
{
    public string $body = '';

    public function getBody()
    {
        return $this->body;
    }
}

final class StewardGuardHalted extends \RuntimeException {}

/** PHPUnit has no PHP session; this guard acts as if there were one, and throws where the real one exits. */
class StewardTestSwitchGuard extends SwitchGuard
{
    protected function sessionActive(): bool
    {
        return true;
    }

    protected function halt(): never
    {
        throw new StewardGuardHalted();
    }
}

/** Core's SessionManager without setcookie or a real PHP session (see SwitchEndTest). */
class StewardGuardFakeSessionManager extends SessionManager
{
    public function open() {}

    public function close()
    {
        $this->dispatcher->dispatch(new Event(), self::EVENT_DESTROY);
        $_SESSION = [];
    }
}

class StewardGuardProbeJob extends BaseJob
{
    public static int $runs = 0;

    public function execute()
    {
        self::$runs++;
    }
}

class SwitchGuardTest extends Base
{
    private const ADMIN = 1;

    private int $ana;
    private int $project;
    private int $task;
    private StewardGuardLogSink $log;
    /** @var array{0:string,1:int}|null */
    private ?array $sent = null;
    private ?string $redirect = null;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();

        $this->log = new StewardGuardLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $cookie = $this->getMockBuilder(RememberMeCookie::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['remove', 'write'])->getMock();
        $cookie->method('remove')->willReturn(true);
        unset($this->container['rememberMeCookie']);
        $this->container['rememberMeCookie'] = $cookie;

        unset($this->container['sessionManager']);
        $this->container['sessionManager'] = new StewardGuardFakeSessionManager($this->container);

        $this->ana = (new UserModel($this->container))->create([
            'username' => 'ana', 'name' => 'Ana Example', 'password' => 'secret-password', 'email' => 'ana@example.org',
        ]);
        $this->project = $this->container['projectModel']->create(['name' => 'Garden']);
        $this->container['projectUserRoleModel']->addUser($this->project, $this->ana, Role::PROJECT_MEMBER);
        $this->task = $this->container['taskCreationModel']->create(['project_id' => $this->project, 'title' => 'Water the plants']);

        $this->route('GET', ['controller' => 'DashboardController', 'action' => 'show']);
        $this->container['userSession']->initialize($this->container['userModel']->getById(self::ADMIN));
        $this->container['memoryCache']->flush();
        $this->assertNull($this->switching()->start($this->ana));
        $this->captureResponse();
    }

    protected function tearDown(): void
    {
        StewardGuardProbeJob::$runs = 0;
        parent::tearDown();
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function switching(): SwitchService
    {
        return $this->container['stewardSwitching'];
    }

    private function route(string $method, array $get, array $post = [], array $server = [], string $json = ''): void
    {
        unset($this->container['request'], $this->container['router']);
        $request = new StewardGuardJsonRequest($this->container, $server + [
            'REQUEST_METHOD' => $method,
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'SwitchGuardTest/1.0',
        ], $get, $post, [], []);
        $request->body = $json;
        $this->container['request'] = $request;
        $this->container['router'] = new Router($this->container);
        $this->container['router']->dispatch();
    }

    private function captureResponse(): void
    {
        $this->sent = null;
        $this->redirect = null;
        $response = $this->getMockBuilder(Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['html', 'redirect', 'text', 'json'])->getMock();
        $response->method('html')->willReturnCallback(function ($body, $status = 200) { $this->sent = [(string) $body, (int) $status]; });
        $response->method('redirect')->willReturnCallback(function ($url) { $this->redirect = (string) $url; });
        unset($this->container['response']);
        $this->container['response'] = $response;
    }

    private function guard(): StewardTestSwitchGuard
    {
        return new StewardTestSwitchGuard($this->container);
    }

    /** Runs the guard; returns true when it stopped the request. */
    private function runGuard(?SwitchGuard $guard = null): bool
    {
        $this->sent = null;
        try {
            ($guard ?? $this->guard())->onBootstrap();
            return false;
        } catch (StewardGuardHalted) {
            return true;
        }
    }

    private function auditRows(string $event): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->eq('event', $event)->asc('id')->findAll();
    }

    private function setLastSeen(int $timestamp): void
    {
        $_SESSION[SwitchService::SESSION_KEY]['last_seen'] = $timestamp;
    }

    private function assertLoggedOut(string $event, string $reason): void
    {
        $this->assertFalse($this->switching()->isSwitched());
        $this->assertFalse($this->container['userSession']->isLogged());
        $rows = $this->auditRows($event);
        $this->assertCount(1, $rows);
        $this->assertSame(self::ADMIN, (int) $rows[0]['actor_user_id']);
        $this->assertSame($reason, json_decode($rows[0]['details'], true)['reason']);
    }

    // --- wiring -----------------------------------------------------------------------------------------

    /** After the first AclCheck/FeatureGate pair, before core's middleware; setUp initialised with switching off. */
    public function testGuardIsRegisteredUnconditionallyAfterTheGate(): void
    {
        $dispatcher = $this->container['dispatcher'];
        $listeners = array_values(array_filter($dispatcher->getListeners('app.bootstrap'),
            fn ($l) => is_array($l) && is_object($l[0]) && str_starts_with(get_class($l[0]), 'Kanboard\\Plugin\\Steward\\')));
        $classes = array_map(fn ($l) => get_class($l[0]), $listeners);
        $this->assertSame([AclCheck::class, FeatureGate::class, SwitchGuard::class, AclCheck::class, FeatureGate::class], $classes);
        $this->assertSame(0, $dispatcher->getListenerPriority('app.bootstrap', $listeners[2]));
    }

    public function testNotSwitchedIsANoOp(): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById(self::ADMIN));
        session_remove(SwitchService::SESSION_KEY);
        $this->route('GET', ['controller' => 'ConfigController', 'action' => 'index']);
        $this->assertFalse($this->runGuard());
        $this->assertSame([], $this->auditRows('switch.action'));
    }

    // --- the per-action table ---------------------------------------------------------------------------

    public function testEveryCoreRowIsAllowedOrRefusedAndWritesAreAudited(): void
    {
        $counts = ['read' => 0, 'write' => 0, 'blocked' => 0];
        foreach (SwitchActionTable::CORE as $pair => $level) {
            [$controller, $action] = explode('::', $pair);
            $this->route('GET', ['controller' => $controller, 'action' => $action, 'task_id' => (string) $this->task, 'project_id' => (string) $this->project]);
            $before = count($this->auditRows('switch.action'));

            $halted = $this->runGuard();

            $after = $this->auditRows('switch.action');
            $this->assertTrue($this->switching()->isSwitched(), $pair);
            if ($level === 'blocked') {
                $this->assertTrue($halted, $pair.' must be refused');
                $this->assertSame(403, $this->sent[1] ?? null, $pair);
                $this->assertStringContainsString('not while switched', strtolower($this->sent[0]), $pair);
                $this->assertCount($before, $after, $pair.' refused requests write no action row');
            } elseif ($level === 'read') {
                $this->assertFalse($halted, $pair.' must be allowed');
                $this->assertCount($before, $after, $pair.' reads are not audited');
            } else {
                $this->assertFalse($halted, $pair.' must be allowed');
                $this->assertCount($before + 1, $after, $pair.' writes are audited');
                $row = end($after);
                $this->assertSame($this->switching()->current()['switch_id'], $row['switch_id'], $pair);
                $this->assertSame(self::ADMIN, (int) $row['actor_user_id'], $pair);
                $this->assertSame($this->ana, (int) $row['subject_user_id'], $pair);
                $this->assertSame([
                    'method' => 'GET', 'controller' => $controller, 'action' => $action,
                    'task_id' => $this->task, 'project_id' => $this->project,
                ], json_decode($row['details'], true), $pair);
            }
            $counts[$level]++;
        }
        $this->assertGreaterThan(50, $counts['blocked']);
        $this->assertGreaterThan(100, $counts['read']);
        $this->assertGreaterThan(100, $counts['write']);
    }

    public function testAnyNonGetRequestIsAudited(): void
    {
        $this->route('POST', ['controller' => 'BoardAjaxController', 'action' => 'reload', 'project_id' => (string) $this->project]);
        $this->assertFalse($this->runGuard());
        $rows = $this->auditRows('switch.action');
        $this->assertCount(1, $rows);
        $this->assertSame('POST', json_decode($rows[0]['details'], true)['method']);
    }

    public function testRefusalPageOffersTheWayBack(): void
    {
        $this->route('GET', ['controller' => 'UserApiAccessController', 'action' => 'show', 'user_id' => (string) $this->ana]);
        $this->assertTrue($this->runGuard());
        $this->assertSame(403, $this->sent[1]);
        $this->assertStringContainsString('Ana Example', $this->sent[0]);
        $this->assertStringContainsString('StewardSwitchController', $this->sent[0]);
        $this->assertStringContainsString('csrf_token', $this->sent[0]);
    }

    /** Like core's BootstrapMiddleware::sendHeaders(): HSTS on HTTPS when ENABLE_HSTS. */
    public function testRefusalSendsHstsOverHttps(): void
    {
        $this->assertTrue(ENABLE_HSTS);
        $this->route('GET', ['controller' => 'UserApiAccessController', 'action' => 'show'], [], ['HTTPS' => 'on']);
        $this->assertTrue($this->runGuard());
        $headers = (new ReflectionProperty(Response::class, 'httpHeaders'))->getValue($this->container['response']);
        $this->assertSame('max-age=31536000', $headers['Strict-Transport-Security'] ?? null);
    }

    /** The board's drag and drop sends task_id in a JSON body; project_id comes from the query. */
    public function testBoardMoveRecordsTheTaskFromTheJsonBody(): void
    {
        $this->route('POST', ['controller' => 'BoardAjaxController', 'action' => 'save', 'project_id' => (string) $this->project], [], [],
            json_encode(['task_id' => $this->task, 'src_column_id' => 1, 'dst_column_id' => 2, 'swimlane_id' => 1, 'position' => 1]));
        $this->assertFalse($this->runGuard());
        $details = json_decode($this->auditRows('switch.action')[0]['details'], true);
        $this->assertSame($this->task, $details['task_id']);
        $this->assertSame($this->project, $details['project_id']);
    }

    public function testQueryIdsWinOverTheJsonBody(): void
    {
        $this->route('GET', ['controller' => 'TaskReorderController', 'action' => 'reorderColumn', 'project_id' => (string) $this->project], [], [],
            json_encode(['task_id' => '7', 'project_id' => 999]));
        $this->assertFalse($this->runGuard());
        $details = json_decode($this->auditRows('switch.action')[0]['details'], true);
        $this->assertSame(7, $details['task_id']);
        $this->assertSame($this->project, $details['project_id']);
    }

    public function testJsonBodyIsReadOnlyForTheBoardActions(): void
    {
        $this->route('POST', ['controller' => 'TaskModificationController', 'action' => 'update'], [], [], json_encode(['task_id' => $this->task]));
        $this->assertFalse($this->runGuard());
        $details = json_decode($this->auditRows('switch.action')[0]['details'], true);
        $this->assertNull($details['task_id']);
    }

    public function testUnknownCoreActionIsRefused(): void
    {
        $this->route('GET', ['controller' => 'BoardViewController', 'action' => 'somethingNew']);
        $this->assertTrue($this->runGuard());
        $this->assertSame(403, $this->sent[1]);
    }

    public function testControllerNamesAreMatchedWhateverTheirCase(): void
    {
        $this->route('GET', ['controller' => 'userapiaccesscontroller', 'action' => 'SHOW']);
        $this->assertTrue($this->runGuard());
    }

    // --- other plugins ----------------------------------------------------------------------------------

    public function testUnknownPluginActionIsRefusedEvenOnGet(): void
    {
        $this->route('GET', ['plugin' => 'Alpha', 'controller' => 'ThingController', 'action' => 'show']);
        $this->assertTrue($this->runGuard());
        $this->assertSame(403, $this->sent[1]);
    }

    public function testRegistrationCoversOnlyThatPlugin(): void
    {
        $this->switching()->allow('Alpha', ['ThingController::show' => 'read', 'ThingController::save' => 'write']);

        $this->route('GET', ['plugin' => 'Alpha', 'controller' => 'ThingController', 'action' => 'show']);
        $this->assertFalse($this->runGuard());
        $this->assertSame([], $this->auditRows('switch.action'));

        $this->route('POST', ['plugin' => 'Alpha', 'controller' => 'ThingController', 'action' => 'save']);
        $this->assertFalse($this->runGuard());
        $rows = $this->auditRows('switch.action');
        $this->assertCount(1, $rows);
        $this->assertSame('Alpha', json_decode($rows[0]['details'], true)['plugin']);

        $this->route('GET', ['plugin' => 'Beta', 'controller' => 'ThingController', 'action' => 'show']);
        $this->assertTrue($this->runGuard(), "Alpha's opt-in never covers Beta's same-named controller");
    }

    /** Plugin names are case-insensitive (like the router): a registration merges with the suite's rows. */
    public function testAllowMergesWithSuiteRowsWhateverTheCase(): void
    {
        $this->switching()->allow('pulse', ['PulseController::extra' => 'read']);
        $this->switching()->allow('PULSE', ['PulseController::more' => 'read']);
        foreach (['index', 'extra', 'more'] as $action) {
            $this->route('GET', ['plugin' => 'Pulse', 'controller' => 'PulseController', 'action' => $action]);
            $this->assertFalse($this->runGuard(), $action);
        }
    }

    public function testAllowRejectsBadRegistrations(): void
    {
        foreach ([['Steward', ['StewardSettingsController::show' => 'read']], ['', ['A::b' => 'read']],
            ['Alpha', ['ThingController::show' => 'blocked']], ['Alpha', ['ThingController' => 'read']]] as [$plugin, $actions]) {
            try {
                $this->switching()->allow($plugin, $actions);
                $this->fail('expected InvalidArgumentException for '.json_encode([$plugin, $actions]));
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testSuiteViewPagesAreAllowedWhenInstalled(): void
    {
        $this->assertTrue(class_exists('Kanboard\Plugin\Pulse\Controller\PulseController'));
        $this->route('GET', ['plugin' => 'Pulse', 'controller' => 'PulseController', 'action' => 'index']);
        $this->assertFalse($this->runGuard());
        $this->route('GET', ['plugin' => 'Presence', 'controller' => 'HeartbeatController', 'action' => 'beat']);
        $this->assertTrue($this->runGuard(), 'a heartbeat would show the target online');
    }

    // --- Steward's own controllers ----------------------------------------------------------------------

    public function testSwitchBackIsAllowedEvenWhenTheAuditFails(): void
    {
        $this->container['db']->getConnection()->exec('DROP TABLE '.AuditLog::TABLE);
        foreach ([['plugin' => 'Steward', 'controller' => 'StewardSwitchController', 'action' => 'back'],
            ['controller' => 'AuthController', 'action' => 'logout']] as $get) {
            $this->route('POST', $get);
            $this->assertFalse($this->runGuard(), 'leaving always works: '.$get['controller']);
        }
    }

    public function testStewardStartAndSettingsAreRefused(): void
    {
        foreach (['StewardSwitchController' => 'start', 'StewardSettingsController' => 'show', 'StewardAuditController' => 'index'] as $controller => $action) {
            $this->route('GET', ['plugin' => 'Steward', 'controller' => $controller, 'action' => $action]);
            $this->assertTrue($this->runGuard(), $controller);
        }
    }

    // --- audit failure ----------------------------------------------------------------------------------

    /** The full Runner: the controller never runs when the action row can't be written. */
    public function testAuditFailureOnAWriteAnswers503AndTheTaskIsUnchanged(): void
    {
        $this->container['dispatcher']->addListener('app.bootstrap', [$this->guard(), 'onBootstrap']);
        $get = ['controller' => 'TaskModificationController', 'action' => 'assignToMe', 'task_id' => (string) $this->task,
            'csrf_token' => $this->container['token']->getReusableCSRFToken()];
        $this->container['db']->getConnection()->exec('ALTER TABLE '.AuditLog::TABLE.' RENAME TO steward_audit_away');

        $this->route('GET', $get);
        try {
            (new Runner($this->container))->execute();
            $this->fail('expected the guard to stop the request');
        } catch (StewardGuardHalted) {
        }
        $this->assertSame(503, $this->sent[1]);
        $this->assertSame(0, (int) $this->container['taskFinderModel']->getById($this->task)['owner_id']);

        // Control: with the audit table back, the same request assigns the task.
        $this->container['db']->getConnection()->exec('ALTER TABLE steward_audit_away RENAME TO '.AuditLog::TABLE);
        $this->route('GET', $get);
        (new Runner($this->container))->execute();
        $this->assertSame($this->ana, (int) $this->container['taskFinderModel']->getById($this->task)['owner_id']);
        $this->assertCount(1, $this->auditRows('switch.action'));
    }

    // --- re-checks --------------------------------------------------------------------------------------

    /** @return list<array{0:array<string,mixed>}> */
    public static function adminChanges(): array
    {
        return [[['role' => Role::APP_USER]], [['role' => Role::APP_MANAGER]], [['is_active' => 0]], [[]]];
    }

    /** @dataProvider adminChanges */
    public function testChangedAdminIsLoggedOut(array $change): void
    {
        if ($change === []) {
            $this->container['db']->table(UserModel::TABLE)->eq('id', self::ADMIN)->remove();
        } else {
            $this->container['db']->table(UserModel::TABLE)->eq('id', self::ADMIN)->update($change);
        }
        $this->route('GET', ['controller' => 'BoardViewController', 'action' => 'show', 'project_id' => (string) $this->project]);
        $this->assertFalse($this->runGuard());
        $this->assertLoggedOut('switch.revoked', 'not_admin');
        $this->assertSame([], $this->auditRows('switch.action'));
    }

    /** @return list<array{0:array<string,mixed>}> */
    public static function targetChanges(): array
    {
        return [[['role' => Role::APP_MANAGER]], [['is_active' => 0]], [[]]];
    }

    /**
     * Core's checkCurrentSession would wipe the session silently; the guard ends the switch first.
     *
     * @dataProvider targetChanges
     */
    public function testChangedTargetEndsTheSwitch(array $change): void
    {
        if ($change === []) {
            $this->container['db']->table(UserModel::TABLE)->eq('id', $this->ana)->remove();
        } else {
            $this->container['db']->table(UserModel::TABLE)->eq('id', $this->ana)->update($change);
        }
        $this->route('GET', ['controller' => 'BoardViewController', 'action' => 'show', 'project_id' => (string) $this->project]);
        $this->assertFalse($this->runGuard());
        $this->assertLoggedOut('switch.revoked', 'target_changed');
    }

    public function testIdleSixtyOneMinutesExpires(): void
    {
        $this->setLastSeen(time() - 61 * 60);
        $this->route('POST', ['controller' => 'TaskModificationController', 'action' => 'update']);
        $this->assertFalse($this->runGuard());
        $this->assertLoggedOut('switch.expired', 'expired');
        $this->assertSame([], $this->auditRows('switch.action'), 'the write never reached the table');
    }

    public function testIdleFiftyNineMinutesStays(): void
    {
        $this->setLastSeen(time() - 59 * 60);
        $this->route('GET', ['controller' => 'DashboardController', 'action' => 'show']);
        $this->assertFalse($this->runGuard());
        $this->assertTrue($this->switching()->isSwitched());
    }

    public function testIdleTimeoutFollowsTheSetting(): void
    {
        $this->container['configModel']->save(['steward_switch_idle_minutes' => '10']);
        $this->container['memoryCache']->flush();
        $this->setLastSeen(time() - 11 * 60);
        $this->route('GET', ['controller' => 'DashboardController', 'action' => 'show']);
        $this->runGuard();
        $this->assertLoggedOut('switch.expired', 'expired');
    }

    public function testAjaxRequestsDoNotMoveLastSeen(): void
    {
        $then = time() - 30 * 60;
        $this->setLastSeen($then);
        $this->route('GET', ['controller' => 'BoardAjaxController', 'action' => 'check', 'project_id' => (string) $this->project],
            [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $this->assertFalse($this->runGuard());
        $this->assertSame($then, $this->switching()->current()['last_seen']);

        $this->route('GET', ['controller' => 'BoardViewController', 'action' => 'show', 'project_id' => (string) $this->project]);
        $this->assertFalse($this->runGuard());
        $this->assertGreaterThanOrEqual(time() - 1, $this->switching()->current()['last_seen']);
    }

    // --- switching turned off ---------------------------------------------------------------------------

    public function testTurningSwitchingOffEndsTheSwitchOnTheNextRequest(): void
    {
        $this->container['configModel']->save(['steward_switching' => '0']);
        $this->container['memoryCache']->flush();
        $this->route('GET', ['controller' => 'DashboardController', 'action' => 'show']);
        $this->assertFalse($this->runGuard());
        $this->assertLoggedOut('switch.revoked', 'disabled');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testKillSwitchEndsTheSwitchOnTheNextRequest(): void
    {
        define('STEWARD_DISABLE_SWITCHING', true);
        $this->route('GET', ['controller' => 'DashboardController', 'action' => 'show']);
        $this->assertFalse($this->runGuard());
        $this->assertLoggedOut('switch.revoked', 'disabled');
    }

    // --- not a browser request --------------------------------------------------------------------------

    /** JSON-RPC never opens a PHP session: the real guard leaves even a stale marker alone. */
    public function testJsonRpcCallIsUntouched(): void
    {
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        $this->setLastSeen(time() - 24 * 3600);
        $this->container['configModel']->save(['steward_switching' => '0']);
        $this->container['memoryCache']->flush();
        $this->route('POST', []);

        (new \Kanboard\Api\Middleware\AuthenticationMiddleware($this->container))->execute('admin', 'admin', 'getMe');

        $this->assertSame([], $this->auditRows('switch.revoked'));
        $this->assertSame([], $this->auditRows('switch.expired'));
        $this->assertNull($this->sent);
    }

    /** A queue job flushes the session before app.bootstrap: nothing to guard, even with a session open. */
    public function testQueueJobIsUntouched(): void
    {
        $this->container['dispatcher']->addListener('app.bootstrap', [$this->guard(), 'onBootstrap']);
        $this->setLastSeen(time() - 24 * 3600);
        $this->route('GET', ['controller' => 'ConfigController', 'action' => 'index']);

        JobHandler::getInstance($this->container)->executeJob(new Job([
            'class' => StewardGuardProbeJob::class, 'params' => [], 'user_id' => $this->ana,
        ]));

        $this->assertSame(1, StewardGuardProbeJob::$runs);
        $this->assertNull($this->sent);
        $this->assertSame([], $this->auditRows('switch.expired'));
        $this->assertSame([], $this->auditRows('switch.action'));
    }
}
