<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Core\Http\RememberMeCookie;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Model\RememberMeSessionModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\LoginLinks;
use Kanboard\Plugin\Steward\Plugin;
use Kanboard\Subscriber\AuthSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;

/** Collects log lines so expected errors never reach stderr. */
class StewardLoginLinkRedeemLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

class LoginLinkRedeemTest extends StewardTestCase
{
    private const ADMIN = 1;
    private const IP = '203.0.113.7';
    private const UA = 'LoginLinkRedeemTest/1.0';

    private int $ana;
    private StewardLoginLinkRedeemLogSink $log;
    /** @var \PHPUnit\Framework\MockObject\MockObject&RememberMeCookie */
    private $cookie;
    private array $cookies = [];
    private array $authTypes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Steward\Schema\version_1($pdo);
        \Kanboard\Plugin\Steward\Schema\version_2($pdo);
        \Kanboard\Plugin\Steward\Schema\version_3($pdo);
        unset($pdo);
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_login_links' => '1', 'application_url' => 'https://kb.example/']);
        $this->container['memoryCache']->flush();

        $this->log = new StewardLoginLinkRedeemLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        // setcookie() can't run under PHPUnit (headers already sent): record remove()/write() instead; read() stays real.
        $this->cookie = $this->getMockBuilder(RememberMeCookie::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['remove', 'write'])
            ->getMock();
        $this->cookie->method('remove')->willReturn(true);
        unset($this->container['rememberMeCookie']);
        $this->container['rememberMeCookie'] = $this->cookie;

        // Core's own after-login work (last login, lockout reset, remember-me), as the app registers it.
        $this->container['dispatcher']->addSubscriber(new AuthSubscriber($this->container));
        $this->container['dispatcher']->addListener('auth.success', function ($event) {
            $this->authTypes[] = $event->getAuthType();
        });

