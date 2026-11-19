<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Core\Session\SessionHandler;
use Kanboard\Model\RememberMeSessionModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardSwitchController;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\SwitchPolicy;
use Kanboard\Plugin\Steward\Model\SwitchService;
use Kanboard\Plugin\Steward\Plugin;

/** Collects log lines so expected errors never reach stderr. */
class StewardSwitchStartLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

/** A remember-me model whose remove() reports success but deletes nothing. */
class StewardStuckRememberMeModel extends RememberMeSessionModel
{
    public function remove($session_id)
    {
        return true;
    }
}

class SwitchStartTest extends Base
{
    private const ADMIN = 1;
    private const IP = '203.0.113.9';
    private const UA = 'SwitchStartTest/1.0';

    private int $ana;
    private StewardSwitchStartLogSink $log;
    /** @var \PHPUnit\Framework\MockObject\MockObject&RememberMeCookie */
    private $cookie;
    private array $cookies = [];
    private ?string $redirect = null;
    private bool $authSuccessDispatched = false;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_switching' => '1']);
        $this->container['memoryCache']->flush();

        $this->log = new StewardSwitchStartLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        // setcookie() can't run under PHPUnit (headers already sent): record remove() instead; read() stays real.
        $this->cookie = $this->getMockBuilder(RememberMeCookie::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['remove', 'write'])
            ->getMock();
        $this->cookie->method('remove')->willReturn(true);
        unset($this->container['rememberMeCookie']);
        $this->container['rememberMeCookie'] = $this->cookie;

        $this->container['dispatcher']->addListener('auth.success', function () {
            $this->authSuccessDispatched = true;
        });

        $this->ana = (new UserModel($this->container))->create([
            'username' => 'ana',
            'name' => 'Ana Example',
            'password' => 'secret-password',
            'email' => 'ana@example.org',
        ]);
        $this->container['db']->table(UserModel::TABLE)->eq('id', $this->ana)->update(['twofactor_secret' => 'TOTPSECRET']);

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

