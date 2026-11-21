<?php
require_once 'tests/units/Base.php';
require_once __DIR__.'/../Schema/Sqlite.php';

use KanboardTests\units\Base;

class LoginLinkSchemaTest extends Base
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->container['db']->getConnection();
        \Kanboard\Plugin\Steward\Schema\version_3($this->pdo);
    }

    private function insert(int $userId, string $hash): void
    {
        $this->pdo->prepare('INSERT INTO steward_login_link (user_id, token_hash, created_by, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $hash, 1, 1000, 1900]);
    }

    public function testSchemaDialectsDeclareVersion3AndTheLoginLinkTable(): void
    {
        foreach (['Sqlite', 'Mysql', 'Postgres', 'Mssql'] as $d) {
            $src = file_get_contents(__DIR__."/../Schema/$d.php");
            $this->assertStringContainsString('const VERSION = 3;', $src, $d);
            $this->assertStringContainsString('function version_3(PDO $pdo)', $src, $d);
            foreach (['steward_login_link', 'user_id', 'token_hash', 'created_by', 'created_at', 'expires_at', 'used_at'] as $col) {
                $this->assertStringContainsString($col, $src, "$d: $col");
            }
            $this->assertMatchesRegularExpression('/REFERENCES (dbo\.)?users\s*\(id\) ON DELETE CASCADE/', $src, "$d foreign key");
            $this->assertMatchesRegularExpression('/token_hash CHAR\(64\) NOT NULL UNIQUE/i', $src, "$d unique hash");
            $this->assertMatchesRegularExpression('/CREATE INDEX steward_login_link_created_by_idx ON (dbo\.)?steward_login_link\s*\(created_by, created_at\)/', $src, "$d rate-limit index");
        }
    }

    public function testVersion3CreatesTheTable(): void
    {
        $columns = array_column($this->pdo->query('PRAGMA table_info(steward_login_link)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        $this->assertSame(['id', 'user_id', 'token_hash', 'created_by', 'created_at', 'expires_at', 'used_at'], $columns);
    }

    public function testTokenHashIsUnique(): void
    {
        $hash = hash('sha256', 'token');
        $this->insert(1, $hash);
        $this->expectException(\PDOException::class);
        $this->insert(1, $hash);
    }

    public function testDeletingAUserDeletesTheirLinks(): void
    {
        $ana = $this->container['userModel']->create(['username' => 'ana', 'password' => 'secret-password']);
        $this->insert($ana, hash('sha256', 'a'));
        $this->insert(1, hash('sha256', 'b'));

        $this->assertTrue($this->container['userModel']->remove($ana));

        $this->assertSame(
            [1],
            array_map('intval', $this->pdo->query('SELECT user_id FROM steward_login_link')->fetchAll(\PDO::FETCH_COLUMN))
        );
    }
}