        $this->ana = (new UserModel($this->container))->create([
            'username' => 'ana', 'name' => 'Ana Example', 'password' => 'secret-password', 'email' => 'ana@example.org',
        ]);
        $this->request();
    }

    private function request(): void
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, [
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => self::IP,
            'HTTP_USER_AGENT' => self::UA,
        ], [], [], [], $this->cookies);
    }

    private function links(): LoginLinks
    {
        return $this->container['stewardLoginLinks'];
    }

    /** Mints a link for $userId as the admin, then signs the browser out; returns the raw token. */
    private function mintFor(?int $userId = null): string
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById(self::ADMIN));
        $this->container['memoryCache']->flush();
        $result = $this->links()->mint($userId ?? $this->ana);
        $this->assertTrue($result['ok'], 'mint: '.($result['reason'] ?? ''));
        $this->signOut();
        return substr($result['url'], -64);
    }

    private function signOut(): void
    {
        $_SESSION = [];
        $this->container['memoryCache']->flush();
    }

    private function row(string $token): array
    {
        return $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $token))->findOne();
    }

    private function auditRows(string $event): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->eq('event', $event)->asc('id')->findAll();
    }

    /** @return list<array{reason:string,subject:?int,actor:?int}> */
    private function failures(): array
    {
        return array_map(fn ($r) => [
            'reason' => json_decode($r['details'], true)['reason'],
            'subject' => $r['subject_user_id'] === null ? null : (int) $r['subject_user_id'],
            'actor' => $r['actor_user_id'] === null ? null : (int) $r['actor_user_id'],
        ], $this->auditRows('login_link.failed'));
    }

    private function sessionUserId(): int
    {
        return (int) $this->container['userSession']->getId();
    }

    // --- success ----------------------------------------------------------------------------------------

    public function testRedeemSignsTheUserInLikeCoresLogin(): void
    {
        $token = $this->mintFor();
        $linkId = (int) $this->row($token)['id'];
        $_SESSION['hasRememberMe'] = true;

        $this->assertNull($this->links()->redeem($token));

        $this->assertSame($this->ana, $this->sessionUserId());
        $this->assertFalse($this->container['userSession']->isPostAuthenticationValidated());
        $this->assertArrayNotHasKey('hasRememberMe', $_SESSION);
        $this->assertSame(['StewardLoginLink'], $this->authTypes);
        $this->assertNotNull($this->row($token)['used_at']);

        $logins = $this->container['db']->table('last_logins')->eq('user_id', $this->ana)->findAll();
        $this->assertCount(1, $logins);
        $this->assertSame('StewardLoginLink', $logins[0]['auth_type']);
        $this->assertSame(0, $this->container['db']->table(RememberMeSessionModel::TABLE)->count(), 'no remember-me login');

        $this->assertCount(1, $this->auditRows('login.success'));
        $used = $this->auditRows('login_link.used');
        $this->assertCount(1, $used);
        $this->assertSame(self::ADMIN, (int) $used[0]['actor_user_id']);
        $this->assertSame($this->ana, (int) $used[0]['subject_user_id']);
        $this->assertSame(['link_id' => $linkId], json_decode($used[0]['details'], true));
        $this->assertSame([], $this->failures());
    }

    public function testTwoFactorUserSignsInWithTheCodeStillToEnter(): void
    {
        $this->container['db']->table(UserModel::TABLE)->eq('id', $this->ana)
            ->update(['twofactor_activated' => 1, 'twofactor_secret' => 'TOTPSECRET']);
        $token = $this->mintFor();

        $this->assertNull($this->links()->redeem($token));

        $this->assertSame($this->ana, $this->sessionUserId());
        $this->assertTrue($this->container['userSession']->hasPostAuthentication());
        $this->assertFalse($this->container['userSession']->isPostAuthenticationValidated());
    }

    // --- peek -------------------------------------------------------------------------------------------

    public function testConfirmDoesNotConsume(): void
    {
        $token = $this->mintFor();

        $this->assertSame($this->ana, (int) $this->links()->peek($token)['id']);
        $this->assertSame('ana', $this->links()->peek($token)['username']);
        $this->assertNull($this->row($token)['used_at']);
        $this->assertSame([], $this->failures(), 'peek never audits');

        $this->assertNull($this->links()->redeem($token));
        $this->assertSame($this->ana, $this->sessionUserId());
    }

    /** The confirm page needs a name, nothing more: never the password hash or any other column. */
    public function testPeekReturnsOnlyIdUsernameAndName(): void
    {
        $token = $this->mintFor();

        $this->assertSame(['id' => $this->ana, 'username' => 'ana', 'name' => 'Ana Example'], $this->links()->peek($token));
    }

    public function testPeekIsNullForEveryDeadLink(): void
    {
        $this->assertNull($this->links()->peek(str_repeat('a', 64)), 'unknown');
        $this->assertNull($this->links()->peek('nope'), 'malformed');

        $expired = $this->mintFor();
        $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $expired))->update(['expires_at' => time() - 1]);
        $this->assertNull($this->links()->peek($expired), 'expired');

        $used = $this->mintFor();
        $this->assertNull($this->links()->redeem($used));
        $this->signOut();
        $this->assertNull($this->links()->peek($used), 'used');

        $target = $this->mintFor();
        $this->container['userModel']->disable($this->ana);
        $this->assertNull($this->links()->peek($target), 'target disabled');
        $this->assertSame([], $this->failures(), 'peek never audits');
    }

    // --- failures ---------------------------------------------------------------------------------------

    public function testSecondRedeemFails(): void
    {
        $token = $this->mintFor();
        $this->assertNull($this->links()->redeem($token));
        $this->signOut();

        $this->assertSame('used', $this->links()->redeem($token));

        $this->assertSame(0, $this->sessionUserId());
        $this->assertSame([['reason' => 'used', 'subject' => $this->ana, 'actor' => null]], $this->failures());
        $this->assertCount(1, $this->auditRows('login_link.used'));
    }

    public function testExpiredLinkFails(): void
    {
        $token = $this->mintFor();
        $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $token))->update(['expires_at' => time() - 60]);

        $this->assertSame('expired', $this->links()->redeem($token));

        $this->assertSame(0, $this->sessionUserId());
        $this->assertNull($this->row($token)['used_at']);
        $this->assertSame([['reason' => 'expired', 'subject' => $this->ana, 'actor' => null]], $this->failures());
    }

    /** A remint sets the older row's expires_at to now: it must be dead within the same second. */
    public function testRemintKilledLinkIsExpiredAtOnce(): void
    {
        $first = $this->mintFor();
        $second = $this->mintFor();

        $this->assertSame('expired', $this->links()->redeem($first));
        $this->assertNull($this->links()->redeem($second));
    }

    public function testUnknownTokenFails(): void
    {
        $this->assertSame('unknown', $this->links()->redeem(str_repeat('0', 64)));
        $this->assertSame([['reason' => 'unknown', 'subject' => null, 'actor' => null]], $this->failures());
    }

    public static function malformedTokens(): array
    {
        $hex = str_repeat('ab', 32);
        return [
            'upper case' => [strtoupper($hex)],
            '63 chars' => [substr($hex, 1)],
            '65 chars' => [$hex.'a'],
            'trailing space' => [$hex.' '],
            'trailing newline' => [$hex."\n"],
            'url-encoded newline' => [$hex.'%0a'],
            'empty' => [''],
            'not hex' => [str_repeat('g', 64)],
        ];
    }

    /** @dataProvider malformedTokens */
    #[DataProvider('malformedTokens')]
    public function testMalformedTokens(string $mangled): void
    {
        $token = $this->mintFor();
        $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $token))
            ->update(['token_hash' => hash('sha256', $mangled)]);

        $this->assertNull($this->links()->peek($mangled));
        $this->assertSame('malformed', $this->links()->redeem($mangled));

        $this->assertSame(0, $this->sessionUserId());
        $this->assertNull($this->container['db']->table(LoginLinks::TABLE)->findOne()['used_at'], 'no row touched');
        $this->assertSame([['reason' => 'malformed', 'subject' => null, 'actor' => null]], $this->failures());
    }

    public static function targetChanges(): array
    {
        return ['disabled' => ['disabled'], 'admin' => ['admin'], 'ldap' => ['ldap'], 'login_form' => ['login_form'], 'locked' => ['locked']];
    }

    /** @dataProvider targetChanges */
    #[DataProvider('targetChanges')]
    public function testTargetChangedAfterMint(string $change): void
    {
        $token = $this->mintFor();
        $linkId = (int) $this->row($token)['id'];
        $users = $this->container['db']->table(UserModel::TABLE);
        match ($change) {
            'disabled' => $this->container['userModel']->disable($this->ana),
            'admin' => $users->eq('id', $this->ana)->update(['role' => Role::APP_ADMIN]),
            'ldap' => $users->eq('id', $this->ana)->update(['is_ldap_user' => 1]),
            'login_form' => $users->eq('id', $this->ana)->update(['disable_login_form' => 1]),
            'locked' => $this->container['userLockingModel']->lock('ana', 15),
        };
        $this->container['memoryCache']->flush();

        $this->assertSame('target_invalid', $this->links()->redeem($token));

        $this->assertSame(0, $this->sessionUserId());
        $this->assertNotNull($this->container['db']->table(LoginLinks::TABLE)->eq('id', $linkId)->findOneColumn('used_at'), 'the link stays spent');
        $this->assertSame([['reason' => 'target_invalid', 'subject' => $this->ana, 'actor' => null]], $this->failures());
        $this->assertSame([], $this->auditRows('login_link.used'));
        $this->assertSame([], $this->authTypes);
    }

    // --- already signed in ------------------------------------------------------------------------------

    public function testSignedInSessionIsRefusedAndTouchesNothing(): void
    {
        $token = $this->mintFor();
        $this->container['userSession']->initialize($this->container['userModel']->getById(self::ADMIN));

        $this->assertSame('signed_in', $this->links()->redeem($token));

        $this->assertSame(self::ADMIN, $this->sessionUserId());
        $this->assertNull($this->row($token)['used_at']);
        $this->assertSame([], $this->failures(), 'signed_in writes nothing');
    }

    public function testLiveRememberMeCookieCountsAsSignedIn(): void
    {
        $token = $this->mintFor();
        $s = $this->container['rememberMeSessionModel']->create(self::ADMIN, self::IP, self::UA);
        $this->cookies = [RememberMeCookie::COOKIE_NAME => $s['token'].'|'.$s['sequence']];
        $this->request();
        $this->cookie->expects($this->never())->method('remove');

        $this->assertSame('signed_in', $this->links()->redeem($token));

        $this->assertSame(0, $this->sessionUserId());
        $this->assertNull($this->row($token)['used_at']);
        $this->assertSame([], $this->failures());
    }

    public static function staleCookies(): array
    {
        return [
            'no matching session' => [str_repeat('d', 64).'|'.str_repeat('e', 64)],
            'malformed' => ['no-separator-here'],
        ];
    }

    /** @dataProvider staleCookies */
    #[DataProvider('staleCookies')]
    public function testStaleRememberMeCookieIsRemovedAndRedeemGoesOn(string $value): void
    {
        $token = $this->mintFor();
        $this->cookies = [RememberMeCookie::COOKIE_NAME => $value];
        $this->request();
        $this->cookie->expects($this->once())->method('remove');

        $this->assertNull($this->links()->redeem($token));

        $this->assertSame($this->ana, $this->sessionUserId());
    }

    public function testExpiredRememberMeSessionIsStale(): void
    {
        $token = $this->mintFor();
        $s = $this->container['rememberMeSessionModel']->create(self::ADMIN, self::IP, self::UA);
        $this->container['db']->table(RememberMeSessionModel::TABLE)->eq('token', $s['token'])->update(['expiration' => time() - 1]);
        $this->cookies = [RememberMeCookie::COOKIE_NAME => $s['token'].'|'.$s['sequence']];
        $this->request();
        $this->cookie->expects($this->once())->method('remove');

        $this->assertNull($this->links()->redeem($token));
        $this->assertSame($this->ana, $this->sessionUserId());
    }

    // --- audit ------------------------------------------------------------------------------------------

    public function testAuditFailureMeansNoSignIn(): void
    {
        $token = $this->mintFor();
        $this->container['db']->getConnection()->exec('DROP TABLE '.AuditLog::TABLE);

        $this->assertSame('audit_failed', $this->links()->redeem($token));

        $this->assertSame(0, $this->sessionUserId());
        $this->assertSame([], $this->authTypes);
        $this->assertSame(0, $this->container['db']->table('last_logins')->count());
        $errors = array_filter($this->log->lines, fn ($l) => $l[0] === 'error' && str_contains($l[1], 'audit_failed'));
        $this->assertNotEmpty($errors, 'the failure is logged when the audit store is down');
    }

    public function testLinkFailuresAreCappedApartFromLoginFailures(): void
    {
        $pdo = $this->container['db']->getConnection();
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO '.AuditLog::TABLE.' (created_at, event, details) VALUES (?, ?, ?)');
        for ($i = 0; $i < AuditLog::FAILURE_CAP; $i++) {
            $st->execute([time() - 1000, 'login_link.failed', '{}']);
        }
        $st->execute([time() - 1000, 'login.failure', '{}']);
        $pdo->commit();
        unset($pdo, $st);

        $this->assertSame('unknown', $this->links()->redeem(str_repeat('1', 64)));

        $this->assertSame(AuditLog::FAILURE_CAP, $this->container['db']->table(AuditLog::TABLE)->eq('event', 'login_link.failed')->count());
        $this->assertSame(1, $this->container['db']->table(AuditLog::TABLE)->eq('event', 'login.failure')->count());
    }
}
