<?php
namespace Kanboard\Plugin\Steward\Schema;

use PDO;

const VERSION = 4;

/** No foreign keys to users: audit rows outlive the accounts they mention. */
function version_1(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS steward_audit (
        id SERIAL PRIMARY KEY,
        created_at BIGINT NOT NULL,
        event VARCHAR(100) NOT NULL,
        actor_user_id INTEGER NULL,
        subject_user_id INTEGER NULL,
        switch_id VARCHAR(64) NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        details TEXT NULL
    )');
    $pdo->exec('CREATE INDEX steward_audit_created_at_idx ON steward_audit(created_at)');
    $pdo->exec('CREATE INDEX steward_audit_event_idx ON steward_audit(event)');
    $pdo->exec('CREATE INDEX steward_audit_switch_id_idx ON steward_audit(switch_id)');
    // Serves the login.failure cap query (WHERE event = ? ORDER BY id DESC OFFSET 10000) run on every failure write.
    $pdo->exec('CREATE INDEX steward_audit_event_id_idx ON steward_audit(event, id)');
}

/** Per-project page settings (spec §5.1–5.4). Core's API can't write this table, unlike project metadata. */
function version_2(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS steward_project_page (
        project_id INTEGER PRIMARY KEY REFERENCES projects(id) ON DELETE CASCADE,
        tagline VARCHAR(255) NOT NULL DEFAULT \'\',
        links TEXT NULL,
        panels TEXT NULL,
        public_panels TEXT NULL,
        is_public SMALLINT NOT NULL DEFAULT 0,
        is_listed SMALLINT NOT NULL DEFAULT 0,
        show_names SMALLINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0
    )');
}

/** One-time sign-in links. Only the hash is stored; created_by has no foreign key so a deleted admin's mints still count toward the rate limit, like audit rows. */
function version_3(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS steward_login_link (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash CHAR(64) NOT NULL UNIQUE,
        created_by INTEGER NULL,
        created_at BIGINT NOT NULL,
        expires_at BIGINT NOT NULL,
        used_at BIGINT NULL
    )');
    $pdo->exec('CREATE INDEX steward_login_link_created_by_idx ON steward_login_link(created_by, created_at)');
}

/** Decision 22: set once a link's first failed redeem is audited, so replaying a spent link writes no further rows. */
function version_4(PDO $pdo)
{
    $pdo->exec('ALTER TABLE steward_login_link ADD COLUMN failure_logged INTEGER NOT NULL DEFAULT 0');
}
