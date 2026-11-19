<?php
namespace Kanboard\Plugin\Steward\Schema;

use PDO;

const VERSION = 1;

/** No foreign keys to users: audit rows outlive the accounts they mention. */
function version_1(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS steward_audit (
        id INTEGER PRIMARY KEY,
        created_at INTEGER NOT NULL,
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
}