    private function request(string $method, array $post): void
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, [
            'REQUEST_METHOD' => $method,
            'REMOTE_ADDR' => self::IP,
            'HTTP_USER_AGENT' => self::UA,
        ], ['controller' => 'StewardSwitchController', 'action' => 'start', 'plugin' => 'Steward'], $post, [], $this->cookies);
    }

    private function service(): SwitchService
    {
        return $this->container['stewardSwitching'];
    }

    /** Runs the controller action; returns the redirect URL. */
    private function post(array $post, string $method = 'POST'): ?string
    {
        $this->request($method, $post);
        $this->redirect = null;
        $resp = $this->createMock(Response::class);
        $resp->method('redirect')->willReturnCallback(function ($url) { $this->redirect = (string) $url; });
        unset($this->container['response']);
        $this->container['response'] = $resp;
        (new StewardSwitchController($this->container))->start();
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

    private function rowCount(string $table, array $eq = []): int
    {
        $q = $this->container['db']->table($table);
        foreach ($eq as $col => $val) {
            $q->eq($col, $val);
        }
        return $q->count();
    }

    /** Gives this browser a remember-me login for the admin; returns its row id. */
    private function rememberAdmin(): int
    {
        $s = $this->container['rememberMeSessionModel']->create(self::ADMIN, self::IP, self::UA);
        $this->cookies = [RememberMeCookie::COOKIE_NAME => $s['token'].'|'.$s['sequence']];
        return (int) $this->container['db']->table(RememberMeSessionModel::TABLE)->eq('token', $s['token'])->findOneColumn('id');
    }

    // --- wiring -----------------------------------------------------------------------------------------

    public function testStartIsAdminOnlyAndBehindTheSwitchingFeature(): void
    {
        $this->assertSame([Role::APP_ADMIN], $this->container['applicationAccessMap']->getRoles('StewardSwitchController', 'start'));
        $this->assertSame('switching', (new FeatureGate($this->container))->featureFor('StewardSwitchController', 'start'));
        $this->assertTrue($this->container['stewardAcl']->verify());
        $this->assertInstanceOf(SwitchService::class, $this->container['stewardSwitching']);
    }

    public function testNotSwitchedByDefault(): void
    {
        $this->assertFalse($this->service()->isSwitched());
        $this->assertNull($this->service()->current());
    }

    // --- request checks ---------------------------------------------------------------------------------

    public function testGetIsRefused(): void
    {
        try {
            $this->post(['csrf_token' => $this->csrf(), 'user_id' => (string) $this->ana], 'GET');
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
        }
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $this->assertSame([], $this->auditRows('switch.start'));
    }

    public function testMissingCsrfIsRefused(): void
    {
        try {
            $this->post(['user_id' => (string) $this->ana]);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
        }
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $this->assertSame([], $this->auditRows('switch.start'));
    }

    public function testBadCsrfIsRefused(): void
    {
        $this->csrf();
        try {
            $this->post(['csrf_token' => 'not-a-token', 'user_id' => (string) $this->ana]);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
        }
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $this->assertFalse($this->service()->isSwitched());
    }

    // --- success ----------------------------------------------------------------------------------------

    public function testSuccessfulSwitch(): void
    {
        $lastLogins = $this->rowCount('last_logins');
        $_SESSION['hasRememberMe'] = true;
        $_SESSION['leftover'] = 'x';

        $url = $this->post(['csrf_token' => $this->csrf(), 'user_id' => (string) $this->ana]);

        $this->assertSame($this->container['helper']->url->to('DashboardController', 'show'), $url);
        $this->assertSame($this->ana, $this->container['userSession']->getId());
        $this->assertSame('ana', $this->container['userSession']->getUsername());
        $this->assertTrue($this->container['userSession']->isPostAuthenticationValidated());
        $this->assertArrayNotHasKey('hasRememberMe', $_SESSION);
        $this->assertArrayNotHasKey('leftover', $_SESSION);
        $this->assertArrayNotHasKey('csrf', $_SESSION);
        $this->assertSame(['user', 'postAuthenticationValidated', 'steward_switch'], array_keys($_SESSION));

        $switch = $_SESSION['steward_switch'];
        $this->assertSame(['switch_id', 'admin_id', 'admin_username', 'started_at', 'last_seen'], array_keys($switch));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $switch['switch_id']);
        $this->assertSame(self::ADMIN, $switch['admin_id']);
        $this->assertSame('admin', $switch['admin_username']);
        $this->assertEqualsWithDelta(time(), $switch['started_at'], 5);
        $this->assertSame($switch['started_at'], $switch['last_seen']);

        $this->assertTrue($this->service()->isSwitched());
        $this->assertSame($switch, $this->service()->current());

        $this->assertSame($lastLogins, $this->rowCount('last_logins'));
        $this->assertSame(0, $this->rowCount(RememberMeSessionModel::TABLE, ['user_id' => $this->ana]));
        $this->assertFalse($this->authSuccessDispatched);

        $starts = $this->auditRows('switch.start');
        $this->assertCount(1, $starts);
        $this->assertSame(self::ADMIN, (int) $starts[0]['actor_user_id']);
        $this->assertSame($this->ana, (int) $starts[0]['subject_user_id']);
        $this->assertSame($switch['switch_id'], $starts[0]['switch_id']);
        $this->assertSame([], $this->auditRows('switch.refused'));
    }

    public function testSuccessRevokesThisBrowsersRememberMeOnly(): void
    {
        $here = $this->rememberAdmin();
        $elsewhere = $this->container['rememberMeSessionModel']->create(self::ADMIN, '198.51.100.1', 'Other browser');
        $this->cookie->expects($this->once())->method('remove');

        $this->assertNotNull($this->post(['csrf_token' => $this->csrf(), 'user_id' => (string) $this->ana]));

        $this->assertSame($this->ana, $this->container['userSession']->getId());
        $this->assertSame(0, $this->rowCount(RememberMeSessionModel::TABLE, ['id' => $here]));
        $this->assertSame(1, $this->rowCount(RememberMeSessionModel::TABLE, ['token' => $elsewhere['token']]));
        $this->assertSame(0, $this->rowCount(RememberMeSessionModel::TABLE, ['user_id' => $this->ana]));
    }

    public function testNoRememberMeCookieMeansNothingToRevoke(): void
    {
        $this->cookie->expects($this->never())->method('remove');
        $this->assertNull($this->service()->start($this->ana));
        $this->assertTrue($this->service()->isSwitched());
    }

    public function testLdapAdminCanSwitch(): void
    {
        $this->container['db']->table(UserModel::TABLE)->eq('id', self::ADMIN)->update(['is_ldap_user' => 1]);
        $this->loginAs(self::ADMIN);
        $this->assertTrue($_SESSION['user']['is_ldap_user']);

        $this->assertNull($this->service()->start($this->ana));
        $this->assertSame($this->ana, $this->container['userSession']->getId());
    }

    public function testTwoFactorAdminMustHaveValidatedThisSession(): void
    {
        $this->container['db']->table(UserModel::TABLE)->eq('id', self::ADMIN)->update(['twofactor_activated' => 1, 'twofactor_secret' => 'X']);
        $this->loginAs(self::ADMIN);

        $this->assertSame('2fa_pending', $this->service()->start($this->ana));
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());

        $this->container['userSession']->setPostAuthenticationAsValidated();
        $this->assertNull($this->service()->start($this->ana));
        $this->assertSame($this->ana, $this->container['userSession']->getId());
    }

    /** The actor is the database row, not the session copy: a demoted admin's stale session can't switch. */
    public function testActorIsReloadedFromTheDatabase(): void
    {
        $this->container['db']->table(UserModel::TABLE)->eq('id', self::ADMIN)->update(['role' => Role::APP_USER]);
        $this->assertTrue($this->container['userSession']->isAdmin());

        $this->assertSame('not_admin', $this->service()->start($this->ana));
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
    }

    public function testAlreadySwitchedIsNested(): void
    {
        $_SESSION['steward_switch'] = ['switch_id' => str_repeat('a', 64), 'admin_id' => 1, 'admin_username' => 'admin', 'started_at' => 1, 'last_seen' => 1];
        $this->assertSame('nested', $this->service()->start($this->ana));
    }

    // --- writeUser --------------------------------------------------------------------------------------

    public function testWriteUserMatchesCoreInitialize(): void
    {
        $this->container['db']->table(UserModel::TABLE)->eq('id', $this->ana)->update(['is_ldap_user' => 1, 'twofactor_activated' => 1]);
        $row = $this->container['userModel']->getById($this->ana);
        $this->assertNotEmpty($row['password']);
        $this->assertNotEmpty($row['twofactor_secret']);

        $_SESSION = [];
        $this->container['userSession']->initialize($row);
        $core = $_SESSION;

        $_SESSION = [];
        $this->service()->writeUser($row);

        $this->assertSame($core, $_SESSION);
        foreach (['password', 'is_admin', 'is_project_admin', 'twofactor_secret'] as $secret) {
            $this->assertArrayNotHasKey($secret, $_SESSION['user']);
        }
        $this->assertSame($this->ana, $_SESSION['user']['id']);
        $this->assertTrue($_SESSION['user']['is_ldap_user']);
        $this->assertTrue($_SESSION['user']['twofactor_activated']);
    }

    // --- refusals ---------------------------------------------------------------------------------------

    public function testPolicyRefusalFlashesUniformMessageAndAuditsTheReason(): void
    {
        $other = (new UserModel($this->container))->create(['username' => 'boss', 'role' => Role::APP_ADMIN]);

        $url = $this->post(['csrf_token' => $this->csrf(), 'user_id' => (string) $other]);

        $this->assertSame($this->container['helper']->url->to('UserViewController', 'show', ['user_id' => $other]), $url);
        $this->assertSame(t(SwitchPolicy::UNIFORM_MESSAGE), session_get('flash')['failure'] ?? null);
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $this->assertSame([], $this->auditRows('switch.start'));
        $refused = $this->auditRows('switch.refused');
        $this->assertCount(1, $refused);
        $this->assertSame(self::ADMIN, (int) $refused[0]['actor_user_id']);
        $this->assertSame($other, (int) $refused[0]['subject_user_id']);
        $this->assertNull($refused[0]['switch_id']);
        $this->assertSame('target_admin', json_decode($refused[0]['details'], true)['reason']);
    }

    /** @return array<string,array{0:string}> */
    public static function authorisationReasons(): array
    {
        return ['disabled' => ['disabled'], 'acl_conflict' => ['acl_conflict'], 'not_admin' => ['not_admin'],
            'nested' => ['nested'], '2fa_pending' => ['2fa_pending']];
    }

    /**
     * Refused before the target lookup: the redirect must not tell whether the target exists.
     *
     * @dataProvider authorisationReasons
     */
    public function testAuthorisationRefusalsGoToTheUserListEvenForAnExistingTarget(string $reason): void
    {
        match ($reason) {
            'disabled' => $this->container['configModel']->save(['steward_switching' => '0']),
            'acl_conflict' => $this->container[AclCheck::FLAG] = true,
            'not_admin' => $this->container['db']->table(UserModel::TABLE)->eq('id', self::ADMIN)->update(['role' => Role::APP_USER]),
            'nested' => $_SESSION['steward_switch'] = ['switch_id' => str_repeat('a', 64), 'admin_id' => 1, 'admin_username' => 'admin', 'started_at' => 1, 'last_seen' => 1],
            '2fa_pending' => $this->container['db']->table(UserModel::TABLE)->eq('id', self::ADMIN)->update(['twofactor_activated' => 1, 'twofactor_secret' => 'X']),
        };
        $this->container['memoryCache']->flush();

        $existing = $this->post(['csrf_token' => $this->csrf(), 'user_id' => (string) $this->ana]);
        $missing = $this->post(['csrf_token' => $this->csrf(), 'user_id' => '999']);

        $list = $this->container['helper']->url->to('UserListController', 'show');
        $this->assertSame($list, $existing);
        $this->assertSame($list, $missing);
        $reasons = array_map(fn ($r) => json_decode($r['details'], true)['reason'], $this->auditRows('switch.refused'));
        $this->assertSame([$reason, $reason], $reasons);
    }

    public function testInactiveTargetGoesBackToTheProfile(): void
    {
        $this->container['userModel']->disable($this->ana);

        $url = $this->post(['csrf_token' => $this->csrf(), 'user_id' => (string) $this->ana]);

        $this->assertSame($this->container['helper']->url->to('UserViewController', 'show', ['user_id' => $this->ana]), $url);
        $this->assertSame('inactive', json_decode($this->auditRows('switch.refused')[0]['details'], true)['reason']);
    }

    /** The target's own 2FA is not asked for: the admin is viewing as them, not signing in as them. */
    public function testTargetWithTwoFactorIsNotPrompted(): void
    {
        $this->container['db']->table(UserModel::TABLE)->eq('id', $this->ana)->update(['twofactor_activated' => 1]);

        $this->assertNull($this->service()->start($this->ana));

        $this->assertSame($this->ana, $this->container['userSession']->getId());
        $this->assertTrue($this->container['userSession']->hasPostAuthentication());
        $this->assertTrue($this->container['userSession']->isPostAuthenticationValidated());
    }

    /** A remember-me cookie without its `token|sequence` shape can't be revoked: fail closed, no PHP warning. */
    public function testMalformedRememberMeCookieRefuses(): void
    {
        $this->cookies = [RememberMeCookie::COOKIE_NAME => 'no-separator-here'];
        $this->request('POST', []);
        $this->cookie->expects($this->never())->method('remove');
        $before = $_SESSION;

        $this->assertSame('rememberme_failed', $this->service()->start($this->ana));

        $this->assertSame($before, $_SESSION);
        $this->assertSame('rememberme_failed', json_decode($this->auditRows('switch.refused')[0]['details'], true)['reason']);
        $errors = array_filter($this->log->lines, fn ($l) => $l[0] === 'error' && str_contains($l[1], 'remember-me'));
        $this->assertNotEmpty($errors);
    }

    public function testUnknownTargetRedirectsToTheUserList(): void
    {
        $url = $this->post(['csrf_token' => $this->csrf(), 'user_id' => '999']);

        $this->assertSame($this->container['helper']->url->to('UserListController', 'show'), $url);
        $this->assertSame(t(SwitchPolicy::UNIFORM_MESSAGE), session_get('flash')['failure'] ?? null);
        $this->assertSame('not_found', json_decode($this->auditRows('switch.refused')[0]['details'], true)['reason']);
    }

    public function testNonNumericUserIdIsNotFound(): void
    {
        $url = $this->post(['csrf_token' => $this->csrf(), 'user_id' => '2 OR 1=1']);

        $this->assertSame($this->container['helper']->url->to('UserListController', 'show'), $url);
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
        $this->assertSame('not_found', json_decode($this->auditRows('switch.refused')[0]['details'], true)['reason']);
    }

    public function testAuditFailureRefusesAndLeavesTheSessionAlone(): void
    {
        $here = $this->rememberAdmin();
        $this->request('POST', []);
        $this->cookie->expects($this->never())->method('remove');
        $this->container['db']->getConnection()->exec('DROP TABLE '.AuditLog::TABLE);
        $before = $_SESSION;

        $this->assertSame('audit_failed', $this->service()->start($this->ana));

        $this->assertSame($before, $_SESSION);
        $this->assertSame(1, $this->rowCount(RememberMeSessionModel::TABLE, ['id' => $here]));
        $errors = array_filter($this->log->lines, fn ($l) => $l[0] === 'error' && str_contains($l[1], 'audit_failed'));
        $this->assertNotEmpty($errors, 'the refusal is logged when the audit store is down');
    }

    public function testAuditFailureThroughTheControllerShowsTheUniformMessage(): void
    {
        $this->container['db']->getConnection()->exec('DROP TABLE '.AuditLog::TABLE);

        $url = $this->post(['csrf_token' => $this->csrf(), 'user_id' => (string) $this->ana]);

        $this->assertSame($this->container['helper']->url->to('UserViewController', 'show', ['user_id' => $this->ana]), $url);
        $this->assertSame(t(SwitchPolicy::UNIFORM_MESSAGE), session_get('flash')['failure'] ?? null);
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());
    }

    public function testRememberMeRowThatSurvivesRefusesAndLeavesTheSessionAlone(): void
    {
        $here = $this->rememberAdmin();
        $this->request('POST', []);
        unset($this->container['rememberMeSessionModel']);
        $this->container['rememberMeSessionModel'] = new StewardStuckRememberMeModel($this->container);
        $this->cookie->expects($this->never())->method('remove');
        $before = $_SESSION;

        $this->assertSame('rememberme_failed', $this->service()->start($this->ana));

        $this->assertSame($before, $_SESSION);
        $this->assertSame(1, $this->rowCount(RememberMeSessionModel::TABLE, ['id' => $here]));
        $start = $this->auditRows('switch.start');
        $refused = $this->auditRows('switch.refused');
        $this->assertCount(1, $start);
        $this->assertCount(1, $refused);
        $this->assertSame($start[0]['switch_id'], $refused[0]['switch_id']);
        $this->assertSame('rememberme_failed', json_decode($refused[0]['details'], true)['reason']);
        $this->assertSame($this->ana, (int) $refused[0]['subject_user_id']);
    }

    // --- real database-backed session -------------------------------------------------------------------

    /**
     * A real PHP session needs a process that has sent no output yet.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPreSwitchSessionIdIsDestroyedInTheDatabaseSessionStore(): void
    {
        $this->assertSame('db', SESSION_HANDLER);
        $this->openDbSession();
        $this->loginAs(self::ADMIN);
        session_write_close();                 // persists the pre-switch session row
        $this->openDbSession(session_id());    // and resumes it, as the next request would
        $old = session_id();
        $this->assertSame(1, $this->rowCount('sessions', ['id' => $old]));
        $this->assertSame(self::ADMIN, $this->container['userSession']->getId());

        $this->assertNull($this->service()->start($this->ana));

        $new = session_id();
        $this->assertNotSame($old, $new);
        $this->assertSame(0, $this->rowCount('sessions', ['id' => $old]), 'the old session id is gone from the store');
        session_write_close();
        $this->assertSame(1, $this->rowCount('sessions', ['id' => $new]));
        $this->assertSame(0, $this->rowCount('sessions', ['id' => $old]));
        $data = $this->container['db']->table('sessions')->eq('id', $new)->findOneColumn('data');
        $this->assertStringContainsString('steward_switch', $data);
    }

    /** A cookie-less PHP session on Kanboard's database handler: nothing is sent, so it runs under the CLI. */
    private function openDbSession(?string $id = null): void
    {
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_set_save_handler(new SessionHandler($this->container['db']), true);
        if ($id !== null) {
            session_id($id);
        }
        session_start();
    }
}
