<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Http\Router;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Middleware\ApplicationAuthorizationMiddleware;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Controller\StewardLoginLinkController;
use Kanboard\Plugin\Steward\Model\FeatureGate;
use Kanboard\Plugin\Steward\Model\LoginLinks;
use Kanboard\Plugin\Steward\Model\SwitchActionTable;
use Kanboard\Plugin\Steward\Plugin;
use PicoDb\SQLException;

/** Collects log lines so expected errors never reach stderr. */
class StewardLoginLinkControllerLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

/** Spec §Routes and controller, §UI: the mint page, the confirm and redeem pages and the profile button. */
class LoginLinkControllerTest extends StewardTestCase
{
    private const ADMIN = 1;
    private const APP_URL = 'https://kb.example/';
    private const INVALID = 'This sign-in link is invalid or has expired.';
    private const SIGNED_IN = "You're signed in. Sign out first, then open the link again.";
    private const MINT_REFUSED = "You can't create a sign-in link for this user";
    private const NO_APP_URL = 'Set the Application URL in Settings before creating sign-in links';

    private int $ana;
    private int $boss;
    private int $ldap;
    private int $noForm;
    private StewardLoginLinkControllerLogSink $log;
    private ?string $body = null;
    private ?int $status = null;
    private ?string $redirect = null;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Steward\Schema\version_1($pdo);
        \Kanboard\Plugin\Steward\Schema\version_2($pdo);
        \Kanboard\Plugin\Steward\Schema\version_3($pdo);
        unset($pdo);
        (new Plugin($this->container))->initialize();
        $this->setFeature(true);
        $this->container['configModel']->save(['application_url' => self::APP_URL]);
        $this->container['memoryCache']->flush();

        $this->log = new StewardLoginLinkControllerLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $cookie = $this->getMockBuilder(RememberMeCookie::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['remove', 'write'])->getMock();
        $cookie->method('remove')->willReturn(true);
        unset($this->container['rememberMeCookie']);
        $this->container['rememberMeCookie'] = $cookie;

        $users = new UserModel($this->container);
        $this->ana = $users->create(['username' => 'ana', 'name' => '<b>Ana & "Co"</b>', 'password' => 'secret-password']);
        $this->boss = $users->create(['username' => 'boss', 'name' => 'Big Boss', 'password' => 'secret-password', 'role' => Role::APP_ADMIN]);
        $this->ldap = $users->create(['username' => 'dir', 'name' => 'Dir User', 'is_ldap_user' => 1]);
        $this->noForm = $users->create(['username' => 'sso', 'name' => 'SSO User', 'password' => 'secret-password']);
        $this->container['db']->table(UserModel::TABLE)->eq('id', $this->noForm)->update(['disable_login_form' => 1]);

