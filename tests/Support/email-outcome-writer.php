<?php

declare(strict_types=1);

/**
 * Test-only child process: plays another worker that owns one in-flight row. It locks the row's primary key,
 * signals "locked", waits for "go", then records an outcome on the row and reports how that ended.
 * Usage: php email-outcome-writer.php <directory> <row id>
 */

use Tests\Support\IsolatedEmailMysqlTestCase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = (string) ($argv[1] ?? '');
$id = (int) ($argv[2] ?? 0);
$report = static function (string $name, string $value) use ($directory): void {
    // Written atomically: the parent only ever sees a complete file.
    file_put_contents("$directory/$name.tmp", $value);
    rename("$directory/$name.tmp", "$directory/$name");
};

$pdo = IsolatedEmailMysqlTestCase::connection();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
$pdo->beginTransaction();
$lock = $pdo->query("SELECT id FROM cd_email_queue WHERE id = $id FOR UPDATE");
if ($lock === false) {
    throw new RuntimeException('The row lock was not taken.');
}
$lock->fetchAll();
$report('locked', '1');

$deadline = microtime(true) + 20;
while (!is_file("$directory/go") && microtime(true) < $deadline) {
    usleep(10_000);
}
// Give the claimer time to reach this row while the primary key is held.
usleep(300_000);

try {
    $pdo->exec("UPDATE cd_email_queue SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id = $id");
    $pdo->commit();
    $report('result', 'ok');
} catch (PDOException $failure) {
    $report('result', (string) ($failure->errorInfo[1] ?? $failure->getMessage()));
}
