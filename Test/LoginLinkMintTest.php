<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\Role;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Model\AclCheck;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Model\LoginLinks;
use Kanboard\Plugin\Steward\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;

/** Collects log lines so expected errors never reach stderr. */
class StewardLoginLinkMintLogSink extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

class LoginLinkMintTest extends StewardTestCase
{
    private const ADMIN = 1;
    private const APP_URL = 'https://kb.example/';

    private int $ana;
    private StewardLoginLinkMintLogSink $log;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Steward\Schema\version_1($pdo);
        \Kanboard\Plugin\Steward\Schema\version_2($pdo);
        \Kanboard\Plugin\Steward\Schema\version_3($pdo);
        unset($pdo);
        (new Plugin($this->container))->initialize();
        $this->container['configModel']->save(['steward_login_links' => '1', 'application_url' => self::APP_URL]);
        $this->container['memoryCache']->flush();

        $this->log = new StewardLoginLinkMintLogSink();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        $this->ana = (new UserModel($this->container))->create([
            'username' => 'ana', 'name' => 'Ana Example', 'password' => 'secret-password', 'email' => 'ana@example.org',
        ]);
        $this->loginAs(self::ADMIN);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['steward_switch']);
        parent::tearDown();
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function links(): LoginLinks
    {
        return $this->container['stewardLoginLinks'];
    }

    private function rows(): array
    {
        return $this->container['db']->table(LoginLinks::TABLE)->asc('id')->findAll();
    }

    private function auditRows(string $event): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->eq('event', $event)->asc('id')->findAll();
    }

    private function refusedReasons(): array
    {
        return array_map(fn ($r) => json_decode($r['details'], true)['reason'], $this->auditRows('login_link.refused'));
    }

    private function setAppUrl(string $url): void
    {
        $this->container['configModel']->save(['application_url' => $url]);
        $this->container['memoryCache']->flush();
    }

    private function tokenOf(array $result): string
    {
        $this->assertMatchesRegularExpression('#/login-link/([0-9a-f]{64})$#D', $result['url']);
        return substr($result['url'], -64);
    }

    // --- wiring ----------------------------------------------------------------------------------------

    public function testConstantsAndContainerKey(): void
    {
        $this->assertInstanceOf(LoginLinks::class, $this->links());
        $this->assertSame('steward_login_link', LoginLinks::TABLE);
        $this->assertSame(900, LoginLinks::LIFETIME);
        $this->assertSame(10, LoginLinks::RATE_LIMIT);
        $this->assertSame(3600, LoginLinks::RATE_WINDOW);
    }

    // --- policy ----------------------------------------------------------------------------------------

    public function testRefusalForAllowsAnActiveLocalNonAdmin(): void
    {
        $this->assertNull($this->links()->refusalFor($this->container['userModel']->getById($this->ana)));
        $this->assertSame('not_found', $this->links()->refusalFor(null));
        $this->assertSame([], $this->auditRows('login_link.refused'), 'refusalFor never audits');
    }

    /** Puts the actor or target into the state that gives $reason. */
    private function arrange(string $reason): int
    {
        $users = $this->container['db']->table(UserModel::TABLE);
        switch ($reason) {
            case 'disabled':
                $this->container['configModel']->save(['steward_login_links' => '0']);
                break;
            case 'acl_conflict':
                $this->container[AclCheck::FLAG] = true;
                break;
            case 'not_admin':
                $users->eq('id', self::ADMIN)->update(['role' => Role::APP_MANAGER]);
                break;
            case 'switched':
                $_SESSION['steward_switch'] = ['switch_id' => str_repeat('a', 64), 'admin_id' => 1, 'admin_username' => 'admin', 'started_at' => 1, 'last_seen' => 1];
                break;
            case '2fa_pending':
                $users->eq('id', self::ADMIN)->update(['twofactor_activated' => 1, 'twofactor_secret' => 'X']);
                break;
            case 'not_found':
                return 999;
            case 'inactive':
                $this->container['userModel']->disable($this->ana);
                break;
            case 'self':
                return self::ADMIN;
            case 'target_admin':
                $users->eq('id', $this->ana)->update(['role' => Role::APP_ADMIN]);
                break;
            case 'external_auth':
                $users->eq('id', $this->ana)->update(['is_ldap_user' => 1]);
                break;
            case 'locked':
                $this->container['userLockingModel']->lock('ana', 15);
                break;
        }
        $this->container['memoryCache']->flush();
        return $this->ana;
    }

    public static function reasons(): array
    {
        $all = ['disabled', 'acl_conflict', 'not_admin', 'switched', '2fa_pending',
            'not_found', 'inactive', 'self', 'target_admin', 'external_auth', 'locked'];
        return array_combine($all, array_map(fn ($r) => [$r], $all));
    }

    /** @dataProvider reasons */
    #[DataProvider('reasons')]
    public function testEachRefusalIsRefusedAndAuditedOnce(string $reason): void
    {
        $id = $this->arrange($reason);
        $target = $this->container['userModel']->getById($id) ?: null;

        $this->assertSame($reason, $this->links()->refusalFor($target));
        $this->assertSame([], $this->auditRows('login_link.refused'));

        $this->assertSame(['ok' => false, 'reason' => $reason], $this->links()->mint($id));
        $this->assertSame([$reason], $this->refusedReasons());
        $this->assertSame([], $this->rows());
        $this->assertSame([], $this->auditRows('login_link.created'));
    }

    public static function actorReasons(): array
    {
        return ['disabled' => ['disabled'], 'acl_conflict' => ['acl_conflict'], 'not_admin' => ['not_admin'],
            'switched' => ['switched'], '2fa_pending' => ['2fa_pending']];
    }

    /**
     * Actor reasons come before the target: a bad id gets the same answer as a good one.
     *
     * @dataProvider actorReasons
     */
    #[DataProvider('actorReasons')]
    public function testActorReasonsAreDecidedBeforeTheTarget(string $reason): void
    {
        $this->arrange($reason);

        $this->assertSame($reason, $this->links()->refusalFor(null));
        $this->assertSame($reason, $this->links()->mint(999)['reason']);
        $this->assertSame($reason, $this->links()->mint(0)['reason']);
        $this->assertSame([$reason, $reason], $this->refusedReasons());
    }

    public function testAppManagerWithABadIdGetsNotAdmin(): void
    {
        $this->arrange('not_admin');

        $this->assertSame(['ok' => false, 'reason' => 'not_admin'], $this->links()->mint(999));
    }

    public function testTwoFactorAdminWhoFinishedTheStepMayMint(): void
    {
        $this->arrange('2fa_pending');
        $this->container['userSession']->setPostAuthenticationAsValidated();

        $this->assertTrue($this->links()->mint($this->ana)['ok']);
    }

    // --- mint ------------------------------------------------------------------------------------------

    public function testMintStoresOnlyTheHash(): void
    {
        $before = time();
        $result = $this->links()->mint($this->ana);
        $after = time();

        $this->assertTrue($result['ok']);
        $token = $this->tokenOf($result);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, $column);
        }
        $this->assertSame($this->ana, (int) $row['user_id']);
        $this->assertSame(self::ADMIN, (int) $row['created_by']);
        $this->assertGreaterThanOrEqual($before, (int) $row['created_at']);
        $this->assertLessThanOrEqual($after, (int) $row['created_at']);
        $this->assertSame((int) $row['created_at'] + 900, (int) $row['expires_at']);
        $this->assertSame((int) $row['expires_at'], $result['expires_at']);
        $this->assertNull($row['used_at']);

        $created = $this->auditRows('login_link.created');
        $this->assertCount(1, $created);
        $this->assertSame(self::ADMIN, (int) $created[0]['actor_user_id']);
        $this->assertSame($this->ana, (int) $created[0]['subject_user_id']);
        $this->assertSame(['link_id' => (int) $row['id'], 'expires_at' => (int) $row['expires_at']], json_decode($created[0]['details'], true));
        $this->assertStringNotContainsString($token, json_encode($created));
        $this->assertSame([], $this->auditRows('login_link.refused'));
    }

    public function testTokensDiffer(): void
    {
        $a = $this->tokenOf($this->links()->mint($this->ana));
        $b = $this->tokenOf($this->links()->mint($this->ana));
        $this->assertNotSame($a, $b);
    }

    public function testRemintKillsEarlierLink(): void
    {
        $bob = $this->container['userModel']->create(['username' => 'bob', 'password' => 'secret-password']);
        $bobs = $this->links()->mint($bob);
        $first = $this->links()->mint($this->ana);
        $now = time();
        $second = $this->links()->mint($this->ana);

        $byHash = [];
        foreach ($this->rows() as $row) {
            $byHash[$row['token_hash']] = $row;
        }
        $this->assertCount(3, $byHash, 'the killed row is kept for the rate limit');
        $dead = $byHash[hash('sha256', $this->tokenOf($first))];
        $this->assertLessThanOrEqual(time(), (int) $dead['expires_at']);
        $this->assertGreaterThanOrEqual($now, (int) $dead['expires_at']);
        $this->assertNull($dead['used_at']);
        $live = $byHash[hash('sha256', $this->tokenOf($second))];
        $this->assertSame($second['expires_at'], (int) $live['expires_at']);
        $this->assertSame($bobs['expires_at'], (int) $byHash[hash('sha256', $this->tokenOf($bobs))]['expires_at'], "another user's link is untouched");
    }

    public function testRemintLeavesUsedLinksAlone(): void
    {
        $first = $this->links()->mint($this->ana);
        $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $this->tokenOf($first)))->update(['used_at' => 5]);
        $this->links()->mint($this->ana);

        $row = $this->container['db']->table(LoginLinks::TABLE)->eq('token_hash', hash('sha256', $this->tokenOf($first)))->findOne();
        $this->assertSame($first['expires_at'], (int) $row['expires_at']);
    }

    public function testEleventhMintInAnHourIsRateLimitedCountingKilledRows(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->links()->mint($this->ana)['ok'], "mint $i");
        }
        // Nine of the ten were killed by the next remint, but they still count.
        $this->assertSame(9, $this->container['db']->table(LoginLinks::TABLE)->lte('expires_at', time())->count());

        $this->assertSame(['ok' => false, 'reason' => 'rate_limited'], $this->links()->mint($this->ana));
        $this->assertSame(['rate_limited'], $this->refusedReasons());
        $this->assertCount(10, $this->rows());
    }

    public function testRateLimitIsPerAdminAndRolling(): void
    {
        $old = time() - 3601;
        $other = $this->container['userModel']->create(['username' => 'root2', 'password' => 'secret-password', 'role' => Role::APP_ADMIN]);
        for ($i = 0; $i < 10; $i++) {
            $this->container['db']->table(LoginLinks::TABLE)->insert([
                'user_id' => $this->ana, 'token_hash' => hash('sha256', "old$i"), 'created_by' => self::ADMIN,
                'created_at' => $old, 'expires_at' => time() + 60,
            ]);
            $this->container['db']->table(LoginLinks::TABLE)->insert([
                'user_id' => $this->ana, 'token_hash' => hash('sha256', "other$i"), 'created_by' => $other,
                'created_at' => time(), 'expires_at' => time() + 60,
            ]);
        }

        $this->assertTrue($this->links()->mint($this->ana)['ok']);
    }

    public function testHousekeepingDeletesRowsExpiredOverADayAgo(): void
    {
        $insert = fn (string $seed, int $expires) => $this->container['db']->table(LoginLinks::TABLE)->insert([
            'user_id' => $this->ana, 'token_hash' => hash('sha256', $seed), 'created_by' => 99,
            'created_at' => $expires - 900, 'expires_at' => $expires,
        ]);
        $insert('ancient', time() - 86401);
        $insert('recent', time() - 86000);

        $this->assertTrue($this->links()->mint($this->ana)['ok']);

        $hashes = array_column($this->rows(), 'token_hash');
        $this->assertNotContains(hash('sha256', 'ancient'), $hashes);
        $this->assertContains(hash('sha256', 'recent'), $hashes);
    }

    public function testEmptyApplicationUrlIsRefused(): void
    {
        $this->setAppUrl('');

        $this->assertSame(['ok' => false, 'reason' => 'no_app_url'], $this->links()->mint($this->ana));
        $this->assertSame(['no_app_url'], $this->refusedReasons());
        $this->assertSame([], $this->rows());
    }

    public function testUrlUsesApplicationUrl(): void
    {
        $token = str_repeat('ab', 32);
        $cases = [
            'https://kb.example' => 'https://kb.example/login-link/',
            'https://kb.example/' => 'https://kb.example/login-link/',
            'https://kb.example/kanboard' => 'https://kb.example/kanboard/login-link/',
            'https://kb.example/kanboard/' => 'https://kb.example/kanboard/login-link/',
        ];
        foreach ($cases as $base => $expected) {
            $this->setAppUrl($base);
            $this->assertSame($expected.$token, $this->links()->url($token), $base);
        }

        $this->setAppUrl('https://kb.example/kanboard');
        $result = $this->links()->mint($this->ana);
        $this->assertStringStartsWith('https://kb.example/kanboard/login-link/', $result['url']);
        $this->tokenOf($result);
    }

    public function testAuditFailureRefusesAndLeavesNoRow(): void
    {
        $this->container['db']->getConnection()->exec('DROP TABLE '.AuditLog::TABLE);

        $this->assertSame(['ok' => false, 'reason' => 'audit_failed'], $this->links()->mint($this->ana));

        $this->assertSame([], $this->rows());
        $errors = array_filter($this->log->lines, fn ($l) => $l[0] === 'error' && str_contains($l[1], 'audit_failed'));
        $this->assertNotEmpty($errors, 'the refusal is logged when the audit store is down');
    }
}
