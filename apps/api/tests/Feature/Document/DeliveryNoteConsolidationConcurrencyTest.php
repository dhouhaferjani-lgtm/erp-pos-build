<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteAlreadyClaimedException;
use App\Modules\Document\Domain\Exceptions\DeliveryNoteBatchValidationException;
use App\Modules\Document\Domain\Services\Billing\DeliveryNoteBillingConcurrencyRetrier;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class DeliveryNoteConsolidationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $clonedConnections = [];

    /** @var list<int> */
    private array $ownedChildPids = [];

    private Company $company;

    private Partner $partner;

    private Product $product;

    /** @var list<string> */
    private array $resultFiles = [];

    /** @var array{int, int} */
    private array $sequenceYears;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET lock_timeout = '10s'");
        DB::statement("SET statement_timeout = '30s'");

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);
        Location::factory()->create([
            'company_id' => $this->company->id,
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $year = (int) date('Y');
        $this->sequenceYears = [$year, $year + 1];
        foreach ($this->sequenceYears as $sequenceYear) {
            DocumentSequence::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'type' => DocumentType::Invoice->value,
                'year' => $sequenceYear,
                'last_number' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ownedChildPids as $pid) {
            $this->terminateAndReap($pid);
        }

        foreach ($this->clonedConnections as $name) {
            try {
                $connection = DB::connection($name);
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                // The process may have disconnected it already.
            }
            DB::purge($name);
            config(["database.connections.{$name}" => null]);
        }
        $this->clonedConnections = [];

        foreach ($this->resultFiles as $resultFile) {
            if (is_file($resultFile)) {
                unlink($resultFile);
            }
        }
        $this->resultFiles = [];

        if (DB::getDriverName() === 'pgsql' && isset($this->company)) {
            DB::table('delivery_note_billing_marks')->where('company_id', $this->company->id)->delete();
            $documentIds = DB::table('documents')->where('company_id', $this->company->id)->pluck('id');
            DB::table('stored_events')->whereIn('aggregate_uuid', $documentIds)->delete();
            DB::table('audit_events')->where('tenant_id', $this->tenant->id)->delete();
            DB::table('documents')->where('company_id', $this->company->id)->delete();
            DB::table('document_sequences')->where('company_id', $this->company->id)->delete();
            DB::table('locations')->where('company_id', $this->company->id)->delete();
            DB::table('products')->where('company_id', $this->company->id)->delete();
            DB::table('partners')->where('company_id', $this->company->id)->delete();
            DB::table('companies')->where('id', $this->company->id)->delete();
            DB::table('tenants')->where('id', $this->tenant->id)->delete();
            DB::statement('RESET lock_timeout');
            DB::statement('RESET statement_timeout');
            app(CompanyContext::class)->clear();
        }

        parent::tearDown();
    }

    /** @return list<string|null> */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    public function test_real_query_pdo_nested_and_deadlock_exception_shapes_are_retried_at_most_twice(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Billing-claim concurrency policy requires PostgreSQL.');
        }

        $faults = [
            'direct QueryException' => fn (): bool => DB::statement("DO \$\$ BEGIN RAISE EXCEPTION 'direct fault' USING ERRCODE = '40001'; END \$\$"),
            'raw PDOException' => fn (): int|false => DB::connection()->getPdo()->exec("DO \$\$ BEGIN RAISE EXCEPTION 'pdo fault' USING ERRCODE = '40P01'; END \$\$"),
            'nested wrapper' => function (): never {
                try {
                    DB::connection()->getPdo()->exec("DO \$\$ BEGIN RAISE EXCEPTION 'wrapped fault' USING ERRCODE = '40001'; END \$\$");
                } catch (PDOException $exception) {
                    throw new RuntimeException('application wrapper', 0, $exception);
                }

                throw new RuntimeException('The wrapped PostgreSQL fault did not fire.');
            },
            'Laravel DeadlockException' => fn (): mixed => DB::transaction(
                fn (): bool => DB::statement("DO \$\$ BEGIN RAISE EXCEPTION 'deadlock detected by nested transaction probe' USING ERRCODE = '40P01'; END \$\$"),
            ),
        ];

        foreach ($faults as $shape => $fault) {
            $attempts = 0;
            $retrier = new DeliveryNoteBillingConcurrencyRetrier(DB::connection());

            try {
                $retrier->run('00000000-0000-0000-0000-000000000001', function () use (&$attempts, $fault): never {
                    $attempts++;
                    $fault();

                    throw new RuntimeException('The PostgreSQL fault did not fire.');
                });
                $this->fail("{$shape} must be translated after the finite retry budget.");
            } catch (DeliveryNoteAlreadyClaimedException $exception) {
                $this->assertSame('00000000-0000-0000-0000-000000000001', $exception->deliveryNoteId);
                if ($shape === 'Laravel DeadlockException') {
                    $this->assertInstanceOf(DeadlockException::class, $exception->getPrevious());
                }
            }

            $this->assertSame(3, $attempts, "{$shape} must receive two retries and no fourth attempt.");
        }
    }

    public function test_commit_time_serialization_failure_retries_in_a_fresh_transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Billing-claim concurrency policy requires PostgreSQL.');
        }

        DB::statement('CREATE TEMP SEQUENCE dn_retry_commit_fault_sequence');
        DB::statement('CREATE TEMP TABLE dn_retry_commit_fault_probe (id integer)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pg_temp.dn_retry_raise_once_at_commit()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF nextval('pg_temp.dn_retry_commit_fault_sequence') = 1 THEN
        RAISE EXCEPTION 'forced commit serialization failure' USING ERRCODE = '40001';
    END IF;
    RETURN NEW;
END;
$$
SQL);
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER dn_retry_raise_once_at_commit
AFTER INSERT ON dn_retry_commit_fault_probe
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION pg_temp.dn_retry_raise_once_at_commit()
SQL);

        $attempts = 0;
        $transactionIds = [];
        $retrier = new DeliveryNoteBillingConcurrencyRetrier(DB::connection());

        $result = $retrier->run('00000000-0000-0000-0000-000000000002', function () use (&$attempts, &$transactionIds): string {
            $attempts++;
            $transactionIds[] = (string) DB::selectOne('SELECT txid_current() AS id')->id;
            DB::table('dn_retry_commit_fault_probe')->insert(['id' => $attempts]);

            return 'converted';
        });

        $this->assertSame('converted', $result);
        $this->assertSame(2, $attempts);
        $this->assertCount(2, array_unique($transactionIds), 'A commit-time retry must begin a fresh PostgreSQL transaction.');
        $this->assertSame([2], DB::table('dn_retry_commit_fault_probe')->pluck('id')->all());
    }

    public function test_unrelated_real_database_error_is_not_retried_or_translated(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Billing-claim concurrency policy requires PostgreSQL.');
        }

        $attempts = 0;
        $retrier = new DeliveryNoteBillingConcurrencyRetrier(DB::connection());

        try {
            $retrier->run('00000000-0000-0000-0000-000000000003', function () use (&$attempts): never {
                $attempts++;
                DB::statement("DO \$\$ BEGIN RAISE EXCEPTION 'unrelated fault' USING ERRCODE = '23503'; END \$\$");

                throw new RuntimeException('The PostgreSQL fault did not fire.');
            });
            $this->fail('An unrelated database failure must propagate unchanged.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
        }

        $this->assertSame(1, $attempts);
    }

    public function test_sales_order_commit_exhaustion_preserves_infrastructure_error_without_phantom_422(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Billing-claim concurrency policy requires PostgreSQL.');
        }

        $order = $this->salesOrder();
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION pg_temp.dn_retry_raise_on_document_commit()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            RAISE EXCEPTION 'forced document commit serialization failure' USING ERRCODE = '40001';
        END;
        $$
        SQL);
        DB::statement(<<<'SQL'
        CREATE CONSTRAINT TRIGGER dn_retry_raise_on_document_commit
        AFTER INSERT ON documents
        DEFERRABLE INITIALLY DEFERRED
        FOR EACH ROW EXECUTE FUNCTION pg_temp.dn_retry_raise_on_document_commit()
        SQL);

        try {
            $response = $this->withoutMiddleware()
                ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");
        } finally {
            $pdo = DB::connection()->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            DB::statement('DROP TRIGGER IF EXISTS dn_retry_raise_on_document_commit ON documents');
        }

        $payload = $response->json();
        $response->assertStatus(422);
        $this->assertIsString($payload['error']);
        $this->assertStringContainsString('SQLSTATE[40001]', $payload['error']);
        $this->assertStringNotContainsString('Delivery note ', $payload['error']);
        $this->assertSame(0, Document::query()->where('company_id', $this->company->id)->where('type', DocumentType::DeliveryNote)->count());
        $this->assertSame(0, Document::query()->where('company_id', $this->company->id)->where('type', DocumentType::Invoice)->count());
        $this->assertSame(0, DB::table('delivery_note_billing_marks')->where('company_id', $this->company->id)->count());
    }

    public function test_later_pre_resolution_retries_do_not_reuse_a_rolled_back_delivery_note_identity(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Billing-claim concurrency policy requires PostgreSQL.');
        }

        $order = $this->salesOrder();
        DB::statement('CREATE TEMP SEQUENCE dn_retry_attempt_local_sequence');
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION pg_temp.dn_retry_fail_first_document_commit()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            IF nextval('pg_temp.dn_retry_attempt_local_sequence') = 1 THEN
                RAISE EXCEPTION 'forced first-attempt commit failure' USING ERRCODE = '40001';
            END IF;
            RETURN NEW;
        END;
        $$
        SQL);
        DB::statement(<<<'SQL'
        CREATE CONSTRAINT TRIGGER dn_retry_fail_first_document_commit
        AFTER INSERT ON documents
        DEFERRABLE INITIALLY DEFERRED
        FOR EACH ROW EXECUTE FUNCTION pg_temp.dn_retry_fail_first_document_commit()
        SQL);

        $preResolutionFaults = 0;
        DB::connection()->beforeExecuting(static function (
            string $query,
            array $bindings,
            Connection $connection,
        ) use (&$preResolutionFaults): void {
            $isSalesOrderLock = str_contains(strtolower($query), 'from "documents"')
                && str_contains(strtolower($query), 'for update')
                && in_array(DocumentType::SalesOrder->value, array_map('strval', $bindings), true);
            if (! $isSalesOrderLock) {
                return;
            }

            $sequenceWasCalled = (bool) $connection->getPdo()
                ->query('SELECT is_called FROM pg_temp.dn_retry_attempt_local_sequence')
                ->fetchColumn();
            if (! $sequenceWasCalled) {
                return;
            }

            $preResolutionFaults++;
            $connection->getPdo()->exec(
                "DO \$\$ BEGIN RAISE EXCEPTION 'forced pre-resolution retry fault' USING ERRCODE = '40001'; END \$\$",
            );
        });

        try {
            $response = $this->withoutMiddleware()
                ->postJson("/api/v1/orders/{$order->id}/convert-to-invoice");
        } finally {
            $pdo = DB::connection()->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            DB::statement('DROP TRIGGER IF EXISTS dn_retry_fail_first_document_commit ON documents');
            DB::purge();
            DB::reconnect();
            DB::statement("SET lock_timeout = '10s'");
            DB::statement("SET statement_timeout = '30s'");
        }

        $payload = $response->json();
        $response->assertStatus(422);
        $this->assertSame(2, $preResolutionFaults, 'Attempts two and three must fail before resolving any delivery note.');
        $this->assertIsString($payload['error']);
        $this->assertStringContainsString('forced pre-resolution retry fault', $payload['error']);
        $this->assertStringNotContainsString('Delivery note ', $payload['error']);
        $this->assertSame(0, Document::query()->where('company_id', $this->company->id)->where('type', DocumentType::DeliveryNote)->count());
        $this->assertSame(0, Document::query()->where('company_id', $this->company->id)->where('type', DocumentType::Invoice)->count());
        $this->assertSame(0, DB::table('delivery_note_billing_marks')->where('company_id', $this->company->id)->count());
    }

    public function test_overlapping_consolidations_serialize_at_the_seeded_sequence_barrier(): void
    {
        $this->requireProcessPostgres();
        $first = $this->deliveryNote('DN-RACE-A');
        $second = $this->deliveryNote('DN-RACE-B');
        $ids = [$first->id, $second->id];

        $results = $this->raceAtInvoiceSequenceBarrier(
            ['lane' => 'consolidation', 'source_id' => $first->id, 'delivery_note_ids' => $ids],
            ['lane' => 'consolidation', 'source_id' => $second->id, 'delivery_note_ids' => array_reverse($ids)],
        );

        $this->assertSame('success', $results[0]['outcome']);
        $this->assertSame('refused', $results[1]['outcome']);
        $this->assertSame($results[0]['invoice_id'], $results[1]['winner_invoice_id']);
        $this->assertSame(DeliveryNoteBillingLane::Consolidation->value, $results[1]['winner_lane']);
        $this->assertFinalisedExactlyOnce($ids, DeliveryNoteBillingLane::Consolidation);
    }

    public function test_consolidation_wins_against_the_sales_order_lane_without_deadlock(): void
    {
        $this->assertCrossLaneWinner('consolidation');
    }

    public function test_sales_order_wins_against_consolidation_without_deadlock(): void
    {
        $this->assertCrossLaneWinner('sales_order');
    }

    public function test_same_order_auto_create_path_serializes_without_extra_draft_or_number(): void
    {
        $this->requireProcessPostgres();
        $order = $this->salesOrder();

        $results = $this->raceAtInvoiceSequenceBarrier(
            ['lane' => 'sales_order', 'source_id' => $order->id, 'delivery_note_ids' => []],
            ['lane' => 'sales_order', 'source_id' => $order->id, 'delivery_note_ids' => []],
        );

        $this->assertSame('success', $results[0]['outcome']);
        $this->assertSame('error', $results[1]['outcome']);
        $this->assertSame(RuntimeException::class, $results[1]['exception']);
        $this->assertSame('Sales order has already been fully invoiced', $results[1]['message']);
        $this->assertNull($results[1]['sqlstate']);
        $this->assertSame(1, Document::query()->where('company_id', $this->company->id)->where('type', DocumentType::Invoice)->count());
        $this->assertSame(1, Document::query()->where('company_id', $this->company->id)->where('type', DocumentType::DeliveryNote)->count());
        $this->assertSame(1, (int) DB::table('document_sequences')
            ->where('company_id', $this->company->id)
            ->where('type', DocumentType::DeliveryNote->value)
            ->sum('last_number'));
        $this->assertSame(1, $this->invoiceSequenceAggregate());
        $this->assertStringNotContainsString('40P01', json_encode($results, JSON_THROW_ON_ERROR));
    }

    public function test_failed_backend_pid_publication_is_bounded_and_reaps_the_owned_child(): void
    {
        $this->requireProcessPostgres();
        $startedAt = microtime(true);

        try {
            $this->spawnConversion(
                ['lane' => 'consolidation', 'source_id' => Str::uuid()->toString(), 'delivery_note_ids' => []],
                publishBackendPid: false,
                publicationTimeoutSeconds: 0.1,
            );
            $this->fail('A child that does not publish its backend pid must fail startup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Child did not publish its PostgreSQL backend pid within the bounded deadline.', $exception->getMessage());
        }

        $this->assertLessThan(2.0, microtime(true) - $startedAt);
        $this->assertSame([], $this->ownedChildPids, 'The parent must reap a child even when startup publication fails.');
    }

    public function test_integrity_regression_net_detects_both_marker_payload_disagreement_directions(): void
    {
        $this->requireProcessPostgres();
        $valid = $this->deliveryNote('DN-INTEGRITY-VALID');
        app(DocumentConverterRegistry::class)->convert($valid, DocumentType::Invoice, [
            'delivery_note_ids' => [$valid->id],
        ]);
        $this->assertSame([
            'payload_without_marker' => [],
            'marker_without_agreeing_payload' => [],
        ], $this->billingIntegrityMismatches());

        $invoice = Document::query()
            ->where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice)
            ->sole();
        $payloadOnly = $this->deliveryNote('DN-INTEGRITY-PAYLOAD-ONLY');
        $payloadOnly->update(['payload' => [
            'invoiced_at' => now()->toIso8601String(),
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]]);
        $markerOnly = $this->deliveryNote('DN-INTEGRITY-MARKER-ONLY');
        DB::table('delivery_note_billing_marks')->insert([
            'delivery_note_id' => $markerOnly->id,
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
            'invoiced_at' => now(),
            'company_id' => $this->company->id,
        ]);

        $mismatches = $this->billingIntegrityMismatches();
        $this->assertSame([$payloadOnly->id], $mismatches['payload_without_marker']);
        $this->assertSame([$markerOnly->id], $mismatches['marker_without_agreeing_payload']);

        try {
            $this->assertBillingIntegrity();
            $this->fail('The integrity regression net must fail for a stamped delivery note without a marker.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('regression net found disagreement', $failure->getMessage());
        }
    }

    private function assertCrossLaneWinner(string $winnerLane): void
    {
        $this->requireProcessPostgres();
        $order = $this->salesOrder();
        $deliveryNote = $this->linkedDeliveryNote($order);
        $consolidation = [
            'lane' => 'consolidation',
            'source_id' => $deliveryNote->id,
            'delivery_note_ids' => [$deliveryNote->id],
        ];
        $salesOrder = [
            'lane' => 'sales_order',
            'source_id' => $order->id,
            'delivery_note_ids' => [$deliveryNote->id],
        ];

        $first = $winnerLane === 'consolidation' ? $consolidation : $salesOrder;
        $second = $winnerLane === 'consolidation' ? $salesOrder : $consolidation;
        $results = $this->raceAtInvoiceSequenceBarrier($first, $second);

        $expectedLane = $winnerLane === 'consolidation'
            ? DeliveryNoteBillingLane::Consolidation
            : DeliveryNoteBillingLane::OrderConversion;
        $this->assertSame('success', $results[0]['outcome']);
        $this->assertSame('refused', $results[1]['outcome']);
        $this->assertSame($results[0]['invoice_id'], $results[1]['winner_invoice_id']);
        $this->assertSame($expectedLane->value, $results[1]['winner_lane']);
        $this->assertStringNotContainsString('40P01', json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertFinalisedExactlyOnce([$deliveryNote->id], $expectedLane);
    }

    /**
     * @param  array{lane: string, source_id: string, delivery_note_ids: list<string>}  $first
     * @param  array{lane: string, source_id: string, delivery_note_ids: list<string>}  $second
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function raceAtInvoiceSequenceBarrier(array $first, array $second): array
    {
        $control = $this->cloneConnection('dn_billing_control_'.Str::lower(Str::random(8)));
        $children = [];
        $control->beginTransaction();
        $controlBackendPid = (int) $control->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $locked = $control->select(
            'SELECT id FROM document_sequences WHERE company_id = ? AND type = ? AND year IN (?, ?) FOR UPDATE',
            [$this->company->id, DocumentType::Invoice->value, ...$this->sequenceYears],
        );
        $this->assertCount(2, $locked, 'The deterministic barrier must lock both reachable invoice years.');

        try {
            $children[] = $this->spawnConversion($first);
            $this->startChild($children[0]);
            $activity = $this->waitForLock($children[0]['backend_pid'], 'document_sequences');
            $this->assertSame('Lock', $activity->wait_event_type);
            $this->assertStringContainsString('document_sequences', strtolower((string) $activity->query));

            $children[] = $this->spawnConversion($second);
            $this->startChild($children[1]);
            $this->waitForProductionOwnerBlock(
                $children[1]['backend_pid'],
                $children[0]['backend_pid'],
                $controlBackendPid,
            );
            $this->assertSame(0, pcntl_waitpid($children[1]['pid'], $status, WNOHANG), 'Process B must remain unfinished while A owns or awaits earlier locks.');

            $control->commit();

            return [
                $this->reapChild($children[0]),
                $this->reapChild($children[1]),
            ];
        } finally {
            if ($control->transactionLevel() > 0) {
                $control->rollBack();
            }
            foreach ($children as &$child) {
                if (is_resource($child['start_socket'])) {
                    fclose($child['start_socket']);
                }
                if (! $child['reaped']) {
                    $this->terminateAndReap($child['pid']);
                    $child['reaped'] = true;
                }
            }
            unset($child);
        }
    }

    /**
     * @param  array{lane: string, source_id: string, delivery_note_ids: list<string>}  $job
     * @return array{pid: int, backend_pid: int, start_socket: resource, result_file: string, reaped: bool}
     */
    private function spawnConversion(
        array $job,
        bool $publishBackendPid = true,
        float $publicationTimeoutSeconds = 5.0,
    ): array {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;
        $resultFile = tempnam(sys_get_temp_dir(), 'dn-billing-race-');
        $this->assertIsString($resultFile);
        $this->resultFiles[] = $resultFile;

        $pid = pcntl_fork();
        if ($pid < 0) {
            $this->fail('Could not fork the billing conversion child.');
        }
        if ($pid === 0) {
            fclose($parentSocket);
            DB::disconnect();

            if (! $publishBackendPid) {
                pcntl_signal(SIGTERM, SIG_IGN);
                while (true) {
                    usleep(100_000);
                }
            }

            try {
                DB::reconnect();
                DB::statement("SET lock_timeout = '8s'");
                DB::statement("SET statement_timeout = '30s'");
                app(CompanyContext::class)->clear();
                app(CompanyContext::class)->setCompanyId($this->company->id);
                $backendPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                fwrite($childSocket, $backendPid."\n");
                stream_set_timeout($childSocket, 5);
                if (fread($childSocket, 1) !== '1') {
                    throw new RuntimeException('Child did not receive its bounded conversion start signal.');
                }
                fclose($childSocket);

                $source = Document::query()->findOrFail($job['source_id']);
                $options = $job['lane'] === 'consolidation'
                    ? ['delivery_note_ids' => $job['delivery_note_ids']]
                    : [];
                $invoice = app(DocumentConverterRegistry::class)->convert($source, DocumentType::Invoice, $options);
                $payload = [
                    'outcome' => 'success',
                    'invoice_id' => $invoice->id,
                    'line_count' => $invoice->lines()->count(),
                ];
            } catch (DeliveryNoteAlreadyClaimedException|DeliveryNoteBatchValidationException $exception) {
                $deliveryNoteId = $exception instanceof DeliveryNoteAlreadyClaimedException
                    ? $exception->deliveryNoteId
                    : (string) ($exception->documents[0]['id'] ?? '');
                $winner = DB::table('delivery_note_billing_marks')->where('delivery_note_id', $deliveryNoteId)->first();
                $payload = [
                    'outcome' => 'refused',
                    'exception' => $exception::class,
                    'delivery_note_id' => $deliveryNoteId,
                    'winner_invoice_id' => $winner?->invoice_id,
                    'winner_lane' => $winner?->invoiced_via,
                ];
            } catch (Throwable $exception) {
                $payload = [
                    'outcome' => 'error',
                    'exception' => $exception::class,
                    'sqlstate' => $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents($resultFile, json_encode($payload, JSON_THROW_ON_ERROR));
            DB::disconnect();
            exit(0);
        }

        $this->assertGreaterThan(0, $pid, 'Only the parent may return from spawnConversion.');

        fclose($childSocket);
        $this->ownedChildPids[] = $pid;

        try {
            $backendPid = $this->awaitBackendPid($parentSocket, $publicationTimeoutSeconds);
        } catch (Throwable $exception) {
            fclose($parentSocket);
            $this->terminateAndReap($pid);

            throw $exception;
        }

        return [
            'pid' => $pid,
            'backend_pid' => $backendPid,
            'start_socket' => $parentSocket,
            'result_file' => $resultFile,
            'reaped' => false,
        ];
    }

    /** @param array{start_socket: resource} $child */
    private function startChild(array $child): void
    {
        fwrite($child['start_socket'], '1');
    }

    private function waitForLock(int $backendPid, ?string $queryFragment = null): object
    {
        $deadline = microtime(true) + 5.0;
        do {
            $activity = DB::selectOne(
                'SELECT wait_event_type, wait_event, query FROM pg_stat_activity WHERE pid = ?',
                [$backendPid],
            );
            if ($activity !== null
                && $activity->wait_event_type === 'Lock'
                && ($queryFragment === null || str_contains(strtolower((string) $activity->query), strtolower($queryFragment)))) {
                return $activity;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail("PostgreSQL backend {$backendPid} did not enter the required bounded lock wait.");
    }

    private function waitForProductionOwnerBlock(int $backendPid, int $ownerBackendPid, int $controlBackendPid): object
    {
        $deadline = microtime(true) + 5.0;
        do {
            $activity = DB::selectOne(<<<'SQL'
SELECT
    activity.wait_event_type,
    activity.wait_event,
    activity.query,
    ? = ANY(pg_blocking_pids(activity.pid)) AS blocked_by_owner,
    ? = ANY(pg_blocking_pids(activity.pid)) AS blocked_by_control,
    EXISTS (
        SELECT 1
        FROM pg_locks relation_lock
        JOIN pg_class relation ON relation.oid = relation_lock.relation
        WHERE relation_lock.pid = activity.pid
          AND relation.relname = 'documents'
          AND relation_lock.mode = 'RowShareLock'
          AND relation_lock.granted
    ) AS holds_documents_relation_lock,
    EXISTS (
        SELECT 1
        FROM pg_locks transaction_lock
        WHERE transaction_lock.pid = activity.pid
          AND transaction_lock.locktype = 'transactionid'
          AND NOT transaction_lock.granted
    ) AS awaits_owner_transaction
FROM pg_stat_activity activity
WHERE activity.pid = ?
SQL, [$ownerBackendPid, $controlBackendPid, $backendPid]);

            if ($activity !== null
                && $activity->wait_event_type === 'Lock'
                && (bool) $activity->blocked_by_owner
                && ! (bool) $activity->blocked_by_control
                && (bool) $activity->holds_documents_relation_lock
                && (bool) $activity->awaits_owner_transaction
                && str_contains(strtolower((string) $activity->query), 'documents')
                && str_contains(strtolower((string) $activity->query), 'for update')) {
                return $activity;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail(
            "PostgreSQL backend {$backendPid} was not directly blocked by conversion owner {$ownerBackendPid} "
            .'on the production documents FOR UPDATE claim path.',
        );
    }

    /**
     * @param  array{pid: int, result_file: string, reaped: bool}  $child
     * @return array<string, mixed>
     */
    private function reapChild(array &$child): array
    {
        $deadline = microtime(true) + 10.0;
        do {
            $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
            if ($waited === $child['pid']) {
                $child['reaped'] = true;
                $this->forgetOwnedChild($child['pid']);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
                $payload = json_decode((string) file_get_contents($child['result_file']), true, 512, JSON_THROW_ON_ERROR);
                $this->assertIsArray($payload);

                return $payload;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->terminateAndReap($child['pid']);
        $child['reaped'] = true;
        $this->fail("Child {$child['pid']} exceeded the bounded reap deadline.");
    }

    private function terminateAndReap(int $pid): void
    {
        if ($this->waitForProcessExit($pid, 0.0)) {
            $this->forgetOwnedChild($pid);

            return;
        }

        posix_kill($pid, SIGTERM);
        if (! $this->waitForProcessExit($pid, 0.25)) {
            posix_kill($pid, SIGKILL);
            if (! $this->waitForProcessExit($pid, 1.0)) {
                throw new RuntimeException("Child {$pid} could not be reaped after SIGKILL.");
            }
        }

        $this->forgetOwnedChild($pid);
    }

    /** @param resource $socket */
    private function awaitBackendPid($socket, float $timeoutSeconds): int
    {
        $read = [$socket];
        $write = [];
        $except = [];
        $seconds = (int) floor($timeoutSeconds);
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
        $ready = stream_select($read, $write, $except, $seconds, $microseconds);
        if ($ready !== 1) {
            throw new RuntimeException('Child did not publish its PostgreSQL backend pid within the bounded deadline.');
        }

        $line = fgets($socket);
        $backendPid = is_string($line) ? (int) trim($line) : 0;
        if ($backendPid <= 0) {
            throw new RuntimeException('Child did not publish a valid PostgreSQL backend pid.');
        }

        return $backendPid;
    }

    private function waitForProcessExit(int $pid, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid || $waited === -1) {
                return true;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function forgetOwnedChild(int $pid): void
    {
        $this->ownedChildPids = array_values(array_filter(
            $this->ownedChildPids,
            static fn (int $ownedPid): bool => $ownedPid !== $pid,
        ));
    }

    private function assertFinalisedExactlyOnce(array $deliveryNoteIds, DeliveryNoteBillingLane $lane): void
    {
        $invoice = Document::query()
            ->where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice)
            ->sole();
        $this->assertGreaterThan(0, $invoice->lines()->count());
        $this->assertSame(count($deliveryNoteIds), DB::table('delivery_note_billing_marks as mark')
            ->join('documents as document', 'document.id', '=', 'mark.delivery_note_id')
            ->whereIn('mark.delivery_note_id', $deliveryNoteIds)
            ->where('mark.invoice_id', $invoice->id)
            ->where('mark.invoiced_via', $lane->value)
            ->whereRaw("document.payload->>'invoice_id' = mark.invoice_id::text")
            ->whereRaw("document.payload->>'invoiced_via' = mark.invoiced_via")
            ->count());
        $this->assertSame(0, DB::table('delivery_note_billing_marks')
            ->where('company_id', $this->company->id)
            ->whereNull('invoice_id')
            ->where('invoiced_via', '!=', DeliveryNoteBillingLane::LegacyUnknown->value)
            ->count());
        $this->assertSame(1, $this->invoiceSequenceAggregate());
    }

    private function assertBillingIntegrity(): void
    {
        $this->assertSame(
            [
                'payload_without_marker' => [],
                'marker_without_agreeing_payload' => [],
            ],
            $this->billingIntegrityMismatches(),
            'Delivery-note billing marker/payload regression net found disagreement.',
        );
    }

    /** @return array{payload_without_marker: list<string>, marker_without_agreeing_payload: list<string>} */
    private function billingIntegrityMismatches(): array
    {
        $payloadWithoutMarker = DB::table('documents as document')
            ->leftJoin('delivery_note_billing_marks as mark', 'mark.delivery_note_id', '=', 'document.id')
            ->where('document.company_id', $this->company->id)
            ->where('document.type', DocumentType::DeliveryNote->value)
            ->whereRaw("document.payload->>'invoiced_at' IS NOT NULL")
            ->whereNull('mark.delivery_note_id')
            ->orderBy('document.id')
            ->pluck('document.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $markerWithoutAgreement = DB::table('delivery_note_billing_marks as mark')
            ->join('documents as document', 'document.id', '=', 'mark.delivery_note_id')
            ->where('mark.company_id', $this->company->id)
            ->where(function ($query): void {
                $query->whereRaw("document.payload->>'invoice_id' IS DISTINCT FROM mark.invoice_id::text")
                    ->orWhereRaw("document.payload->>'invoiced_via' IS DISTINCT FROM mark.invoiced_via")
                    ->orWhereRaw("document.payload->>'invoiced_at' IS NULL");
            })
            ->orderBy('mark.delivery_note_id')
            ->pluck('mark.delivery_note_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return [
            'payload_without_marker' => $payloadWithoutMarker,
            'marker_without_agreeing_payload' => $markerWithoutAgreement,
        ];
    }

    private function invoiceSequenceAggregate(): int
    {
        return (int) DB::table('document_sequences')
            ->where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice->value)
            ->whereIn('year', $this->sequenceYears)
            ->sum('last_number');
    }

    private function cloneConnection(string $name): Connection
    {
        $default = (string) config('database.default');
        config(["database.connections.{$name}" => config("database.connections.{$default}")]);
        DB::purge($name);
        $this->clonedConnections[] = $name;
        $connection = DB::connection($name);
        $connection->statement("SET lock_timeout = '10s'");
        $connection->statement("SET statement_timeout = '30s'");

        return $connection;
    }

    private function deliveryNote(string $number): Document
    {
        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number.'-'.Str::lower(Str::random(8)),
            'document_date' => now(),
            'currency' => 'TND',
        ]);
        DocumentLine::create([
            'document_id' => $deliveryNote->id,
            'line_number' => 1,
            'product_id' => $this->product->id,
            'description' => $number,
            'quantity' => '1.0000',
            'quantity_delivered' => '0.0000',
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
            'line_total' => '10.000',
        ]);

        return $deliveryNote->fresh(['lines']);
    }

    private function salesOrder(): Document
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-RACE-'.Str::lower(Str::random(8)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '30.000',
            'tax_amount' => '5.700',
            'total' => '35.700',
            'balance_due' => '35.700',
        ]);
        DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 1,
            'product_id' => $this->product->id,
            'description' => 'Race physical line',
            'quantity' => '3.0000',
            'quantity_delivered' => '1.0000',
            'unit_price' => '10.000',
            'tax_rate' => '19.00',
            'line_total' => '30.000',
        ]);

        return $order->fresh(['lines', 'partner']);
    }

    private function linkedDeliveryNote(Document $order): Document
    {
        $sourceLine = $order->lines()->sole();
        $deliveryNote = $this->deliveryNote('DN-SO-RACE');
        $deliveryNote->lines()->update(['source_line_id' => $sourceLine->id]);
        $order->update(['payload' => ['delivery_note_ids' => [$deliveryNote->id]]]);
        $sourceLine->update(['quantity_delivered' => $sourceLine->quantity]);

        return $deliveryNote->fresh(['lines']);
    }

    private function requireProcessPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Two-process billing-claim contention requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the process-concurrency proof.');
        }
    }
}
