<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use Maatify\Crypto\Reversible\DTO\ReversibleCryptoMetadataDTO;
use Maatify\Crypto\Reversible\ReversibleCryptoAlgorithmEnum;
use Maatify\EmailDelivery\Exception\SyntheticEmailUnavailableException;
use Maatify\EmailDelivery\Exception\SyntheticEmailTransactionUnresolvedException;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO;
use Maatify\EmailDelivery\Synthetic\Internal\SyntheticEmailContexts;
use Maatify\EmailDelivery\Synthetic\PdoSyntheticEmailStore;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\Support\EmailQueueFaultPdo;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;
use Tests\Support\WorkerRecordingTransport;
use Throwable;

final class SyntheticEmailMysqlTest extends IsolatedEmailMysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DROP TABLE IF EXISTS cd_email_synthetic_requests');
        $sql = file_get_contents(dirname(__DIR__, 2) . '/schema/mysql/004_email_synthetic_requests.sql');
        self::assertIsString($sql);
        $this->pdo->exec($sql);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS cd_email_synthetic_requests');
        }
    }

    private function store(?PDO $pdo = null, int $retention = 60): PdoSyntheticEmailStore
    {
        return new PdoSyntheticEmailStore($pdo ?? $this->pdo, Factory::crypto(), new Factory(), $retention);
    }

    /** @param array<string, mixed> $context */
    private function request(array $context = ['name' => 'Synthetic secret']): SyntheticEmailRequestDTO
    {
        return new SyntheticEmailRequestDTO('fixture-consumer', 'user', '1', 'synthetic@example.invalid', new EmailQueuePayloadDTO($context, 'welcome', 'en'), 1);
    }

    private function worker(WorkerRecordingTransport $transport, ?PDO $pdo = null): EmailQueueWorker
    {
        return new EmailQueueWorker($pdo ?? $this->pdo, Factory::crypto(), new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', ['global_app_name' => 'Fixture'], false), $transport, new Factory(), new NullLogger());
    }

    public function testEncryptedInertRecordsCannotEnterWorkerEvenAfterRestartWithPositiveRealMailSensor(): void
    {
        $result = $this->store()->record($this->request());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $result->recordId);
        self::assertObjectNotHasProperty('jobId', $result);
        self::assertObjectNotHasProperty('queueState', $result);
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        $statement = $this->pdo->query('SELECT * FROM cd_email_synthetic_requests');
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        foreach (['recipient_encrypted', 'payload_encrypted', 'recipient_iv', 'recipient_tag', 'payload_iv', 'payload_tag', 'recipient_key_id', 'payload_key_id'] as $column) {
            self::assertIsString($row[$column]);
        }
        /** @var array<string, string> $row */
        self::assertStringNotContainsString('synthetic@example.invalid', (string) $row['recipient_encrypted']);
        self::assertStringNotContainsString('Synthetic secret', (string) $row['payload_encrypted']);
        $crypto = Factory::crypto();
        foreach (['recipient', 'payload'] as $role) {
            $cipher = (string) $row[$role . '_encrypted'];
            $key = (string) $row[$role . '_key_id'];
            $meta = new ReversibleCryptoMetadataDTO((string) $row[$role . '_iv'], (string) $row[$role . '_tag']);
            $real = $role === 'recipient' ? (new Factory())->emailQueueRecipient() : (new Factory())->emailQueuePayload();
            $domain = $role === 'recipient' ? SyntheticEmailContexts::recipient($real) : SyntheticEmailContexts::payload($real);
            $plain = $crypto->context($domain)->decrypt($cipher, $key, ReversibleCryptoAlgorithmEnum::AES_256_GCM, $meta);
            self::assertStringContainsString($role === 'recipient' ? 'synthetic@example.invalid' : 'Synthetic secret', $plain);
            $rejected = false;
            try {
                $crypto->context($real)->decrypt($cipher, $key, ReversibleCryptoAlgorithmEnum::AES_256_GCM, $meta);
            } catch (Throwable) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Real delivery domains must reject synthetic ciphertext.');
        }
        $transport = new WorkerRecordingTransport();
        $this->worker($transport)->processBatch();
        $this->worker($transport, self::connection())->processBatch();
        self::assertSame(0, $transport->calls);
        Factory::queue($this->pdo)->enqueueOnce(Factory::request(context: ['name' => 'Real fixture']));
        $this->worker($transport)->processBatch();
        self::assertSame(1, $transport->calls, 'Positive sensor: this worker really sends real accepted jobs.');
        $this->store()->record($this->request());
        $this->worker($transport, self::connection())->processBatch();
        self::assertSame(1, $transport->calls);
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testSyntheticOnlySchemaWorksWithoutRealQueueOrReceiptTables(): void
    {
        $this->pdo->exec('DROP TABLE cd_email_enqueue_receipts, cd_email_queue');
        $one = $this->store()->record($this->request());
        $two = $this->store()->record($this->request());
        self::assertNotSame($one->recordId, $two->recordId, 'Recording is append-only, not real keyed acceptance.');
        self::assertSame(0, $this->store()->purgeExpired());
    }

    public function testRetentionUsesDatabaseUtcAndCleanupIsBoundedIncludingExactExpiry(): void
    {
        $old = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $epoch = (new DateTimeImmutable('2030-01-01T12:00:00Z'))->getTimestamp();
            $this->pdo->exec('SET timestamp=' . $epoch);
            $store = $this->store();
            Factory::queue($this->pdo)->enqueueOnce(Factory::request(context: ['name' => 'Real fixture']));
            $first = $store->record($this->request());
            $store->record($this->request());
            self::assertSame('2030-01-01T12:00:00.000000Z', $first->recordedAtUtc);
            self::assertSame('2030-01-01T12:01:00.000000Z', $first->expiresAtUtc);
            self::assertSame(0, $store->purgeExpired());
            $this->pdo->exec('SET timestamp=' . ($epoch + 59));
            self::assertSame(0, $store->purgeExpired());
            $live = $store->record($this->request());
            $this->pdo->exec('SET timestamp=' . ($epoch + 60));
            self::assertSame(1, $store->purgeExpired(1));
            self::assertSame(1, $store->purgeExpired(1));
            self::assertSame(0, $store->purgeExpired(1));
            self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
            self::assertSame($live->recordId, self::scalar($this->pdo, 'SELECT record_id FROM cd_email_synthetic_requests'));
            self::assertSame('+00:00', self::scalar($this->pdo, 'SELECT @@SESSION.time_zone'));
        } finally {
            $this->pdo->exec('SET timestamp=0');
            date_default_timezone_set($old);
        }
    }

    public function testSnapshotDetachesNestedReferencesAndMutableDatesBeforeAnySql(): void
    {
        $value = 'original';
        $date = new DateTime('2030-01-01T15:00:00+03:00');
        $request = new SyntheticEmailRequestDTO('fixture-consumer', 'user', null, 'synthetic@example.invalid', new EmailQueuePayloadDTO(['nested' => ['value' => &$value]], 'welcome', 'en'), 1, scheduledAt: $date);
        $date->modify('+1 day');
        $pdo = new EmailQueueFaultPdo();
        $pdo->beforeExecuteHook = static function (string $sql) use (&$value): void {
            $value = 'changed';
        };
        $this->store($pdo)->record($request);
        $statement = $this->pdo->query('SELECT * FROM cd_email_synthetic_requests');
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('2030-01-01 12:00:00', $row['requested_scheduled_at']);
        $plain = Factory::crypto()->context(SyntheticEmailContexts::payload((new Factory())->emailQueuePayload()))->decrypt((string) $row['payload_encrypted'], (string) $row['payload_key_id'], ReversibleCryptoAlgorithmEnum::AES_256_GCM, new ReversibleCryptoMetadataDTO((string) $row['payload_iv'], (string) $row['payload_tag']));
        self::assertStringContainsString('original', $plain);
        self::assertStringNotContainsString('changed', $plain);
    }

    /** @return iterable<string, array{string}> */
    public static function badSchema(): iterable
    {
        yield 'nontransactional' => ['ALTER TABLE cd_email_synthetic_requests ENGINE=MyISAM'];
        yield 'missing expiry index' => ['ALTER TABLE cd_email_synthetic_requests DROP INDEX idx_email_synthetic_expiry'];
        yield 'nonbinary identity' => ['ALTER TABLE cd_email_synthetic_requests MODIFY record_id CHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL'];
        yield 'plaintext column type' => ['ALTER TABLE cd_email_synthetic_requests MODIFY payload_encrypted LONGTEXT NOT NULL'];
        yield 'trigger into queue' => ["CREATE TRIGGER synthetic_unsafe AFTER INSERT ON cd_email_synthetic_requests FOR EACH ROW SET @fixture_trigger=1"];
    }

    #[DataProvider('badSchema')]
    public function testIncompatibleSchemaFailsBeforeWriting(string $ddl): void
    {
        $this->pdo->exec($ddl);
        try {
            $this->store()->record($this->request());
            self::fail('Incompatible schemas must fail closed.');
        } catch (SyntheticEmailUnavailableException) {
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConnections(): iterable
    {
        yield 'caller transaction' => ['transaction'];
        yield 'autocommit off' => ['autocommit'];
        yield 'non UTC' => ['timezone'];
        yield 'silent errors' => ['silent'];
    }

    #[DataProvider('invalidConnections')]
    public function testCallerStateIsNotConsumedOrReconfigured(string $mode): void
    {
        if ($mode === 'transaction') {
            $this->pdo->beginTransaction();
        } elseif ($mode === 'autocommit') {
            $this->pdo->exec('SET SESSION autocommit=0');
        } elseif ($mode === 'timezone') {
            $this->pdo->exec("SET SESSION time_zone='+02:00'");
        } else {
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        }
        foreach (['record', 'purge'] as $operation) {
            try {
                $operation === 'record' ? $this->store()->record($this->request()) : $this->store()->purgeExpired();
                self::fail('Invalid connection must be rejected.');
            } catch (SyntheticEmailUnavailableException) {
                self::assertSame($mode === 'transaction', $this->pdo->inTransaction());
            }
        }
        if ($mode === 'autocommit') {
            self::assertSame('0', self::scalar($this->pdo, 'SELECT @@SESSION.autocommit'));
        }
        if ($mode === 'timezone') {
            self::assertSame('+02:00', self::scalar($this->pdo, 'SELECT @@SESSION.time_zone'));
        }
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
    }

    public function testValidationPrecedesSqlAndCleanupBoundsAreExplicit(): void
    {
        $pdo = new EmailQueueFaultPdo();
        $store = $this->store($pdo);
        foreach ([['value' => INF], ['value' => new DateTimeImmutable()], ['value' => str_repeat('x', 1048577)]] as $context) {
            try {
                $store->record($this->request($context));
                self::fail('Invalid context must fail before SQL.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $pdo->beginCalls);
            }
        }
        foreach ([0, -1, 1001] as $limit) {
            try {
                $store->purgeExpired($limit);
                self::fail('Invalid limit must fail.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $pdo->beginCalls);
            }
        }
        foreach ([0, -1, 2147483648] as $retention) {
            try {
                $this->store($pdo, $retention);
                self::fail('Retention must be explicit and valid.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, $pdo->beginCalls);
            }
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function faults(): iterable
    {
        foreach (['begin_false', 'begin_throw', 'begin_false_active', 'begin_throw_active', 'commit_false_before', 'commit_throw_before', 'commit_false_after', 'commit_throw_after', 'commit_true_active', 'settings_false', 'settings_throw', 'attribute_throw', 'synthetic_false', 'synthetic_throw', 'synthetic_read_false', 'synthetic_read_throw', 'synthetic_prepare_false', 'synthetic_prepare_throw'] as $mode) {
            yield $mode => [$mode, str_starts_with($mode, 'commit_') || str_ends_with($mode, '_active'), str_ends_with($mode, '_after')];
        }
    }

    #[DataProvider('faults')]
    public function testFaultsNeverReturnSuccessOrTouchRealMail(string $mode, bool $unresolved, bool $committed): void
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = $mode;
        try {
            $this->store($pdo)->record($this->request());
            self::fail('Fault must not produce a successful record result.');
        } catch (SyntheticEmailUnavailableException $failure) {
            self::assertSame($unresolved, $failure instanceof SyntheticEmailTransactionUnresolvedException);
            self::assertSame($committed ? '1' : '0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
            if (str_starts_with($mode, 'synthetic_')) {
                self::assertSame(1, $pdo->executeFaults, 'The injection must hit the actual synthetic insert.');
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->mode = 'none';
                $pdo->rollBack();
            }
        }
        if ($committed) {
            // Unknown commit is not exactly-once: explicit retry on a fresh connection appends an inert row.
            $this->store(self::connection())->record($this->request());
            self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        }
    }

    public function testReadbackFailureRollsBackAndRollbackFailureIsUnresolved(): void
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->afterExecuteHook = static function (string $sql) use ($pdo): void {
            if (str_starts_with($sql, 'INSERT INTO cd_email_synthetic_requests')) {
                $pdo->exec('DELETE FROM cd_email_synthetic_requests');
            }
        };
        try {
            $this->store($pdo)->record($this->request());
            self::fail('Missing inserted row must fail readback.');
        } catch (SyntheticEmailUnavailableException $failure) {
            self::assertNotInstanceOf(SyntheticEmailTransactionUnresolvedException::class, $failure);
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        }
        $pdo->afterExecuteHook = null;
        $pdo->mode = 'synthetic_throw';
        $pdo->failRollback = true;
        try {
            $this->store($pdo)->record($this->request());
            self::fail('Failed rollback must be unresolved.');
        } catch (SyntheticEmailTransactionUnresolvedException) {
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->failRollback = false;
            $pdo->rollBack();
        }
    }

    public function testCleanupCannotReportSuccessAfterCommitResponseIsLost(): void
    {
        $this->store()->record($this->request());
        $this->pdo->exec("UPDATE cd_email_synthetic_requests SET expires_at='2000-01-01 00:00:00'");
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = 'commit_throw_after';
        try {
            $this->store($pdo)->purgeExpired();
            self::fail('Unknown cleanup commit must not report a successful count.');
        } catch (SyntheticEmailTransactionUnresolvedException) {
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        }
    }

    public function testIndependentProcessesAppendDistinctRecordsAndConcurrentCleanupRemainsBounded(): void
    {
        $this->runProcesses('record', 4);
        self::assertSame('4', self::scalar($this->pdo, 'SELECT COUNT(DISTINCT record_id) FROM cd_email_synthetic_requests'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        $this->pdo->exec("UPDATE cd_email_synthetic_requests SET expires_at='2000-01-01 00:00:00'");
        $this->runProcesses('purge', 2);
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
    }

    private function runProcesses(string $operation, int $count): void
    {
        /** @var list<array{resource, array<int, resource>}> $children */
        $children = [];
        try {
            for ($i = 0; $i < $count; ++$i) {
                $child = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/synthetic-email-process.php', $operation], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($child);
                $children[] = [$child, $pipes];
            }
            foreach ($children as [$child, $pipes]) {
                fwrite($pipes[0], 'g');
                fclose($pipes[0]);
            }
            $pids = [];
            foreach ($children as [$child, $pipes]) {
                stream_set_timeout($pipes[1], 20);
                $out = stream_get_contents($pipes[1]);
                self::assertIsString($out);
                self::assertMatchesRegularExpression('/\A[0-9]+:ok\z/', $out);
                $pids[] = explode(':', $out)[0];
                self::assertSame('', stream_get_contents($pipes[2]));
            }
            self::assertCount($count, array_unique($pids), 'Independent PHP processes participate.');
        } finally {
            foreach ($children as [$child, $pipes]) {
                if (proc_get_status($child)['running']) {
                    proc_terminate($child);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($child);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function cleanupFailures(): iterable
    {
        foreach (['synthetic_delete_false', 'synthetic_delete_throw', 'synthetic_prepare_false', 'synthetic_prepare_throw'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('cleanupFailures')]
    public function testCleanupStatementFailureRollsBackWithoutDeleting(string $mode): void
    {
        $this->store()->record($this->request());
        $this->pdo->exec("UPDATE cd_email_synthetic_requests SET expires_at='2000-01-01 00:00:00'");
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = $mode;
        try {
            $this->store($pdo)->purgeExpired(1);
            self::fail('Cleanup failure must not report success.');
        } catch (SyntheticEmailUnavailableException $failure) {
            self::assertNotInstanceOf(SyntheticEmailTransactionUnresolvedException::class, $failure);
            self::assertSame(1, $pdo->executeFaults);
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertFalse($pdo->inTransaction());
            self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        }
    }
}
