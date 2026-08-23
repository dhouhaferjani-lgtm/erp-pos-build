<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryOpeningService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Opening-balance batch LIFECYCLE hardening (H-1 / H-2 / L-1).
 *
 * Three defects, all on the surface a first tenant touches before anything else:
 *
 *  H-1  postBatch() was a check-then-set: the `canPost()` guard ran BEFORE the
 *       transaction opened and the in-transaction re-check read the STALE
 *       in-memory model, so two overlapping posts both passed and the lock-write
 *       silently re-pointed the batch hash chain.
 *  H-2  validateBatch() had no batch-status guard and selected every row that was
 *       not Skipped — POSTED rows included — so validating a LOCKED batch rewrote
 *       posted rows and replaced the `mapped_data` that the SHA-256 batch seal is
 *       computed over.
 *  L-1  the OB-/HIST-INV-/HIST-CN- sequence generators did read-max-then-increment
 *       with no lock (TOCTOU), unlike the corrected inventory sibling.
 *
 * The concurrency race is reproduced DETERMINISTICALLY by advancing the batch ROW
 * behind a stale in-memory model — exactly the interleaving where transaction B
 * evaluates its guard against a snapshot that transaction A has already superseded.
 */
final class OpeningBalanceBatchLifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'OB Hardening Tenant',
            'slug' => 'ob-hardening-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'OB Hardening Company',
            'legal_name' => 'OB Hardening Company LLC',
            'tax_id' => 'TAX-OB-HARD-1',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'OB Hardening Admin',
            'email' => 'ob-hardening-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['accounts.view', 'accounts.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3100',
            'name' => 'Inventory',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-OBH-01',
            'name' => 'OB Hardening Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'OBH-PROD-1',
            'name' => 'OB Hardening Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '10.000',
        ]);
    }

    // -----------------------------------------------------------------
    // H-1 (a) — the in-transaction guard must read the FRESH, LOCKED row
    // -----------------------------------------------------------------

    public function test_accounting_post_refuses_when_the_batch_row_advanced_behind_a_stale_model(): void
    {
        $batch = $this->createValidatedAccountingBatch();

        $stale = OpeningBalanceBatch::findOrFail($batch->id);
        $this->simulateConcurrentPost($batch->id);

        // The in-memory model the caller holds still believes it is postable.
        $this->assertSame(OpeningBatchStatus::Draft, $stale->status);

        $before = $this->batchRowSnapshot($batch->id);

        $this->assertRefusesToPost(
            function () use ($stale): void {
                app(AccountingOpeningService::class)->postBatch($stale, (string) $this->user->id);
            }
        );

        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('source_type', 'opening_balance')
                ->where('source_id', $batch->id)
                ->count(),
            'A refused post must not leave a journal entry behind.'
        );
        $this->assertSame($before, $this->batchRowSnapshot($batch->id), 'The batch row (hash chain included) must be untouched.');
    }

    public function test_arap_post_refuses_when_the_batch_row_advanced_behind_a_stale_model(): void
    {
        $batch = $this->createValidatedArBatch();

        $stale = OpeningBalanceBatch::findOrFail($batch->id);
        $this->simulateConcurrentPost($batch->id);

        $this->assertSame(OpeningBatchStatus::Draft, $stale->status);

        $before = $this->batchRowSnapshot($batch->id);

        $this->assertRefusesToPost(
            function () use ($stale): void {
                app(ArApOpeningService::class)->postBatch($stale, (string) $this->user->id);
            }
        );

        $this->assertSame(
            0,
            Document::query()->where('company_id', $this->company->id)->where('is_historical', true)->count(),
            'A refused AR/AP post must not leave historical documents behind.'
        );
        $this->assertSame($before, $this->batchRowSnapshot($batch->id));
    }

    public function test_inventory_post_refuses_when_the_batch_row_advanced_behind_a_stale_model(): void
    {
        $batch = $this->createValidatedInventoryBatch();

        $stale = OpeningBalanceBatch::findOrFail($batch->id);
        $this->simulateConcurrentPost($batch->id);

        $this->assertSame(OpeningBatchStatus::Draft, $stale->status);

        $before = $this->batchRowSnapshot($batch->id);

        $this->assertRefusesToPost(
            function () use ($stale): void {
                app(InventoryOpeningService::class)->postBatch($stale, (string) $this->user->id);
            }
        );

        $this->assertSame(
            0,
            StockMovement::query()->where('company_id', $this->company->id)->count(),
            'A refused inventory post must not leave stock movements behind.'
        );
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_id', $batch->id)->count()
        );
        $this->assertSame($before, $this->batchRowSnapshot($batch->id));
    }

    // -----------------------------------------------------------------
    // H-1 (c) — the claim-style conditional writes are the DB backstop
    // -----------------------------------------------------------------

    public function test_mark_batch_validated_refuses_a_batch_that_already_left_draft(): void
    {
        $batch = $this->createValidatedAccountingBatch();

        $stale = OpeningBalanceBatch::findOrFail($batch->id);
        OpeningBalanceBatch::query()->whereKey($batch->id)->update([
            'status' => OpeningBatchStatus::Validated,
            'validated_at' => now(),
            'validated_by' => $this->user->id,
        ]);

        $this->expectException(RuntimeException::class);

        app(OpeningBalanceBatchService::class)->markBatchValidated($stale, (string) $this->user->id);
    }

    public function test_mark_rows_posted_refuses_a_row_that_is_already_posted(): void
    {
        $batch = $this->createValidatedAccountingBatch();
        $row = $batch->rows()->firstOrFail();

        // mapped_entity_id is a uuid column on PostgreSQL — real UUIDs only.
        $firstEntityId = (string) Str::uuid();
        $secondEntityId = (string) Str::uuid();

        OpeningBalanceImportRow::query()->whereKey($row->id)->update([
            'status' => OpeningImportRowStatus::Posted,
            'mapped_entity_id' => $firstEntityId,
        ]);

        $this->assertRefuses(
            function () use ($row, $secondEntityId): void {
                app(OpeningBalanceBatchService::class)->markRowsPosted([$row->id => $secondEntityId]);
            },
            'markRowsPosted must refuse a row that is already POSTED.'
        );

        $this->assertSame(
            $firstEntityId,
            OpeningBalanceImportRow::query()->whereKey($row->id)->value('mapped_entity_id'),
            'A refused re-post must not re-point the row at a second entity.'
        );
    }

    // -----------------------------------------------------------------
    // H-2 — validate must never touch a sealed batch or a posted row
    // -----------------------------------------------------------------

    public function test_validate_endpoint_on_a_locked_batch_returns_422_and_leaves_the_seal_intact(): void
    {
        $batch = $this->createValidatedAccountingBatch();
        app(AccountingOpeningService::class)->postBatch($batch, (string) $this->user->id);

        $batch->refresh();
        $this->assertSame(OpeningBatchStatus::Locked, $batch->status);

        $storedHash = (string) $batch->hash;
        $this->assertSame($storedHash, $this->recomputeBatchHash($batch), 'Pre-condition: the seal verifies right after posting.');

        $rowsBefore = $this->importRowSnapshot($batch->id);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/companies/{$this->company->id}/opening-batches/{$batch->id}/validate"
        );

        $response->assertStatus(422);

        $this->assertSame($rowsBefore, $this->importRowSnapshot($batch->id), 'Validating a LOCKED batch must not rewrite its rows.');
        $this->assertSame(
            $storedHash,
            $this->recomputeBatchHash($batch->refresh()),
            'The stored batch hash must still verify against the row content.'
        );
    }

    public function test_inventory_and_arap_validate_refuse_a_non_editable_batch(): void
    {
        $inventoryBatch = $this->createValidatedInventoryBatch();
        OpeningBalanceBatch::query()->whereKey($inventoryBatch->id)->update(['status' => OpeningBatchStatus::Locked]);

        $inventoryRowsBefore = $this->importRowSnapshot($inventoryBatch->id);

        $this->assertRefuses(
            function () use ($inventoryBatch): void {
                app(InventoryOpeningService::class)->validateBatch($inventoryBatch->refresh());
            },
            'InventoryOpeningService::validateBatch must refuse a LOCKED batch.'
        );
        $this->assertSame($inventoryRowsBefore, $this->importRowSnapshot($inventoryBatch->id));

        $arBatch = $this->createValidatedArBatch();
        OpeningBalanceBatch::query()->whereKey($arBatch->id)->update(['status' => OpeningBatchStatus::Locked]);

        $arRowsBefore = $this->importRowSnapshot($arBatch->id);

        $this->assertRefuses(
            function () use ($arBatch): void {
                app(ArApOpeningService::class)->validateBatch($arBatch->refresh());
            },
            'ArApOpeningService::validateBatch must refuse a LOCKED batch.'
        );
        $this->assertSame($arRowsBefore, $this->importRowSnapshot($arBatch->id));
    }

    public function test_validate_excludes_posted_rows_from_the_row_selection(): void
    {
        $batch = $this->createAccountingBatch([
            ['account_code' => '5100', 'debit' => '100.000', 'credit' => '0.000', 'description' => 'Cash'],
            ['account_code' => '3900', 'debit' => '0.000', 'credit' => '100.000', 'description' => 'OBE'],
        ]);

        // mapped_entity_id is a uuid column on PostgreSQL — real UUIDs only.
        $postedEntityId = (string) Str::uuid();

        $postedRow = OpeningBalanceImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 99,
            'row_type' => 'ACCOUNTING',
            'status' => OpeningImportRowStatus::Posted,
            'raw_data' => ['account_code' => '5100', 'debit' => '7.000', 'credit' => '0.000'],
            'mapped_data' => ['sealed' => 'do-not-touch'],
            'mapped_entity_id' => $postedEntityId,
        ]);

        $result = app(AccountingOpeningService::class)->validateBatch($batch->refresh());

        $this->assertSame(2, $result['total_rows'], 'A POSTED row must not be re-validated.');

        $postedRow->refresh();
        $this->assertSame(OpeningImportRowStatus::Posted, $postedRow->status);
        $this->assertSame(['sealed' => 'do-not-touch'], $postedRow->mapped_data);
        $this->assertSame($postedEntityId, $postedRow->mapped_entity_id);
    }

    public function test_apply_validation_results_refuses_a_posted_row(): void
    {
        $batch = $this->createValidatedAccountingBatch();
        $row = $batch->rows()->firstOrFail();

        OpeningBalanceImportRow::query()->whereKey($row->id)->update([
            'status' => OpeningImportRowStatus::Posted,
            'mapped_data' => json_encode(['sealed' => 'do-not-touch']),
        ]);

        $this->assertRefuses(
            function () use ($batch, $row): void {
                app(OpeningBalanceBatchService::class)->applyValidationResults($batch, [
                    $row->id => ['valid' => true, 'errors' => [], 'mapped_data' => ['overwritten' => 'yes']],
                ]);
            },
            'applyValidationResults must refuse a POSTED row.'
        );

        $row->refresh();
        $this->assertSame(OpeningImportRowStatus::Posted, $row->status);
        $this->assertSame(['sealed' => 'do-not-touch'], $row->mapped_data);
    }

    // -----------------------------------------------------------------
    // L-1 — company-scoped advisory lock around the sequence read-max
    // -----------------------------------------------------------------

    public function test_accounting_entry_numbering_takes_a_company_scoped_advisory_lock(): void
    {
        $this->requiresPostgres();

        $batch = $this->createValidatedAccountingBatch();

        $keys = $this->captureAdvisoryLockKeys(
            function () use ($batch): void {
                app(AccountingOpeningService::class)->postBatch($batch, (string) $this->user->id);
            }
        );

        $this->assertContains("gl-ob-seq:{$this->company->id}:".date('Y'), $keys);
    }

    public function test_historical_document_numbering_takes_a_company_scoped_advisory_lock(): void
    {
        $this->requiresPostgres();

        $batch = $this->createValidatedArBatch();

        $keys = $this->captureAdvisoryLockKeys(
            function () use ($batch): void {
                app(ArApOpeningService::class)->postBatch($batch, (string) $this->user->id);
            }
        );

        $this->assertContains("hist-doc-seq:{$this->company->id}:HIST-INV:".date('Y'), $keys);
    }

    // -----------------------------------------------------------------
    // H-1 (b) — the partial unique index on the accounting arm's JE
    // -----------------------------------------------------------------

    public function test_partial_unique_index_blocks_a_second_opening_journal_entry_for_the_same_batch(): void
    {
        $this->requiresPostgres();

        $batch = $this->createValidatedAccountingBatch();
        $entry = app(AccountingOpeningService::class)->postBatch($batch, (string) $this->user->id);

        $this->expectException(QueryException::class);

        JournalEntry::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'entry_number' => $entry->entry_number.'-DUP',
            'entry_date' => $batch->cutover_date,
            'description' => 'GL Opening Balance - duplicate',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'opening_balance',
            'source_id' => $batch->id,
            'is_historical' => true,
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function requiresPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only: partial unique indexes and advisory locks do not exist on sqlite.');
        }
    }

    /**
     * Advance the batch ROW the way a concurrent request that already posted would,
     * leaving any in-memory model the caller still holds stale.
     */
    private function simulateConcurrentPost(string $batchId): void
    {
        OpeningBalanceBatch::query()->whereKey($batchId)->update([
            'status' => OpeningBatchStatus::Locked,
            'validated_at' => now(),
            'validated_by' => $this->user->id,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
            'hash' => str_repeat('a', 64),
            'previous_hash' => null,
        ]);
    }

    /**
     * PHPUnit's AssertionFailedError extends RuntimeException, so `$this->fail()`
     * must NEVER sit inside a `catch (RuntimeException)` — it would be swallowed and
     * the assertion silently lost. Refusal is recorded as a flag instead.
     *
     * @param  \Closure(): void  $operation
     */
    private function assertRefuses(\Closure $operation, string $message): void
    {
        $refused = false;

        try {
            $operation();
        } catch (RuntimeException) {
            $refused = true;
        }

        $this->assertTrue($refused, $message);
    }

    /**
     * @param  \Closure(): void  $post
     */
    private function assertRefusesToPost(\Closure $post): void
    {
        $this->assertRefuses($post, 'postBatch must refuse a batch whose committed row is no longer DRAFT.');
    }

    /**
     * Byte-level snapshot of the batch row, JSON-encoded so the comparison is exact.
     */
    private function batchRowSnapshot(string $batchId): string
    {
        return (string) json_encode(
            DB::table('opening_balance_batches')->where('id', $batchId)->first()
        );
    }

    /**
     * Byte-level snapshot of every staged row, JSON-encoded so the comparison is exact.
     */
    private function importRowSnapshot(string $batchId): string
    {
        return (string) json_encode(
            DB::table('opening_balance_import_rows')
                ->where('batch_id', $batchId)
                ->orderBy('row_number')
                ->get()
                ->all()
        );
    }

    private function recomputeBatchHash(OpeningBalanceBatch $batch): string
    {
        $method = new ReflectionMethod(OpeningBalanceBatchService::class, 'calculateBatchHash');
        $method->setAccessible(true);

        /** @var string $hash */
        $hash = $method->invoke(app(OpeningBalanceBatchService::class), $batch);

        return $hash;
    }

    /**
     * @param  \Closure(): void  $operation
     * @return list<string>
     */
    private function captureAdvisoryLockKeys(\Closure $operation): array
    {
        /** @var list<string> $keys */
        $keys = [];

        DB::listen(static function (QueryExecuted $query) use (&$keys): void {
            if (! str_contains($query->sql, 'pg_advisory_xact_lock')) {
                return;
            }

            foreach ($query->bindings as $binding) {
                if (is_string($binding)) {
                    $keys[] = $binding;
                }
            }
        });

        $operation();

        return $keys;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function createAccountingBatch(array $rows): OpeningBalanceBatch
    {
        $service = app(OpeningBalanceBatchService::class);
        $batch = $service->createBatch(
            $this->company,
            OpeningBatchType::Accounting,
            Carbon::parse('2026-01-01'),
            'OB-HARDENING-GL',
            (string) $this->user->id,
            'phpunit',
        );
        $service->addImportRows($batch, $rows);

        return $batch;
    }

    private function createValidatedAccountingBatch(): OpeningBalanceBatch
    {
        $batch = $this->createAccountingBatch([
            ['account_code' => '5100', 'debit' => '10000.000', 'credit' => '0.000', 'description' => 'Cash opening'],
            ['account_code' => '3900', 'debit' => '0.000', 'credit' => '10000.000', 'description' => 'OBE offset'],
        ]);

        app(AccountingOpeningService::class)->validateBatch($batch->refresh());

        return $batch->refresh();
    }

    private function createValidatedArBatch(): OpeningBalanceBatch
    {
        Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'OBH-CUST-1',
            'type' => 'customer',
        ]);

        $service = app(OpeningBalanceBatchService::class);
        $batch = $service->createBatch(
            $this->company,
            OpeningBatchType::ArOpenItems,
            Carbon::parse('2026-01-01'),
            'OB-HARDENING-AR',
            (string) $this->user->id,
            'phpunit',
        );
        $service->addImportRows($batch, [[
            'partner_code' => 'OBH-CUST-1',
            'external_invoice_number' => 'LEG-OBH-1',
            'document_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'total' => '500.000',
            'open_amount' => '500.000',
            'currency' => $this->company->currency,
            'document_type' => 'invoice',
            'notes' => null,
        ]]);

        app(ArApOpeningService::class)->validateBatch($batch->refresh());

        return $batch->refresh();
    }

    private function createValidatedInventoryBatch(): OpeningBalanceBatch
    {
        $service = app(OpeningBalanceBatchService::class);
        $batch = $service->createBatch(
            $this->company,
            OpeningBatchType::Inventory,
            Carbon::parse('2026-01-01'),
            'OB-HARDENING-INV',
            (string) $this->user->id,
            'phpunit',
        );
        $service->addImportRows($batch, [[
            'product_code' => $this->product->sku,
            'location_code' => $this->warehouse->code,
            'quantity' => '50.0000',
            'unit_cost' => '10.000',
        ]]);

        app(InventoryOpeningService::class)->validateBatch($batch->refresh());

        return $batch->refresh();
    }
}
