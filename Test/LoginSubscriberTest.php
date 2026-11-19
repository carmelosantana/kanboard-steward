<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;
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

class LoginSubscriberTest extends Base
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
        $this->assertSame(['auth_type' => 'Database'], json_decode($rows[0]['details'], true));
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
        $this->assertSame(['username' => str_repeat('é', 64)], json_decode($rows[0]['details'], true));
    }

    public function testFailureForAKnownUserLinksTheSubject(): void
    {
        $this->container['dispatcher']->dispatch(new AuthFailureEvent('alice'), AuthenticationManager::EVENT_FAILURE);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($this->alice, (int) $rows[0]['subject_user_id']);
        $this->assertNull($rows[0]['actor_user_id']);
        $this->assertSame(['username' => 'alice'], json_decode($rows[0]['details'], true));
    }

    public function testFailureWithoutUsernameStillWritesARow(): void
    {
        $this->container['dispatcher']->dispatch(new AuthFailureEvent(), AuthenticationManager::EVENT_FAILURE);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('login.failure', $rows[0]['event']);
        $this->assertSame(['username' => ''], json_decode($rows[0]['details'], true));
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
