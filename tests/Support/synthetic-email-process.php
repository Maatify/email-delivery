<?php

declare(strict_types=1);

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO;
use Maatify\EmailDelivery\Synthetic\PdoSyntheticEmailStore;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (fread(STDIN, 1) !== 'g') {
    throw new RuntimeException('Synthetic process barrier was not released.');
}
$pdo = IsolatedEmailMysqlTestCase::connection();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
$store = new PdoSyntheticEmailStore($pdo, Factory::crypto(), new Factory(), 60);
if (($argv[1] ?? '') === 'record') {
    $store->record(new SyntheticEmailRequestDTO('fixture-consumer', 'user', null, 'synthetic@example.invalid', new EmailQueuePayloadDTO(['name' => 'fixture'], 'welcome', 'en'), 1));
} elseif (($argv[1] ?? '') === 'purge') {
    if ($store->purgeExpired(1) !== 1) {
        throw new RuntimeException('Concurrent cleanup exceeded or missed its bounded record.');
    }
} else {
    throw new RuntimeException('Unknown synthetic process operation.');
}
echo getmypid() . ':ok';
