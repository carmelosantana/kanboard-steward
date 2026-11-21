<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Core\Http\Request;
use Kanboard\Core\Log\Logger;
use Kanboard\Core\Security\AuthenticationManager;
use Kanboard\Event\AuthFailureEvent;
use Kanboard\Event\AuthSuccessEvent;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Plugin;

/** Swallows log lines so expected errors never reach stderr. */
class StewardLoginLogSink extends \Kanboard\Core\Log\Base
{
    public function log($level, $message, array $context = []): void
    {
    }
}

class LoginSubscriberTest extends StewardTestCase
{
    private int $alice;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();

        $logger = new Logger();
        $logger->setLogger(new StewardLoginLogSink());
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, [
            'REMOTE_ADDR' => '198.51.100.4',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);

        $this->alice = (new UserModel($this->container))->create(['username' => 'alice', 'name' => 'Alice Example']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['scope']);
        parent::tearDown();
    }

    /** What core's API AuthenticationMiddleware does before it authenticates (session_set('scope', 'API')). */
    private function asApi(): void
    {
        session_set('scope', 'API');
    }

    private function rows(): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->asc('id')->findAll();
    }

    public function testSuccessWritesLoginSuccessRow(): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($this->alice));
        $this->container['dispatcher']->dispatch(new AuthSuccessEvent('Database'), AuthenticationManager::EVENT_SUCCESS);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('login.success', $rows[0]['event']);
        $this->assertSame($this->alice, (int) $rows[0]['actor_user_id']);
        $this->assertSame($this->alice, (int) $rows[0]['subject_user_id']);
        $this->assertSame('198.51.100.4', $rows[0]['ip']);
        $this->assertSame('TestAgent/1.0', $rows[0]['user_agent']);
        $this->assertSame(['auth_type' => 'Database', 'source' => 'web'], json_decode($rows[0]['details'], true));
    }

    public function testApiSuccessWritesNothing(): void
    {
        $this->asApi();
        $this->container['userSession']->initialize($this->container['userModel']->getById($this->alice));
        $this->container['dispatcher']->dispatch(new AuthSuccessEvent('Database'), AuthenticationManager::EVENT_SUCCESS);
        $this->assertSame([], $this->rows());
    }

    public function testApiFailureWritesCappedFailureWithSourceApi(): void
    {
        $pdo = $this->container['db']->getConnection();
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO '.AuditLog::TABLE." (created_at, event, details) VALUES (?, 'login.failure', '{}')");
        for ($i = 0; $i < AuditLog::FAILURE_CAP; $i++) {
            $st->execute([time() - 1000 + $i % 100]);
        }
        $pdo->commit();

        $this->asApi();
        $this->container['dispatcher']->dispatch(new AuthFailureEvent('alice'), AuthenticationManager::EVENT_FAILURE);

        $this->assertSame(AuditLog::FAILURE_CAP, $this->container['db']->table(AuditLog::TABLE)->eq('event', 'login.failure')->count());
        $last = $this->container['db']->table(AuditLog::TABLE)->desc('id')->findOne();
        $this->assertSame('login.failure', $last['event']);
        $this->assertSame($this->alice, (int) $last['subject_user_id']);
        $this->assertSame(['username' => 'alice', 'source' => 'api'], json_decode($last['details'], true));
    }

    public function testRealApiPasswordLoginWritesOnlyTheFailure(): void
    {
        $this->container['userModel']->update(['id' => $this->alice, 'password' => 'correct-horse']);
        $auth = $this->container['authenticationManager'];
        $auth->register(new \Kanboard\Auth\DatabaseAuth($this->container));
        $this->asApi();

        $this->assertFalse($auth->passwordAuthentication('alice', 'wrong'));
        $this->assertTrue($auth->passwordAuthentication('alice', 'correct-horse'));

        $rows = $this->rows();
        $this->assertSame(['login.failure'], array_column($rows, 'event'));
        $this->assertSame('api', json_decode($rows[0]['details'], true)['source']);
    }

    public function testFailureWritesUsernameAsTypedCutTo64Chars(): void
    {
        $typed = str_repeat('é', 70).'<script>';
        $this->container['dispatcher']->dispatch(new AuthFailureEvent($typed), AuthenticationManager::EVENT_FAILURE);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('login.failure', $rows[0]['event']);
        $this->assertNull($rows[0]['actor_user_id']);
        $this->assertNull($rows[0]['subject_user_id']);
        $this->assertSame(['username' => str_repeat('é', 64), 'source' => 'web'], json_decode($rows[0]['details'], true));
    }

    public function testFailureForAKnownUserLinksTheSubject(): void
    {
        $this->container['dispatcher']->dispatch(new AuthFailureEvent('alice'), AuthenticationManager::EVENT_FAILURE);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($this->alice, (int) $rows[0]['subject_user_id']);
        $this->assertNull($rows[0]['actor_user_id']);
        $this->assertSame(['username' => 'alice', 'source' => 'web'], json_decode($rows[0]['details'], true));
    }

    public function testFailureWithoutUsernameStillWritesARow(): void
    {
        $this->container['dispatcher']->dispatch(new AuthFailureEvent(), AuthenticationManager::EVENT_FAILURE);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('login.failure', $rows[0]['event']);
        $this->assertSame(['username' => '', 'source' => 'web'], json_decode($rows[0]['details'], true));
    }

    public function testRealPasswordLoginWritesRows(): void
    {
        $this->container['userModel']->update(['id' => $this->alice, 'password' => 'correct-horse']);
        $auth = $this->container['authenticationManager'];
        $auth->register(new \Kanboard\Auth\DatabaseAuth($this->container));

        $this->assertFalse($auth->passwordAuthentication('alice', 'wrong'));
        $this->assertTrue($auth->passwordAuthentication('alice', 'correct-horse'));

        $events = array_column($this->rows(), 'event');
        $this->assertSame(['login.failure', 'login.success'], $events);
    }
}
