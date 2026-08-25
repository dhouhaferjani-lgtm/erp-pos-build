<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\DTOs\MovementGlContext;
use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEDGER C-27, fix round r1 — the STOCK side of the numbering seam.
 *
 * Gate r1 (stock↔GL lens) raised two gaps that the expense-driven pins in
 * {@see JournalEntryNumberingTenantScopeTest} cannot cover:
 *
 * F-1 — `sealAndPersistEntry` takes the per-company chain key ALONE whenever it
 *       posts an entry that was NUMBERED in an earlier transaction (the
 *       `$existing`-Draft replay branches of `createInventoryMovementEntry`
 *       `:5157-5163` and `createInventoryWriteOffEntry` `:5380-5387`, reached in
 *       production by `InventoryGlPostingBuffer::flushIfOutermost()` posting a
 *       batch inside one root transaction). That made company→tenant order
 *       reachable, which AB-BAs against an ordinary tenant→company mint; the
 *       reviewer reproduced a real `SQLSTATE 40P01` on PostgreSQL.
 *
 * F-2 — none of the eight inventory / GR-IR mint sites was exercised for the
 *       two-company case, and none in a worker context with `CompanyContext`
 *       cleared (rule 20), even though that is the path whose pre-fix failure
 *       actually loses money: `23505` is NOT retryable, so the contained POS
 *       flush discarded the whole inventory GL batch and committed the receipt
 *       with the stock movements written and no COGS entry at all.
 *
 * PostgreSQL only, and with `connectionsToTransact()` empty so the buffer sees
 * production transaction levels (the same contract the inventory seam tests use).
 */
