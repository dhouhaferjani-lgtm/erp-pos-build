<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
if ($database === false || $database === '' || $username === false || $username === '') {
    fwrite(STDERR, "DB_DATABASE and DB_USERNAME are required.\n");
    exit(2);
}

$connection = sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '5432',
    $database,
    $username,
    getenv('DB_PASSWORD') ?: '',
);

$a = pg_connect($connection, PGSQL_CONNECT_FORCE_NEW);
$b = pg_connect($connection, PGSQL_CONNECT_FORCE_NEW);
if ($a === false || $b === false) {
    fwrite(STDERR, "PostgreSQL connection failed.\n");
    exit(2);
}

pg_query($a, 'DROP TABLE IF EXISTS wave3_lock_probe');
pg_query($a, 'CREATE TABLE wave3_lock_probe (id integer PRIMARY KEY)');
pg_query($a, 'INSERT INTO wave3_lock_probe VALUES (1), (2)');

foreach ([$a, $b] as $session) {
    pg_query($session, "SET deadlock_timeout = '100ms'");
    pg_query($session, 'BEGIN');
}

pg_query($a, 'SELECT * FROM wave3_lock_probe WHERE id = 1 FOR UPDATE');
pg_query($b, 'SELECT * FROM wave3_lock_probe WHERE id = 2 FOR UPDATE');
pg_send_query($a, 'SELECT * FROM wave3_lock_probe WHERE id = 2 FOR UPDATE');
pg_send_query($b, 'SELECT * FROM wave3_lock_probe WHERE id = 1 FOR UPDATE');

$resultA = pg_get_result($a);
$resultB = pg_get_result($b);
$stateA = $resultA === false ? 'NO_RESULT' : (pg_result_error_field($resultA, PGSQL_DIAG_SQLSTATE) ?: '00000');
$stateB = $resultB === false ? 'NO_RESULT' : (pg_result_error_field($resultB, PGSQL_DIAG_SQLSTATE) ?: '00000');

echo "session_a_sqlstate={$stateA}\n";
echo "session_b_sqlstate={$stateB}\n";

@pg_query($a, 'ROLLBACK');
@pg_query($b, 'ROLLBACK');
pg_query($a, 'DROP TABLE IF EXISTS wave3_lock_probe');

exit($stateA === '40P01' || $stateB === '40P01' ? 0 : 1);
