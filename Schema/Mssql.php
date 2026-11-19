<?php
namespace Kanboard\Plugin\Steward\Schema;

use PDO;

const VERSION = 1;

/** No foreign keys to users: audit rows outlive the accounts they mention. Syntax follows core app/Schema/Mssql.php. */
function version_1(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE dbo.steward_audit (
            id int identity PRIMARY KEY
          , created_at bigint NOT NULL
          , event nvarchar(100) NOT NULL
          , actor_user_id int NULL
          , subject_user_id int NULL
          , switch_id nvarchar(64) NULL
          , ip nvarchar(45) NULL
          , user_agent nvarchar(255) NULL
          , details nvarchar(max) NULL
        );
    ');
    $pdo->exec('CREATE INDEX steward_audit_created_at_idx ON dbo.steward_audit(created_at)');
    $pdo->exec('CREATE INDEX steward_audit_event_idx ON dbo.steward_audit(event)');
    $pdo->exec('CREATE INDEX steward_audit_switch_id_idx ON dbo.steward_audit(switch_id)');
    // Serves the login.failure cap query (WHERE event = ? ORDER BY id DESC OFFSET 10000) run on every failure write.
    $pdo->exec('CREATE INDEX steward_audit_event_id_idx ON dbo.steward_audit(event, id)');
}