final class InventoryGlNumberingTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('The inventory GL numbering seam requires PostgreSQL advisory locks and real root commits.');
        }
    }

    /**
     * Let the buffer and `postEntryNow` observe production transaction levels;
     * RefreshDatabase would otherwise hold the test at level one forever.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    /**
     * F-1 — posting an entry that was numbered in an EARLIER transaction must
     * still take the tenant numbering key BEFORE the company chain key.
     *
     * Transaction 1 mints the Draft. Transaction 2 replays the same movement with
     * `postSynchronously: true`, hits the `$existing` branch, and goes straight to
     * `postEntryNow` → `sealAndPersistEntry` without minting anything — the exact
     * shape `InventoryGlPostingBuffer` produces when one context in a batch is a
     * replay. Before the fix that transaction took the company key ALONE.
     */
    public function test_posting_a_pre_numbered_entry_takes_the_tenant_key_before_the_company_key(): void
    {
        [$tenant, $company] = $this->makeTenantWithCompany();
        $this->inventoryAccounts($tenant, $company);
        $gl = app(GeneralLedgerService::class);
        $movementId = (string) Str::uuid();

        // Transaction 1 — mint the Draft (numbered here, posted nowhere).
        DB::transaction(function () use ($gl, $company, $movementId): void {
            $gl->createInventoryMovementEntry(
                companyId: $company->id,
                movementId: $movementId,
                sourceType: 'inventory_exit',
                amount: '5.000',
                reason: MovementReason::Delivery,
                counterPurpose: SystemAccountPurpose::CostOfGoodsSold,
                debitInventory: false,
                entryDate: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
                description: 'C-27 F-1 draft',
                currencyCode: 'TND',
                postSynchronously: false,
            );
        });

        // Transaction 2 — replay: the entry already exists and is Draft, so this
        // transaction ONLY posts. It mints nothing.
        DB::enableQueryLog();
        DB::transaction(function () use ($gl, $company, $movementId): void {
            $gl->createInventoryMovementEntry(
                companyId: $company->id,
                movementId: $movementId,
                sourceType: 'inventory_exit',
                amount: '5.000',
                reason: MovementReason::Delivery,
                counterPurpose: SystemAccountPurpose::CostOfGoodsSold,
                debitInventory: false,
                entryDate: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
                description: 'C-27 F-1 draft',
                currencyCode: 'TND',
                postSynchronously: true,
            );
        });
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // The replay really did post (so the log below is the sealing transaction).
        $entry = JournalEntry::query()->where('source_id', $movementId)->sole();
        self::assertSame(1, $entry->chain_sequence);

        $tenantLockAt = $this->firstLockIndex($log, "journal_entry_number:{$tenant->id}");
        $companyLockAt = $this->firstLockIndex($log, $company->id);

        self::assertNotNull(
            $companyLockAt,
            'sealAndPersistEntry must still take the per-company chain key — the hash chain is per company.'
        );
        self::assertNotNull(
            $tenantLockAt,
            'A transaction that posts a PRE-NUMBERED entry takes the company chain key; it must take the tenant '
            .'numbering key first, or it can AB-BA against a concurrent mint (reviewer reproduced SQLSTATE 40P01).'
        );
        self::assertLessThan(
            $companyLockAt,
            $tenantLockAt,
            'Invariant: every path that takes the company chain key takes the tenant numbering key FIRST.'
        );
    }

    /**
     * F-2 — the inventory GL mint path, driven through the production buffer with
     * NO CompanyContext bound, must allocate tenant-unique numbers for the second
     * company of a tenant, and both sides of the seam must survive: the
     * `stock_movements` row AND its journal entry.
     */
    public function test_inventory_gl_mints_tenant_unique_numbers_for_a_second_company_with_no_company_context(): void
    {
        $tenant = Tenant::factory()->create();
        $companyA = $this->companyFor($tenant);
        $companyB = $this->companyFor($tenant);
        $this->inventoryAccounts($tenant, $companyA);
        $this->inventoryAccounts($tenant, $companyB);

        $movementA = $this->stockMovement($tenant, $companyA);
        $movementB = $this->stockMovement($tenant, $companyB);

        // Worker reality (rule 20): projections and queued jobs run with no
        // CompanyContext bound. Anything that resolved the tenant from context
        // instead of from the movement would break here.
        app(CompanyContext::class)->clear();

        $this->flush($companyA, $movementA->id);
        $this->flush($companyB, $movementB->id);

        // Stock side: both movements still exist (neither batch rolled back).
        self::assertTrue(StockMovement::query()->whereKey($movementA->id)->exists());
        self::assertTrue(StockMovement::query()->whereKey($movementB->id)->exists());

        // GL side: tenant-unique numbers, one chain per company.
        $entryA = JournalEntry::query()->where('source_id', $movementA->id)->sole();
        $entryB = JournalEntry::query()->where('source_id', $movementB->id)->sole();

        $year = date('Y');
        self::assertSame(sprintf('JE-%s-%06d', $year, 1), $entryA->entry_number);
        self::assertSame(
            sprintf('JE-%s-%06d', $year, 2),
            $entryB->entry_number,
            'The second company of a tenant must not re-mint the first company\'s inventory entry number — '
            .'23505 is not retryable, so the contained POS flush discards the whole GL batch and commits the '
            .'stock movements with no COGS entry.'
        );
        self::assertSame($companyA->id, $entryA->company_id);
        self::assertSame($companyB->id, $entryB->company_id);
        self::assertSame(1, $entryA->chain_sequence);
        self::assertSame(1, $entryB->chain_sequence, 'The hash chain stays per company.');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Index of the first `pg_advisory_xact_lock` statement whose bindings contain
     * the given value, or null when no such statement was logged.
     *
     * @param  array<int, array{query: string, bindings: array<int|string, mixed>, time: float|null}>  $log
     */
    private function firstLockIndex(array $log, string $binding): ?int
    {
        foreach (array_values($log) as $index => $entry) {
            if (! str_contains((string) $entry['query'], 'pg_advisory_xact_lock(hashtextextended')) {
                continue;
            }

            $bindings = array_map(static fn (mixed $value): string => (string) $value, $entry['bindings']);
            if (in_array($binding, $bindings, true)) {
                return $index;
            }
        }

        return null;
    }

    private function flush(Company $company, string $movementId): void
    {
        $buffer = app(InventoryGlPostingBuffer::class);

        DB::transaction(function () use ($buffer, $company, $movementId): void {
            $buffer->enqueue(new MovementGlContext(
                kind: MovementGlKind::Exit,
                movementId: $movementId,
                companyId: $company->id,
                currencyCode: 'TND',
                reason: MovementReason::Delivery,
                quantityBefore: '3.0000',
                quantityAfter: '0.0000',
                unitCost: '1.6666666',
                sourceType: 'Document',
                sourceId: (string) Str::uuid(),
                occurredAt: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
                entryDate: new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
                postedByUserId: null,
                isHistorical: false,
                batchNumber: null,
                productId: null,
            ));
            $buffer->flushIfOutermost();
        });
    }

    /** @return array{0: Tenant, 1: Company} */
    private function makeTenantWithCompany(): array
    {
        $tenant = Tenant::factory()->create();

        return [$tenant, $this->companyFor($tenant)];
    }

    private function companyFor(Tenant $tenant): Company
    {
        return Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
            'inventory_valuation_mode' => 'perpetual',
        ]);
    }

    private function inventoryAccounts(Tenant $tenant, Company $company): void
    {
        Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => AccountType::Expense,
            'system_purpose' => SystemAccountPurpose::CostOfGoodsSold,
        ]);
        Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Inventory,
        ]);
    }

    private function stockMovement(Tenant $tenant, Company $company): StockMovement
    {
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        return StockMovement::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::Delivery,
            'quantity' => '3.0000',
            'quantity_before' => '3.0000',
            'quantity_after' => '0.0000',
            'occurred_at' => new \DateTimeImmutable('2026-08-10T10:00:00+00:00'),
        ]);
    }
}