        $this->loginAs(self::ADMIN);
    }

    // --- helpers ----------------------------------------------------------------------------------------

    private function setFeature(bool $on): void
    {
        $this->container['configModel']->save(['steward_login_links' => $on ? '1' : '0']);
        $this->container['memoryCache']->flush();
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['userSession']->setPostAuthenticationAsValidated();
        $this->container['memoryCache']->flush();
    }

    private function signOut(): void
    {
        $_SESSION = [];
        $this->container['memoryCache']->flush();
    }

    private function csrf(): string
    {
        return $this->container['token']->getCSRFToken();
    }

    /** Runs $action on a fresh request; the body, status and redirect land in the properties. */
    private function act(string $action, string $method = 'GET', array $get = [], array $post = []): void
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, [
            'REQUEST_METHOD' => $method,
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_USER_AGENT' => 'LoginLinkControllerTest/1.0',
        ], $get + ['controller' => 'StewardLoginLinkController', 'action' => $action, 'plugin' => 'Steward'], $post, [], []);

        $this->body = $this->status = $this->redirect = null;
        $response = $this->getMockBuilder(Response::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['html', 'redirect'])->getMock();
        $response->method('html')->willReturnCallback(function ($body, $status = 200) {
            $this->body = (string) $body;
            $this->status = (int) $status;
        });
        $response->method('redirect')->willReturnCallback(function ($url) { $this->redirect = (string) $url; });
        unset($this->container['response']);
        $this->container['response'] = $response;

        (new StewardLoginLinkController($this->container))->$action();
    }

    private function headers(): array
    {
        return \Closure::bind(fn () => $this->httpHeaders, $this->container['response'], Response::class)();
    }

    private function create(int|string $userId): void
    {
        $this->act('create', 'POST', [], ['csrf_token' => $this->csrf(), 'user_id' => (string) $userId]);
    }

    /** Mints a link for Ana as the admin, then signs the browser out; returns the raw token. */
    private function mintForAna(): string
    {
        $this->loginAs(self::ADMIN);
        $result = $this->container['stewardLoginLinks']->mint($this->ana);
        $this->assertTrue($result['ok'], 'mint: '.($result['reason'] ?? ''));
        $this->signOut();
        return substr($result['url'], -64);
    }

    private function confirm(string $token): void
    {
        $this->act('confirm', 'GET', ['token' => $token]);
    }

    private function redeem(string $token): void
    {
        $this->act('redeem', 'POST', ['token' => $token], ['csrf_token' => $this->csrf()]);
    }

    private function row(string $token): array
    {
        return $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $token))->findOne();
    }

    /** The flash as the page shows it: core's t() stores it HTML-escaped and flashMessage() doesn't escape twice. */
    private function flashFailure(): ?string
    {
        $message = $this->container['flash']->getMessage('failure');
        return $message ? html_entity_decode($message, ENT_QUOTES, 'UTF-8') : null;
    }

    private function errors(): array
    {
        return array_column(array_filter($this->log->lines, fn ($l) => $l[0] === 'error'), 1);
    }

    private function profileButton(int $userId): string
    {
        return $this->container['helper']->hook->render('template:user:sidebar:actions', ['user' => $this->container['userModel']->getById($userId)]);
    }

    /** Replaces the container's LoginLinks with one whose $method throws a database error. */
    private function breakLinks(string $method): void
    {
        $links = $this->getMockBuilder(LoginLinks::class)
            ->setConstructorArgs([$this->container])->onlyMethods([$method])->getMock();
        $links->method($method)->willThrowException(new SQLException('SQLSTATE[HY000]: database is locked'));
        unset($this->container['stewardLoginLinks']);
        $this->container['stewardLoginLinks'] = $links;
    }

    private function assertInvalidPage(string $case = ''): void
    {
        $this->assertSame(404, $this->status, $case);
        $this->assertStringContainsString(htmlspecialchars(self::INVALID, ENT_QUOTES), (string) $this->body, $case);
        $this->assertStringNotContainsString('<form', (string) $this->body, $case);
        $this->assertNull($this->redirect, $case);
    }

    // --- wiring -----------------------------------------------------------------------------------------

    public function testAclFeatureGateAndSwitchRows(): void
    {
        $map = $this->container['applicationAccessMap'];
        $this->assertSame($map->getRoleHierarchy(Role::APP_ADMIN), $map->getRoles('StewardLoginLinkController', 'create'));
        $this->assertSame($map->getRoleHierarchy(Role::APP_PUBLIC), $map->getRoles('StewardLoginLinkController', 'confirm'));
        $this->assertSame($map->getRoleHierarchy(Role::APP_PUBLIC), $map->getRoles('StewardLoginLinkController', 'redeem'));
        $gate = new FeatureGate($this->container);
        foreach (['create', 'confirm', 'redeem'] as $action) {
            $this->assertSame('login_links', $gate->featureFor('StewardLoginLinkController', $action), $action);
            $this->assertSame(SwitchActionTable::BLOCKED, (new SwitchActionTable())->classify('Steward', 'StewardLoginLinkController', $action), $action);
        }
    }

    public function testNonAdminIsForbiddenFromCreateByTheAcl(): void
    {
        $this->loginAs($this->ana);
        unset($this->container['request'], $this->container['router']);
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'POST'],
            ['controller' => 'StewardLoginLinkController', 'action' => 'create', 'plugin' => 'Steward'], [], [], []);
        $this->container['router'] = new Router($this->container);
        $this->container['router']->dispatch();
        $this->expectException(AccessForbiddenException::class);
        (new ApplicationAuthorizationMiddleware($this->container))->execute();
    }

    public function testEveryActionAnswersTheFixed404WithTheFeatureOff(): void
    {
        $token = $this->mintForAna();
        $this->loginAs(self::ADMIN);
        $this->setFeature(false);
        $this->create($this->ana);
        $this->assertSame([FeatureGate::NOT_FOUND_BODY, 404], [$this->body, $this->status], 'create');
        $this->signOut();
        $this->confirm($token);
        $this->assertSame([FeatureGate::NOT_FOUND_BODY, 404], [$this->body, $this->status], 'confirm');
        $this->redeem($token);
        $this->assertSame([FeatureGate::NOT_FOUND_BODY, 404], [$this->body, $this->status], 'redeem');
        $this->assertNull($this->row($token)['used_at']);
        $this->assertSame(0, (int) $this->container['userSession']->getId());
    }

    // --- create -----------------------------------------------------------------------------------------

    public function testGetCreateIsForbidden(): void
    {
        $this->expectException(AccessForbiddenException::class);
        $this->act('create', 'GET', ['user_id' => (string) $this->ana], ['csrf_token' => $this->csrf(), 'user_id' => (string) $this->ana]);
    }

    public function testCreateWithABadCsrfTokenIsForbidden(): void
    {
        $this->csrf();
        try {
            $this->act('create', 'POST', [], ['csrf_token' => 'not-a-token', 'user_id' => (string) $this->ana]);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException) {
        }
        $this->assertSame(0, $this->container['db']->table(LoginLinks::TABLE)->count());
    }

    public function testCreateShowsTheLinkOnceWithCopyButtonExpiryAndNoStore(): void
    {
        $this->container['configModel']->save(['application_time_format' => 'g:i a']);
        $this->container['memoryCache']->flush();

        $this->create($this->ana);

        $this->assertSame(200, $this->status);
        $this->assertNull($this->redirect);
        $this->assertSame(1, preg_match_all('#https://kb\.example/\?controller=StewardLoginLinkController&amp;action=confirm&amp;plugin=Steward&amp;token=([0-9a-f]{64})#', $this->body, $m));
        $row = $this->row($m[1][0]);
        $this->assertSame($this->ana, (int) $row['user_id']);
        $this->assertSame('no-store', $this->headers()['Cache-Control'] ?? null);

        $this->assertMatchesRegularExpression('#<input[^>]+readonly[^>]+value="https://kb\.example/\?controller=StewardLoginLinkController&amp;action=confirm&amp;plugin=Steward&amp;token=[0-9a-f]{64}"#', $this->body);
        $this->assertMatchesRegularExpression('#<button[^>]+data-steward-copy="[^"]+"#', $this->body);
        $this->assertStringContainsString('Works once, until '.date('g:i a', (int) $row['expires_at']), $this->body);
        $this->assertStringContainsString('Creating a new link cancels this one.', $this->body);
        $this->assertStringContainsString('&lt;b&gt;Ana &amp; &quot;Co&quot;&lt;/b&gt;', $this->body);
        $this->assertStringNotContainsString('<b>Ana', $this->body);
        $this->assertStringNotContainsString('onclick', $this->body);
    }

    /** Target-phase refusals go back to the profile; the reason itself is only in the audit log. */
    public function testRefusalForATargetFlashesTheUniformMessageAndReturnsToTheProfile(): void
    {
        foreach (['admin target' => $this->boss, 'self' => self::ADMIN, 'ldap' => $this->ldap, 'login form disabled' => $this->noForm] as $case => $userId) {
            $this->create($userId);
            $this->assertSame(self::MINT_REFUSED, $this->flashFailure(), $case);
            $this->assertStringContainsString('controller=UserViewController&action=show&user_id='.$userId, (string) $this->redirect, $case);
            $this->assertNull($this->body, $case);
        }
    }

    public function testRefusalBeforeTheTargetIsKnownGoesToTheUserList(): void
    {
        $this->create(9999);
        $this->assertSame(self::MINT_REFUSED, $this->flashFailure());
        $this->assertStringContainsString('controller=UserListController&action=show', (string) $this->redirect);

        $this->create('2 OR 1=1');
        $this->assertSame(self::MINT_REFUSED, $this->flashFailure());
        $this->assertStringContainsString('controller=UserListController&action=show', (string) $this->redirect);
    }

    /** Past the ACL (which stops them first), a non-admin still learns nothing about the user. */
    public function testNonAdminReachingCreateGetsTheUserListWhetherOrNotTheUserExists(): void
    {
        $this->loginAs($this->ana);
        $this->create($this->boss);
        $existing = [$this->flashFailure(), $this->redirect];
        $this->create(9999);
        $this->assertSame($existing, [$this->flashFailure(), $this->redirect]);
        $this->assertSame(self::MINT_REFUSED, $existing[0]);
        $this->assertStringContainsString('controller=UserListController&action=show', (string) $existing[1]);
    }

    public function testMissingApplicationUrlHasItsOwnMessage(): void
    {
        $this->container['configModel']->save(['application_url' => '']);
        $this->container['memoryCache']->flush();

        $this->create($this->ana);

        $this->assertSame(self::NO_APP_URL, $this->flashFailure());
        $this->assertStringContainsString('controller=UserViewController&action=show&user_id='.$this->ana, (string) $this->redirect);
    }

    public function testDatabaseErrorWhileMintingFlashesTheUniformMessage(): void
    {
        $this->breakLinks('mint');

        $this->create($this->ana);

        $this->assertSame(self::MINT_REFUSED, $this->flashFailure());
        $this->assertStringContainsString('controller=UserListController&action=show', (string) $this->redirect);
        $this->assertNull($this->body);
        $this->assertCount(1, $this->errors());
        $this->assertStringContainsString('database is locked', $this->errors()[0]);
    }

    /** The link was made but can't be shown: logged, and the admin gets the same answer as a failed mint. */
    public function testErrorAfterMintingFlashesTheUniformMessage(): void
    {
        $users = $this->getMockBuilder(UserModel::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['getById'])->getMock();
        $users->method('getById')->willThrowException(new SQLException('SQLSTATE[HY000]: database is locked'));
        $links = $this->getMockBuilder(LoginLinks::class)
            ->setConstructorArgs([$this->container])->onlyMethods(['mint'])->getMock();
        $links->method('mint')->willReturn(['ok' => true, 'url' => self::APP_URL.'login-link/'.str_repeat('a', 64), 'expires_at' => time() + 900]);
        unset($this->container['stewardLoginLinks'], $this->container['userModel']);
        $this->container['stewardLoginLinks'] = $links;
        $this->container['userModel'] = $users;

        $this->create($this->ana);

        $this->assertSame(self::MINT_REFUSED, $this->flashFailure());
        $this->assertStringContainsString('controller=UserListController&action=show', (string) $this->redirect);
        $this->assertNull($this->body);
        $this->assertCount(1, $this->errors());
        $this->assertStringContainsString('database is locked', $this->errors()[0]);
    }

    // --- confirm ----------------------------------------------------------------------------------------

    public function testConfirmNamesTheUserPostsToRedeemAndConsumesNothing(): void
    {
        $token = $this->mintForAna();

        $this->confirm($token);

        $this->assertSame(200, $this->status);
        $this->assertSame('no-referrer', $this->headers()['Referrer-Policy'] ?? null);
        $this->assertSame('no-store', $this->headers()['Cache-Control'] ?? null);
        $this->assertStringContainsString('<strong>ana</strong>', $this->body);
        $this->assertStringContainsString('Sign in to Kanboard as', $this->body);
        $this->assertMatchesRegularExpression(
            '~<form method="post" action="[^"]*controller=StewardLoginLinkController&amp;action=redeem&amp;plugin=Steward&amp;token='.$token.'"~', $this->body);
        $this->assertStringContainsString('name="csrf_token"', $this->body);
        $this->assertStringContainsString('form-login', $this->body);
        $this->assertStringNotContainsString('class="header"', $this->body, 'no app chrome');
        $this->assertNull($this->row($token)['used_at']);
    }

    public function testConfirmShowsTheBrandedSiteNameEscaped(): void
    {
        $this->container['configModel']->save(['steward_branding' => '1', 'steward_site_name' => 'Acme <Boards>']);
        $this->container['memoryCache']->flush();
        $token = $this->mintForAna();

        $this->confirm($token);

        $this->assertStringContainsString('Sign in to Acme &lt;Boards&gt; as', $this->body);
    }

    /** Other plugins' login-form hooks (OAuth buttons, taglines) belong to the login page, not to these pages. */
    public function testLinkPagesRenderNoForeignLoginFormHooks(): void
    {
        foreach (['template:auth:login-form:before', 'template:auth:login-form:after'] as $hook) {
            $this->container['hook']->on($hook, [
                'template' => 'Steward:auth/logo',
                'callable' => fn () => ['steward_logo_url' => '', 'steward_site_name' => 'FOREIGN-HOOK-MARKER'],
            ]);
        }
        $token = $this->mintForAna();

        $this->confirm($token);
        $this->assertSame(200, $this->status);
        $this->assertStringContainsString('<strong>ana</strong>', $this->body);
        $this->assertStringNotContainsString('FOREIGN-HOOK-MARKER', $this->body, 'confirm');

        $this->confirm(str_repeat('0', 64));
        $this->assertInvalidPage('invalid');
        $this->assertStringNotContainsString('FOREIGN-HOOK-MARKER', $this->body, 'invalid');

        $this->loginAs($this->boss);
        $this->confirm($token);
        $this->assertStringContainsString(htmlspecialchars(self::SIGNED_IN, ENT_QUOTES), $this->body);
        $this->assertStringNotContainsString('FOREIGN-HOOK-MARKER', $this->body, 'signed in');
    }

    public function testLinkPagesShowStewardsBrandOnlyWithBrandingOn(): void
    {
        $this->container['configModel']->save(['steward_site_name' => 'Acme Boards']);
        $this->container['memoryCache']->flush();
        $token = $this->mintForAna();

        $this->confirm($token);
        $this->assertStringNotContainsString('steward-login-brand', $this->body, 'branding off');

        $this->container['configModel']->save(['steward_branding' => '1']);
        $this->container['memoryCache']->flush();

        $this->confirm($token);
        $this->assertStringContainsString('<h1 class="steward-login-name">Acme Boards</h1>', $this->body, 'confirm');
        $this->confirm(str_repeat('0', 64));
        $this->assertStringContainsString('<h1 class="steward-login-name">Acme Boards</h1>', $this->body, 'invalid');
    }

    public function testConfirmWhileSignedInShowsTheSignedInPage(): void
    {
        $token = $this->mintForAna();
        $this->loginAs($this->boss);

        $this->confirm($token);

        $this->assertStringContainsString(htmlspecialchars(self::SIGNED_IN, ENT_QUOTES), $this->body);
        $this->assertStringNotContainsString('<form', $this->body);
        $this->assertSame('no-referrer', $this->headers()['Referrer-Policy'] ?? null);
        $this->assertNull($this->row($token)['used_at']);
    }

    public function testDatabaseErrorOnConfirmShowsTheInvalidPage(): void
    {
        $token = $this->mintForAna();
        $this->breakLinks('peek');

        $this->confirm($token);

        $this->assertInvalidPage();
        $this->assertCount(1, $this->errors());
    }

    // --- redeem -----------------------------------------------------------------------------------------

    public function testRedeemSignsInAndGoesToTheDashboard(): void
    {
        $token = $this->mintForAna();

        $this->redeem($token);

        $this->assertStringContainsString('controller=DashboardController&action=show', (string) $this->redirect);
        $this->assertSame($this->ana, (int) $this->container['userSession']->getId());
        $this->assertNotNull($this->row($token)['used_at']);
    }

    public function testGetRedeemIsForbidden(): void
    {
        $token = $this->mintForAna();
        try {
            $this->act('redeem', 'GET', ['token' => $token]);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException) {
        }
        $this->assertNull($this->row($token)['used_at']);
    }

    public function testRedeemWithABadCsrfTokenIsForbidden(): void
    {
        $token = $this->mintForAna();
        $this->csrf();
        try {
            $this->act('redeem', 'POST', ['token' => $token], ['csrf_token' => 'not-a-token']);
            $this->fail('expected AccessForbiddenException');
        } catch (AccessForbiddenException) {
        }
        $this->assertNull($this->row($token)['used_at']);
        $this->assertSame(0, (int) $this->container['userSession']->getId());
        // Core's 403 page for a stale form still holds the token in its URL.
        $this->assertSame('no-referrer', $this->headers()['Referrer-Policy'] ?? null);
        $this->assertSame('no-store', $this->headers()['Cache-Control'] ?? null);
    }

    public function testRedeemWhileSignedInShowsTheSignedInPageAndConsumesNothing(): void
    {
        $token = $this->mintForAna();
        $this->loginAs($this->boss);

        $this->redeem($token);

        $this->assertStringContainsString(htmlspecialchars(self::SIGNED_IN, ENT_QUOTES), (string) $this->body);
        $this->assertNull($this->redirect);
        $this->assertSame($this->boss, (int) $this->container['userSession']->getId());
        $this->assertNull($this->row($token)['used_at']);
    }

    /** Unknown, used, expired and malformed: one page, byte for byte, from confirm and from redeem. */
    public function testEveryDeadLinkGetsTheIdenticalInvalidPage(): void
    {
        $used = $this->mintForAna();
        $this->redeem($used);
        $this->signOut();
        $expired = $this->mintForAna();
        $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $expired))->update(['expires_at' => time() - 1]);
        $tokens = ['unknown' => str_repeat('a', 64), 'used' => $used, 'expired' => $expired, 'malformed' => 'NOPE', 'empty' => ''];

        $bodies = [];
        foreach ($tokens as $case => $token) {
            $this->confirm($token);
            $this->assertInvalidPage('confirm '.$case);
            $this->assertSame('no-referrer', $this->headers()['Referrer-Policy'] ?? null, $case);
            $bodies[] = $this->body;
            $this->redeem($token);
            $this->assertInvalidPage('redeem '.$case);
            $bodies[] = $this->body;
            $this->assertSame(0, (int) $this->container['userSession']->getId(), $case);
        }
        $this->assertCount(1, array_unique($bodies));
    }

    public function testATokenParameterThatIsNotAStringIsInvalid(): void
    {
        $this->signOut();
        $this->act('confirm', 'GET', ['token' => ['x']]);
        $this->assertInvalidPage();
    }

    public function testDatabaseErrorOnRedeemShowsTheInvalidPage(): void
    {
        $token = $this->mintForAna();
        $this->breakLinks('redeem');

        $this->redeem($token);

        $this->assertInvalidPage();
        $this->assertCount(1, $this->errors());
        $this->assertStringContainsString('database is locked', $this->errors()[0]);
    }

    // --- profile button ---------------------------------------------------------------------------------

    public function testButtonIsAPostFormForAnEligibleUser(): void
    {
        $html = $this->profileButton($this->ana);
        $this->assertMatchesRegularExpression(
            '~<form method="post" action="[^"]*controller=StewardLoginLinkController&amp;action=create&amp;plugin=Steward&amp;user_id='.$this->ana.'"~', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('name="user_id" id="form-user_id" value="'.$this->ana.'"', $html);
        $this->assertStringContainsString('Sign-in link', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testButtonPostsToThePrettyRouteWhenUrlRewritingIsOn(): void
    {
        // Plugin::initialize() ran with rewriting off, so the route was never added; add it as Plugin.php does.
        $this->container['route']->enable();
        $this->container['route']->addRoute('steward/user/:user_id/login-link', 'StewardLoginLinkController', 'create', 'Steward');

        $this->assertMatchesRegularExpression('~<form method="post" action="[^"]*steward/user/'.$this->ana.'/login-link"~', $this->profileButton($this->ana));
    }

    public function testButtonHiddenForAdminLdapSelfAndNonAdminsAndWithTheFeatureOff(): void
    {
        foreach (['admin target' => $this->boss, 'ldap' => $this->ldap, 'login form disabled' => $this->noForm, 'self' => self::ADMIN] as $case => $userId) {
            $this->assertStringNotContainsString('StewardLoginLinkController', $this->profileButton($userId), $case);
        }
        $this->loginAs($this->boss);
        $this->assertStringContainsString('StewardLoginLinkController', $this->profileButton($this->ana), 'another admin');
        $this->loginAs($this->ana);
        $this->assertStringNotContainsString('StewardLoginLinkController', $this->profileButton($this->ldap), 'non-admin');

        $this->loginAs(self::ADMIN);
        $this->setFeature(false);
        $this->assertStringNotContainsString('StewardLoginLinkController', $this->profileButton($this->ana), 'feature off');
    }

    public function testCopyButtonIsWiredInStewardJs(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__).'/Assets/js/steward.js');
        $this->assertStringContainsString('data-steward-copy', $js);
        $this->assertStringContainsString('clipboard', $js);
    }
}
