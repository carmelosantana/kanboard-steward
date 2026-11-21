<?php
// Loads the Steward schema dialect for the database the suite runs on: core's phpunit config defines DB_DRIVER
// (sqlite | mysql | postgres). Tests call \Kanboard\Plugin\Steward\Schema\version_N($pdo) as before.

$stewardSchemaDialect = [
    'mysql' => 'Mysql',
    'postgres' => 'Postgres',
][defined('DB_DRIVER') ? DB_DRIVER : 'sqlite'] ?? 'Sqlite';

require_once __DIR__.'/../../Schema/'.$stewardSchemaDialect.'.php';
unset($stewardSchemaDialect);

/** A table's column names in declared order, on any driver (PRAGMA table_info is SQLite-only). */
function steward_table_columns(PDO $pdo, string $table): array
{
    $st = $pdo->query('SELECT * FROM '.$table.' WHERE 1 = 0');
    $columns = [];
    for ($i = 0; $i < $st->columnCount(); $i++) {
        $columns[] = $st->getColumnMeta($i)['name'];
    }
    return $columns;
}
