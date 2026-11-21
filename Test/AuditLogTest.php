<?php
require_once __DIR__.'/support/StewardTestCase.php';
require_once __DIR__.'/support/StewardSchema.php';

use Kanboard\Core\Http\Request;
use Kanboard\Core\Log\Logger;
use Kanboard\Plugin\Steward\Model\AuditLog;
use Kanboard\Plugin\Steward\Plugin;

/** Collects log lines; installed on its own Logger so expected errors never reach stderr. */
class StewardAuditLogCollector extends \Kanboard\Core\Log\Base
{
    public array $lines = [];

    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }
}

class AuditLogTest extends StewardTestCase
{
    private StewardAuditLogCollector $log;

    protected function setUp(): void
    {
        parent::setUp();
        \Kanboard\Plugin\Steward\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();

        $this->log = new StewardAuditLogCollector();
        $logger = new Logger();
        $logger->setLogger($this->log);
        unset($this->container['logger']);
        $this->container['logger'] = $logger;

        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => str_repeat('U', 300),
        ]);
    }

    private function audit(): AuditLog
    {
        return $this->container['stewardAudit'];
    }

    private function rows(): array
    {
        return $this->container['db']->table(AuditLog::TABLE)->asc('id')->findAll();
    }

    /** Inserts $n rows in one transaction; ORM inserts would make the cap test slow. */
    private function bulk(int $n, string $event, int $createdAt): void
    {
        $pdo = $this->container['db']->getConnection();
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO '.AuditLog::TABLE.' (created_at, event) VALUES (?, ?)');
        for ($i = 0; $i < $n; $i++) {
            $st->execute([$createdAt + $i, $event]);
        }
        $pdo->commit();
    }

    public function testSchemaDialectsDeclareVersion1AndTheTable(): void
    {
        foreach (['Sqlite', 'Mysql', 'Postgres', 'Mssql'] as $d) {
            $src = file_get_contents(__DIR__."/../Schema/$d.php");
            $this->assertStringContainsString('namespace Kanboard\Plugin\Steward\Schema;', $src, $d);
            $this->assertStringContainsString('const VERSION = 3;', $src, $d);
            $this->assertStringContainsString('function version_1(PDO $pdo)', $src, $d);
            foreach (['steward_audit', 'created_at', 'event', 'actor_user_id', 'subject_user_id', 'switch_id', 'ip', 'user_agent', 'details'] as $col) {
                $this->assertStringContainsString($col, $src, "$d: $col");
            }
            foreach (['created_at', 'event', 'switch_id'] as $idx) {
                $this->assertMatchesRegularExpression('/CREATE INDEX steward_audit_'.$idx.'_idx ON (dbo\.)?steward_audit\s*\('.$idx.'\)/', $src, "$d index $idx");
            }
            $this->assertMatchesRegularExpression('/CREATE INDEX steward_audit_event_id_idx ON (dbo\.)?steward_audit\s*\(event, id\)/', $src, "$d index (event, id)");
        }
    }

    public function testSchemaDialectsDeclareVersion2AndTheProjectPageTable(): void
    {
        foreach (['Sqlite', 'Mysql', 'Postgres', 'Mssql'] as $d) {
            $src = file_get_contents(__DIR__."/../Schema/$d.php");
            $this->assertStringContainsString('function version_2(PDO $pdo)', $src, $d);
            foreach (['steward_project_page', 'project_id', 'tagline', 'links', 'panels', 'public_panels', 'is_public', 'is_listed', 'show_names', 'updated_at'] as $col) {
                $this->assertStringContainsString($col, $src, "$d: $col");
            }
            $this->assertMatchesRegularExpression('/REFERENCES (dbo\.)?projects\s*\(id\) ON DELETE CASCADE/', $src, "$d foreign key");
        }
    }

    public function testVersion2CreatesTheProjectPageTable(): void
    {
        $pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Steward\Schema\version_2($pdo);
        $columns = steward_table_columns($pdo, 'steward_project_page');
        $this->assertSame(['project_id', 'tagline', 'links', 'panels', 'public_panels', 'is_public', 'is_listed', 'show_names', 'updated_at'], $columns);
    }

    public function testMysqlTableUsesUtf8mb4LikeCore(): void
    {
        $src = file_get_contents(__DIR__.'/../Schema/Mysql.php');
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $src);
        $this->assertDoesNotMatchRegularExpression('/CHARSET=utf8(?!mb4)/', $src);
        $this->assertSame(3, substr_count($src, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    }

    /** 1.0's public constant stays for plugins that read it; the list is what Steward caps. */
    public function testFailureEventAliasIsKept(): void
    {
        $this->assertSame('login.failure', AuditLog::FAILURE_EVENT);
        $this->assertContains(AuditLog::FAILURE_EVENT, AuditLog::FAILURE_EVENTS);
    }

    public function testInternalWriteRoundTrips(): void
    {
        $switchId = $this->audit()->newSwitchId();
        $before = time();
        $this->assertTrue($this->audit()->logInternal('switch.start', 1, 2, ['reason' => 'support'], $switchId));

        $rows = $this->audit()->find([], 10, 0);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('switch.start', $row['event']);
        $this->assertSame(1, (int) $row['actor_user_id']);
        $this->assertSame(2, (int) $row['subject_user_id']);
        $this->assertSame($switchId, $row['switch_id']);
        $this->assertSame('203.0.113.7', $row['ip']);
        $this->assertSame(255, strlen($row['user_agent']));
        $this->assertSame(['reason' => 'support'], json_decode($row['details'], true));
        $this->assertGreaterThanOrEqual($before, (int) $row['created_at']);
        $this->assertSame(1, $this->audit()->count([]));
    }

    public function testNullsAndEmptyDetailsAreStored(): void
    {
        $this->assertTrue($this->audit()->logInternal('login.failure', null, null));
        $row = $this->rows()[0];
        $this->assertNull($row['actor_user_id']);
        $this->assertNull($row['subject_user_id']);
        $this->assertNull($row['switch_id']);
        $this->assertSame('[]', $row['details']);
    }

    public function testDetailsAreCutTo4096BytesOnACharacterBoundary(): void
    {
        $this->assertTrue($this->audit()->logInternal('settings.changed', 1, null, ['keys' => str_repeat('é', 5000)]));
        $details = $this->rows()[0]['details'];
        $this->assertLessThanOrEqual(4096, strlen($details));
        $this->assertGreaterThan(4090, strlen($details));
        $this->assertTrue(mb_check_encoding($details, 'UTF-8'));
    }

    public function testInvalidUtf8InDetailsIsStillWritten(): void
    {
        $this->assertTrue($this->audit()->logInternal('login.failure', null, null, ['username' => "bad\xff"]));
        $this->assertTrue(mb_check_encoding($this->rows()[0]['details'], 'UTF-8'));
    }

    public function testInvalidUtf8UserAgentIsScrubbed(): void
    {
        unset($this->container['request']);
        $this->container['request'] = new Request($this->container, ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => "evil\xff\xfe".str_repeat('é', 200)]);
        $this->assertTrue($this->audit()->logInternal('login.failure', null, null));
        $ua = $this->rows()[0]['user_agent'];
        $this->assertTrue(mb_check_encoding($ua, 'UTF-8'));
        $this->assertLessThanOrEqual(255, strlen($ua));
    }

    public function testPublicLogRejectsStewardEvents(): void
    {
        foreach (['switch.start', 'login.success', 'plugin.', 'plugin.vault', 'plugin..unlock', 'plugin.vault.', 'xplugin.vault.unlock', 'plugin.va ult.unlock', ''] as $event) {
            $this->assertFalse($this->audit()->log($event, 1, 2), $event);
        }
        $this->assertSame(0, $this->audit()->count([]));
    }

    public function testPublicLogAcceptsNamespacedPluginEvents(): void
    {
        $this->assertTrue($this->audit()->log('plugin.vault.unlock', 3, null, ['item' => 7]));
        $this->assertTrue($this->audit()->log('plugin.git_host.repo.created', 3, null));
        $this->assertSame(['plugin.vault.unlock', 'plugin.git_host.repo.created'], array_column($this->rows(), 'event'));
    }

    public function testInternalLogRejectsMalformedEvents(): void
    {
        $this->assertFalse($this->audit()->logInternal('', 1, null));
        $this->assertFalse($this->audit()->logInternal(str_repeat('a', 101), 1, null));
        $this->assertFalse($this->audit()->logInternal("switch.start\n", 1, null));
        $this->assertSame(0, $this->audit()->count([]));
    }

    public function testHookFiresAfterEachWriteWithTheRow(): void
    {
        $seen = [];
        $this->container['hook']->on('steward:audit', function (array $row) use (&$seen) {
            $seen[] = $row;
        });
        $this->audit()->logInternal('switch.end', 1, 2, ['a' => 1], 'abc');
        $this->audit()->log('switch.end', 1, 2); // rejected: no hook

        $this->assertCount(1, $seen);
        $this->assertSame('switch.end', $seen[0]['event']);
        $this->assertSame((int) $this->rows()[0]['id'], $seen[0]['id']);
        $this->assertSame('abc', $seen[0]['switch_id']);
        $this->assertSame('{"a":1}', $seen[0]['details']);
    }

    public function testAThrowingListenerDoesNotBreakTheWrite(): void
    {
        $this->container['hook']->on('steward:audit', function () {
            throw new RuntimeException('listener boom');
        });
        $this->assertTrue($this->audit()->logInternal('switch.end', 1, 2));
        $this->assertSame(1, $this->audit()->count([]));
        $this->assertStringContainsString('listener boom', implode("\n", array_column($this->log->lines, 1)));
    }

    public function testAListenerThatLogsDoesNotRecurse(): void
    {
        $audit = $this->audit();
        $this->container['hook']->on('steward:audit', function (array $row) use ($audit) {
            $audit->log('plugin.mirror.copy', null, null, ['of' => $row['event']]);
        });
        $this->assertTrue($audit->logInternal('switch.end', 1, 2));
        $this->assertSame(['switch.end', 'plugin.mirror.copy'], array_column($this->rows(), 'event'));
    }

    public function testFindFiltersAndPaginatesNewestFirst(): void
    {
        $db = $this->container['db'];
        $now = time();
        $db->table(AuditLog::TABLE)->insert(['created_at' => $now - 300, 'event' => 'switch.start', 'actor_user_id' => 1, 'subject_user_id' => 2]);
        $db->table(AuditLog::TABLE)->insert(['created_at' => $now - 200, 'event' => 'switch.end', 'actor_user_id' => 1, 'subject_user_id' => 2]);
        $db->table(AuditLog::TABLE)->insert(['created_at' => $now - 100, 'event' => 'login.success', 'actor_user_id' => 3]);
        $db->table(AuditLog::TABLE)->insert(['created_at' => $now, 'event' => 'login.failure']);

        $this->assertSame(['login.failure', 'login.success', 'switch.end', 'switch.start'], array_column($this->audit()->find([], 10, 0), 'event'));
        $this->assertSame(['login.success', 'switch.end'], array_column($this->audit()->find([], 2, 1), 'event'));
        $this->assertSame(['switch.end'], array_column($this->audit()->find(['event' => 'switch.end'], 10, 0), 'event'));
        $this->assertSame(['switch.end', 'switch.start'], array_column($this->audit()->find(['user_id' => 2], 10, 0), 'event'));
        $this->assertSame(['login.success'], array_column($this->audit()->find(['user_id' => 3], 10, 0), 'event'));
        $this->assertSame(['switch.end', 'switch.start'], array_column($this->audit()->find(['user_id' => 1], 10, 0), 'event'));
        $this->assertSame(['login.success', 'switch.end'], array_column($this->audit()->find(['from' => $now - 200, 'to' => $now - 100], 10, 0), 'event'));
        $this->assertSame(2, $this->audit()->count(['from' => $now - 200, 'to' => $now - 100]));
        $this->assertSame(1, $this->audit()->count(['event' => 'login.failure']));
        $this->assertSame(4, $this->audit()->count(['event' => '', 'user_id' => '', 'from' => '', 'to' => '']));
    }

    public function testPruneDropsRowsOlderThanRetentionAtMostOnceADay(): void
    {
        $this->container['configModel']->save(['steward_audit_retention_days' => '30']);
        $this->container['memoryCache']->flush();
        $day = 86400;
        $this->bulk(1, 'switch.start', time() - 31 * $day);
        $this->bulk(1, 'switch.end', time() - 29 * $day);

        $this->audit()->prune();
        $this->assertSame(['switch.end'], array_column($this->rows(), 'event'));
        $this->assertGreaterThan(time() - 60, (int) $this->container['configModel']->getOption(AuditLog::LAST_PRUNE));

        $this->bulk(1, 'switch.start', time() - 31 * $day);
        $this->audit()->prune();
        $this->assertSame(2, $this->audit()->count([]), 'second prune the same day is a no-op');

        $this->container['configModel']->save([AuditLog::LAST_PRUNE => (string) (time() - $day - 1)]);
        $this->audit()->prune();
        $this->assertSame(['switch.end'], array_column($this->rows(), 'event'));
    }

    public function testPruneUsesTheDefaultRetentionWhenUnset(): void
    {
        $day = 86400;
        $this->bulk(1, 'switch.start', time() - 181 * $day);
        $this->bulk(1, 'switch.end', time() - 179 * $day);
        $this->audit()->prune();
        $this->assertSame(['switch.end'], array_column($this->rows(), 'event'));
    }

    public function testWriteTriggersTheDailyPrune(): void
    {
        $this->bulk(1, 'switch.start', time() - 400 * 86400);
        $this->assertTrue($this->audit()->logInternal('switch.end', 1, 2));
        $this->assertSame(['switch.end'], array_column($this->rows(), 'event'));
    }

    public function testPruneCapsFailureRowsAt10000OldestFirst(): void
    {
        $base = time() - 20000;
        $this->bulk(10005, 'login.failure', $base);
        $this->bulk(3, 'login.success', $base);

        $this->audit()->prune();

        $this->assertSame(10000, $this->audit()->count(['event' => 'login.failure']));
        $this->assertSame(3, $this->audit()->count(['event' => 'login.success']));
        $oldest = $this->container['db']->table(AuditLog::TABLE)->eq('event', 'login.failure')->asc('created_at')->findOneColumn('created_at');
        $this->assertSame($base + 5, (int) $oldest);
    }

    public function testFailureWriteEnforcesTheCapEvenAfterTodaysPrune(): void
    {
        $this->container['configModel']->save([AuditLog::LAST_PRUNE => (string) time()]);
        $this->bulk(10000, 'login.failure', time() - 20000);
        $this->assertTrue($this->audit()->logInternal('login.failure', null, null, ['username' => 'x']));
        $this->assertSame(10000, $this->audit()->count(['event' => 'login.failure']));
        $this->assertSame(1, $this->container['db']->table(AuditLog::TABLE)->eq('event', 'login.failure')->gte('created_at', time() - 60)->count());
    }

    public function testFailureEventsListTheCappedEvents(): void
    {
        $this->assertSame(['login.failure', 'login_link.failed'], AuditLog::FAILURE_EVENTS);
    }

    public function testPruneCapsEachFailureEventOnItsOwn(): void
    {
        $base = time() - 30000;
        $this->bulk(10003, 'login_link.failed', $base);
        $this->bulk(4000, 'login.failure', $base);

        $this->audit()->prune();

        $this->assertSame(10000, $this->audit()->count(['event' => 'login_link.failed']));
        $this->assertSame(4000, $this->audit()->count(['event' => 'login.failure']), 'one event never eats the other\'s rows');
        $oldest = $this->container['db']->table(AuditLog::TABLE)->eq('event', 'login_link.failed')->asc('created_at')->findOneColumn('created_at');
        $this->assertSame($base + 3, (int) $oldest);
    }

    public function testLinkFailureWriteEnforcesItsOwnCap(): void
    {
        $this->container['configModel']->save([AuditLog::LAST_PRUNE => (string) time()]);
        $this->bulk(10000, 'login_link.failed', time() - 20000);
        $this->bulk(10000, 'login.failure', time() - 20000);

        $this->assertTrue($this->audit()->logInternal('login_link.failed', null, null, ['reason' => 'unknown']));

        $this->assertSame(10000, $this->audit()->count(['event' => 'login_link.failed']));
        $this->assertSame(10000, $this->audit()->count(['event' => 'login.failure']));
        $this->assertSame(1, $this->container['db']->table(AuditLog::TABLE)->eq('event', 'login_link.failed')->gte('created_at', time() - 60)->count());
    }

    public function testHousekeepingFailureInsideACallersTransactionNeverLosesAReportedRow(): void
    {
        $db = $this->container['db'];
        // Prune is due and its settings read will fail; PicoDb cancels the open transaction on any SQL error.
        // Dropped before the transaction starts: MySQL commits implicitly on DDL.
        $db->getConnection()->exec('DROP TABLE settings');
        $db->startTransaction();

        $ok = $this->audit()->logInternal('login.failure', null, null, ['username' => 'x']);
        $inTx = $db->getConnection()->inTransaction();
        $rows = $db->table(AuditLog::TABLE)->eq('event', 'login.failure')->count();
        if ($inTx) {
            $db->closeTransaction();
        }

        $this->assertTrue($ok);
        $this->assertTrue($inTx, 'the caller\'s transaction is still open');
        $this->assertSame(1, $rows);
        $this->assertSame(1, $this->audit()->count(['event' => 'login.failure']), 'the row survives the commit');
    }

    public function testHousekeepingRunsOnTheNextWriteOutsideATransaction(): void
    {
        $this->bulk(1, 'switch.start', time() - 400 * 86400);
        $db = $this->container['db'];
        $db->startTransaction();
        $this->assertTrue($this->audit()->logInternal('switch.end', 1, 2));
        $db->closeTransaction();
        $this->assertSame(2, $this->audit()->count([]), 'no prune inside a transaction');

        $this->assertTrue($this->audit()->logInternal('switch.end', 1, 2));
        $this->assertSame(['switch.end', 'switch.end'], array_column($this->rows(), 'event'));
    }

    public function testNewSwitchIdIs64HexChars(): void
    {
        $a = $this->audit()->newSwitchId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertNotSame($a, $this->audit()->newSwitchId());
    }

    public function testWritesReturnFalseWithoutThrowingWhenTheTableIsMissing(): void
    {
        $this->container['db']->getConnection()->exec('DROP TABLE '.AuditLog::TABLE);
        $fired = false;
        $this->container['hook']->on('steward:audit', function () use (&$fired) {
            $fired = true;
        });

        $this->assertFalse($this->audit()->logInternal('switch.start', 1, 2));
        $this->assertFalse($this->audit()->log('plugin.vault.unlock', 1, null));
        $this->assertFalse($fired);
        $this->assertSame([], $this->audit()->find([], 10, 0));
        $this->assertSame(0, $this->audit()->count([]));
        $this->audit()->prune();
        $this->assertNotSame([], array_filter($this->log->lines, fn ($l) => $l[0] === 'error'));
    }

    public function testLastPruneIsNotAUserEditableSetting(): void
    {
        $this->assertArrayNotHasKey(AuditLog::LAST_PRUNE, \Kanboard\Plugin\Steward\Model\SettingsSchema::filter([AuditLog::LAST_PRUNE => '1']));
    }
}
