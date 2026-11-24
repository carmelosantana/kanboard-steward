<?php
namespace Kanboard\Plugin\Steward\Schema;

use PDO;

const VERSION = 4;

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

/** Per-project page settings (spec §5.1–5.4). Core's API can't write this table, unlike project metadata. */
function version_2(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE dbo.steward_project_page (
            project_id int PRIMARY KEY REFERENCES dbo.projects(id) ON DELETE CASCADE
          , tagline nvarchar(255) NOT NULL DEFAULT \'\'
          , links nvarchar(max) NULL
          , panels nvarchar(max) NULL
          , public_panels nvarchar(max) NULL
          , is_public smallint NOT NULL DEFAULT 0
          , is_listed smallint NOT NULL DEFAULT 0
          , show_names smallint NOT NULL DEFAULT 0
          , updated_at bigint NOT NULL DEFAULT 0
        );
    ');
}

/** One-time sign-in links. Only the hash is stored; created_by has no foreign key so a deleted admin's mints still count toward the rate limit, like audit rows. */
function version_3(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE dbo.steward_login_link (
            id int identity PRIMARY KEY
          , user_id int NOT NULL REFERENCES dbo.users(id) ON DELETE CASCADE
          , token_hash char(64) NOT NULL UNIQUE
          , created_by int NULL
          , created_at bigint NOT NULL
          , expires_at bigint NOT NULL
          , used_at bigint NULL
        );
    ');
    $pdo->exec('CREATE INDEX steward_login_link_created_by_idx ON dbo.steward_login_link(created_by, created_at)');
}

/** Decision 22: set once a link's first failed redeem is audited, so replaying a spent link writes no further rows. */
function version_4(PDO $pdo)
{
    $pdo->exec('ALTER TABLE dbo.steward_login_link ADD failure_logged int NOT NULL DEFAULT 0');
}
