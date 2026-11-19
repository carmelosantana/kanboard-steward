<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
use Kanboard\Controller\AuthController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Http\Router;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Core\Session\SessionHandler;
use Kanboard\Core\Session\SessionManager;
use Kanboard\Event\AuthSuccessEvent;
use Kanboard\Middleware\AuthenticationMiddleware;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardSwitchController;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchService;
use Kanboard\Plugin\Steward\Plugin;
use Symfony\Contracts\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Collects log lines so expected errors never reach stderr. */
class StewardSwitchEndLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

/**
 * Core's SessionManager without the parts PHPUnit's process can't do (setcookie, a real PHP session): close() fires
 * the same event and empties the session, open() is recorded. The real one runs in the separate-process test below.
 */
class StewardFakeSessionManager extends SessionManager
{
    public array $calls = [];

    public function open()
    {
        $this->calls[] = 'open';
    }

    public function close()
    {
        $this->calls[] = 'close';
        $this->dispatcher->dispatch(new Event(), self::EVENT_DESTROY);
        $_SESSION = [];
    }
}

class SwitchEndTest extends Base
{
    private const ADMIN = 1;

    private int $ana;
    private StewardSwitchEndLogSink $log;
    private StewardFakeSessionManager $sessions;
    private ?string $redirect = null;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();

        $this->log = new StewardSwitchEndLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        // setcookie() can't run under PHPUnit (headers already sent).
        $cookie = $this->getMockBuilder(RememberMeCookie::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['remove', 'write'])
            ->getMock();
        $cookie->method('remove')->willReturn(true);
        unset($this->container['rememberMeCookie']);
        $this->container['rememberMeCookie'] = $cookie;

        $this->sessions = new StewardFakeSessionManager($this->container);
        unset($this->container['sessionManager']);
        $this->container['sessionManager'] = $this->sessions;

        $this->ana = (new UserModel($this->container))->create([
            'username' => 'ana',
            'name' => 'Ana Example',
            'password' => 'secret-password',
            'email' => 'ana@example.org',
        ]);

        $this->request('GET', []);
        $this->loginAs(self::ADMIN);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        parent::tearDown();
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function request(string $method, array $post, array $get = []): void
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, [
            'REQUEST_METHOD' => $method,
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'SwitchEndTest/1.0',
        ], $get ?: ['controller' => 'StewardSwitchController', 'action' => 'back', 'plugin' => 'Steward'], $post, [], []);
    }

    private function captureResponse(): void
    {
        $this->redirect = null;
        $resp = $this->createMock(Response::class);
        $resp->method('redirect')->willReturnCallback(function ($url) { $this->redirect = (string) $url; });
        unset($this->container['response']);
        $this->container['response'] = $resp;
    }

    private function service(): SwitchService
    {
        return $this->container['stewardSwitching'];
    }

    /** Switches the admin to Ana; returns the switch id. */
    private function switchToAna(): string
    {
        $this->assertNull($this->service()->start($this->ana));
        return $this->service()->current()['switch_id'];
    }

    /** Runs StewardSwitchController::back; returns the redirect URL. */
    private function back(array $post, string $method = 'POST'): ?string
    {
        $this->request($method, $post);
        $this->captureResponse();
        (new StewardSwitchController($this->container))->back();
        return $this->redirect;
    }

    private function csrf(): string
    {
        return $this->container['token']->getCSRFToken();
    }

    private function auditRows(?string $event = null): array
    {
        $q = $this->container['db']->table(AuditLog::TABLE)->asc('id');
        if ($event !== null) {
            $q->eq('event', $event);
        }
        return $q->findAll();
    }

    /** Every switch.* row that closes a switch. */
    private function endRows(): array
    {
        return array_values(array_filter($this->auditRows(), fn ($r) => in_array($r['event'], ['switch.end', 'switch.expired', 'switch.revoked'], true)));
    }

    private function profilePath(int $userId): string
    {
        return $this->container['helper']->url->to('UserViewController', 'show', ['user_id' => $userId]);
    }

    private function loginUrl(): string
    {
        return $this->container['helper']->url->to('AuthController', 'login');
    }

    // --- wiring -----------------------------------------------------------------------------------------

    public function testBackIsForAnySignedInUserAndAlwaysAvailable(): void
    {
        $map = $this->container['applicationAccessMap'];
        $this->assertSame($map->getRoleHierarchy(Role::APP_USER), $map->getRoles('StewardSwitchController', 'back'));
        $this->assertFalse($this->container['applicationAuthorization']->isAllowed('StewardSwitchController', 'back', Role::APP_PUBLIC));
        $this->assertSame(FeatureGate::ALWAYS, (new FeatureGate($this->container))->featureFor('StewardSwitchController', 'back'));
        $this->assertTrue($this->container['stewardAcl']->verify());
    }

    /** Leaving a switch must work even when switching was turned off meanwhile. */
    public function testBackPassesTheGateWithSwitchingOff(): void
    {
        $this->container['configModel']->save(['steward_switching' => '0']);
        $this->container['memoryCache']->flush();
        $this->assertTrue((new FeatureGate($this->container))->check('Steward', 'StewardSwitchController', 'back'));
        $this->assertFalse((new FeatureGate($this->container))->check('Steward', 'StewardSwitchController', 'start'));
    }

    /** The controller's own feature check (spec §1) leaves back() alone: it ends the switch with switching off. */
    public function testBackEndsTheSwitchWithSwitchingOff(): void
    {
        $this->switchToAna();
        $this->container['configModel']->save(['steward_switching' => '0']);
        $this->container['memoryCache']->flush();

        $this->assertSame($this->loginUrl(), $this->back(['csrf_token' => $this->csrf()]));
        $this->assertFalse($this->service()->isSwitched());
    }

    // --- switch back ------------------------------------------------------------------------------------

    public function testBackLogsOutAndSendsTheBrowserToTheLoginPage(): void
    {
        $switchId = $this->switchToAna();

        $url = $this->back(['csrf_token' => $this->csrf()]);

        $this->assertSame($this->loginUrl(), $url);
        $this->assertSame(['close', 'open'], $this->sessions->calls);
        $this->assertSame(['redirectAfterLogin' => $this->profilePath($this->ana)], $_SESSION);
        $this->assertFalse($this->container['userSession']->isLogged());
        $this->assertFalse($this->service()->isSwitched());

        $ends = $this->endRows();
        $this->assertCount(1, $ends, 'exactly one end row, although close() fired the logout event');
        $this->assertSame('switch.end', $ends[0]['event']);
        $this->assertSame($switchId, $ends[0]['switch_id']);
        $this->assertSame(self::ADMIN, (int) $ends[0]['actor_user_id']);
        $this->assertSame($this->ana, (int) $ends[0]['subject_user_id']);
        $this->assertSame('end', json_decode($ends[0]['details'], true)['reason']);
    }

    /** Core's redirectAfterLogin() ignores full URLs: the path must be local. */
    public function testRedirectAfterLoginIsARelativePath(): void
    {
        $this->switchToAna();
        $this->back(['csrf_token' => $this->csrf()]);

        $path = $_SESSION['redirectAfterLogin'];
        $this->assertFalse(filter_var($path, FILTER_VALIDATE_URL));
        $this->assertDoesNotMatchRegularExpression('#^[a-z][a-z0-9+.-]*:|^//#i', $path);
        $this->assertSame($this->profilePath($this->ana), $path);
    }

    public function testSigningInAsTheAdminAfterwardsLandsOnTheTargetsProfile(): void
    {
        $this->switchToAna();
        $this->back(['csrf_token' => $this->csrf()]);

        // The login page renders a form with a fresh token from the new session; the admin signs in as usual.
        $this->request('POST', ['csrf_token' => $this->csrf(), 'username' => 'admin', 'password' => 'admin'],
            ['controller' => 'AuthController', 'action' => 'check']);
        $this->captureResponse();
        (new AuthController($this->container))->check();

        $this->assertSame($this->profilePath($this->ana), $this->redirect);
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $this->assertArrayNotHasKey('redirectAfterLogin', $_SESSION);
        $this->assertFalse($this->service()->isSwitched());
        $this->assertCount(1, $this->endRows());
    }

    public function testBackWhenNotSwitchedIsForbidden(): void
    {
        try {
            $this->back(['csrf_token' => $this->csrf()]);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
        }
        $this->assertSame([], $this->sessions->calls);
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $this->assertSame([], $this->endRows());
    }

    public function testGetBackIsRefused(): void
    {
        $this->switchToAna();
        try {
            $this->back(['csrf_token' => $this->csrf()], 'GET');
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
        }
        $this->assertTrue($this->service()->isSwitched());
        $this->assertSame([], $this->sessions->calls);
        $this->assertSame([], $this->endRows());
    }

    public function testBackWithoutCsrfIsRefused(): void
    {
        $this->switchToAna();
        foreach ([[], ['csrf_token' => 'not-a-token']] as $post) {
            try {
                $this->back($post);
                $this->fail('expected AccessForbiddenException');
            } catch (AccessForbiddenException $e) {
            }
        }
        $this->assertTrue($this->service()->isSwitched());
        $this->assertSame($this->ana, $this->container['userSession']->getId());
        $this->assertSame([], $this->endRows());
    }

    /** A Switch back form left open in another tab, posted after the browser logged out. */
    public function testStaleBackAfterLogoutGoesToTheLoginPage(): void
    {
        $this->switchToAna();
        $token = $this->csrf();
        $this->container['sessionManager']->close();

        $this->request('POST', ['csrf_token' => $token]);
        $this->container['router'] = new Router($this->container);
        $this->container['router']->dispatch();
        $this->captureResponse();
        $middleware = new AuthenticationMiddleware($this->container);
        $middleware->execute();

        $this->assertSame($this->loginUrl(), $this->redirect);
        $this->assertCount(1, $this->endRows());
    }

    public function testFailedAuditStillLogsOut(): void
    {
        $this->switchToAna();
        $this->container['db']->getConnection()->exec('DROP TABLE '.AuditLog::TABLE);

        $url = $this->back(['csrf_token' => $this->csrf()]);

        $this->assertSame($this->loginUrl(), $url);
        $this->assertSame(['close', 'open'], $this->sessions->calls);
        $this->assertSame(['redirectAfterLogin' => $this->profilePath($this->ana)], $_SESSION);
        $errors = array_filter($this->log->lines, fn ($l) => $l[0] === 'error' && str_contains($l[1], 'switch.end'));
        $this->assertNotEmpty($errors, 'the lost end row is logged');
    }

    // --- expiry and revocation (called by the guard) ----------------------------------------------------

    /** @return list<array{0:string,1:string}> */
    public static function guardReasons(): array
    {
        return [['expired', 'switch.expired'], ['revoked', 'switch.revoked']];
    }

    /** @dataProvider guardReasons */
    #[DataProvider('guardReasons')]
    public function testGuardReasonsLogOutWithoutAWayBack(string $reason, string $event): void
    {
        $switchId = $this->switchToAna();

        $this->service()->end($reason);

        $this->assertSame(['close', 'open'], $this->sessions->calls);
        $this->assertSame([], $_SESSION, 'no redirect back to the profile, no user');
        $ends = $this->endRows();
        $this->assertCount(1, $ends);
        $this->assertSame($event, $ends[0]['event']);
        $this->assertSame($switchId, $ends[0]['switch_id']);
        $this->assertSame(self::ADMIN, (int) $ends[0]['actor_user_id']);
        $this->assertSame($this->ana, (int) $ends[0]['subject_user_id']);
        $this->assertSame($reason, json_decode($ends[0]['details'], true)['reason']);
    }

    public function testUnknownReasonIsRejected(): void
    {
        $this->switchToAna();
        $before = $_SESSION;
        try {
            $this->service()->end('logout');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
        }
        $this->assertSame($before, $_SESSION);
        $this->assertSame([], $this->sessions->calls);
    }

    public function testEndWhenNotSwitchedDoesNothing(): void
    {
        $before = $_SESSION;
        $this->service()->end('expired');
        $this->assertSame($before, $_SESSION);
        $this->assertSame([], $this->sessions->calls);
        $this->assertSame([], $this->endRows());
    }

    // --- core logout and login --------------------------------------------------------------------------

    public function testCoreLogoutWhileSwitchedAuditsOnce(): void
    {
        $switchId = $this->switchToAna();

        $this->container['sessionManager']->close();
        $this->container['sessionManager']->close();

        $ends = $this->endRows();
        $this->assertCount(1, $ends);
        $this->assertSame('switch.end', $ends[0]['event']);
        $this->assertSame($switchId, $ends[0]['switch_id']);
        $this->assertSame(self::ADMIN, (int) $ends[0]['actor_user_id']);
        $this->assertSame($this->ana, (int) $ends[0]['subject_user_id']);
        $this->assertSame('logout', json_decode($ends[0]['details'], true)['reason']);
    }

    public function testCoreLogoutWhenNotSwitchedWritesNothing(): void
    {
        $this->container['sessionManager']->close();
        $this->assertSame([], $this->endRows());
    }

    public function testRealLoginClearsALeftoverSwitch(): void
    {
        $switchId = $this->switchToAna();
        // Credentials posted straight to AuthController::check while switched: core keeps the session's other keys.
        $this->loginAs(self::ADMIN);
        $this->assertTrue($this->service()->isSwitched());

        $this->container['dispatcher']->dispatch(new AuthSuccessEvent('Database'), 'auth.success');

        $this->assertFalse($this->service()->isSwitched());
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $ends = $this->endRows();
        $this->assertCount(1, $ends);
        $this->assertSame($switchId, $ends[0]['switch_id']);
        $this->assertSame('login', json_decode($ends[0]['details'], true)['reason']);
    }

    /** The sign-in form posted from a session that is still switched: core's own login path clears the marker. */
    public function testCredentialsPostedFromASwitchedSessionClearTheSwitch(): void
    {
        $switchId = $this->switchToAna();
        $this->request('POST', ['csrf_token' => $this->csrf(), 'username' => 'admin', 'password' => 'admin'],
            ['controller' => 'AuthController', 'action' => 'check']);
        $this->captureResponse();

        (new AuthController($this->container))->check();

        $this->assertFalse($this->service()->isSwitched());
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $ends = $this->endRows();
        $this->assertCount(1, $ends);
        $this->assertSame('switch.end', $ends[0]['event']);
        $this->assertSame($switchId, $ends[0]['switch_id']);
        $this->assertSame('login', json_decode($ends[0]['details'], true)['reason']);
    }

    public function testRealLoginWithoutASwitchWritesNoEndRow(): void
    {
        $this->container['dispatcher']->dispatch(new AuthSuccessEvent('Database'), 'auth.success');
        $this->assertSame([], $this->endRows());
    }

    /** Steward never restores admin rights: the only user it writes into a session is the switch target. */
    public function testNoStewardCodeWritesAUserIntoTheSessionExceptTheTarget(): void
    {
        $sources = [];
        $root = dirname(__DIR__);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;
            if (str_ends_with($path, '.php') && ! str_starts_with($path, $root.'/Test/')) {
                $sources[$path] = file_get_contents($path);
            }
        }
        $all = implode("\n", $sources);
        $this->assertSame(0, preg_match_all('/userSession\s*->\s*initialize|userProfile|\$_SESSION\s*\[\s*[\'"]user/', $all));
        $this->assertSame(1, preg_match_all('/session_set\s*\(\s*[\'"]user[\'"]/', $all), 'only writeUser() sets the user');
        $this->assertSame(1, preg_match_all('/->writeUser\s*\(/', $all));
        $this->assertSame(1, preg_match_all('/->writeUser\s*\(\s*\$target\s*\)/', $all));
    }

    // --- real database-backed session -------------------------------------------------------------------

    /**
     * Core's real SessionManager on the database handler: the switched session is gone from the store, the new
     * one holds only the redirect, and a real sign-in lands on the profile.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRealSessionSwitchBackAndSignIn(): void
    {
        $this->assertSame('db', SESSION_HANDLER);
        unset($this->container['sessionManager']);
        $this->container['sessionManager'] = new SessionManager($this->container);
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        // Core 1.2.57's SessionManager::open() sets this, and its handler reads nothing else; 1.2.47 doesn't mind.
        ini_set('session.serialize_handler', 'php_serialize');
        session_set_save_handler(new SessionHandler($this->container['db']), true);
        session_start();
        $this->loginAs(self::ADMIN);
        session_write_close();                 // persist the admin's session, as a finished request would
        session_id(session_id());
        session_start();
        $switchId = $this->switchToAna();
        $switched = session_id();
        session_write_close();
        // The next request: the browser offers the switched id in its cookie.
        $_COOKIE['KB_SID'] = $switched;
        ini_set('session.use_cookies', '1'); // core's open() configures cookie parameters
        session_name('KB_SID');
        session_id($switched);
        session_start();
        $this->assertSame($this->ana, $this->container['userSession']->getId());

        $url = $this->back(['csrf_token' => $this->csrf()]);

        $this->assertSame($this->loginUrl(), $url);
        $fresh = session_id();
        $this->assertNotSame('', $fresh);
        $this->assertNotSame($switched, $fresh);
        $this->assertSame(['redirectAfterLogin' => $this->profilePath($this->ana)], $_SESSION);
        session_write_close();
        $this->assertSame(0, $this->container['db']->table('sessions')->eq('id', $switched)->count(), 'the switched session is gone');
        $data = (string) $this->container['db']->table('sessions')->eq('id', $fresh)->findOneColumn('data');
        $this->assertStringContainsString('redirectAfterLogin', $data);
        $this->assertStringNotContainsString('steward_switch', $data);
        $this->assertStringNotContainsString('"user"', $data);

        session_id($fresh);
        session_start();
        $this->request('POST', ['csrf_token' => $this->csrf(), 'username' => 'admin', 'password' => 'admin'],
            ['controller' => 'AuthController', 'action' => 'check']);
        $this->captureResponse();
        (new AuthController($this->container))->check();

        $this->assertSame($this->profilePath($this->ana), $this->redirect);
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $ends = $this->endRows();
        $this->assertCount(1, $ends);
        $this->assertSame($switchId, $ends[0]['switch_id']);
        session_write_close();
    }
}
