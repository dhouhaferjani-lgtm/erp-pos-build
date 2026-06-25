<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Domain\Events\GoodsReceived;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GoodsReceiptGlTest — verifies that receiving goods posts Dr Inventory / Cr 408 (GR-IR).
 *
 * Tests covered:
 * 1. Single receipt posts a balanced JE Dr Inventory / Cr GoodsReceivedNotInvoiced
 * 2. Partial receipts accrue 408 incrementally (two entries total correct sum)
 * 3. Re-dispatching the same GoodsReceived (same movementId) is idempotent
 */
final class GoodsReceiptGlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Account $inventoryAccount;

    private Account $grirAccount;

    private GeneralLedgerHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GR-IR GL Test Tenant',
            'slug' => 'grir-gl-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GR-IR GL Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Seed Tunisia chart so Inventory + GoodsReceivedNotInvoiced purposes resolve.
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        /** @var Account $inventoryAccount */
        $inventoryAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Inventory);
        $this->inventoryAccount = $inventoryAccount;

        /** @var Account $grirAccount */
        $grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $this->grirAccount = $grirAccount;

        $this->hashService = app(GeneralLedgerHashService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Dispatch a GoodsReceived event for a single receipt line.
     */
    private function dispatchGoodsReceived(
        string $movementId,
        string $qty,
        string $unitCost,
    ): void {
        event(new GoodsReceived(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            productId: Str::uuid()->toString(),
            locationId: Str::uuid()->toString(),
            poLineId: Str::uuid()->toString(),
            movementId: $movementId,
            receivedQty: $qty,
            unitCost: $unitCost,
            currency: 'TND',
        ));
    }

    // =========================================================================
    // 1. Single receipt: Dr Inventory 50.000 / Cr 408 50.000
    // =========================================================================

    public function test_single_receipt_posts_gr_ir_dr_inventory_cr_408(): void
    {
        $movementId = Str::uuid()->toString();

        $this->dispatchGoodsReceived($movementId, '5.0000', '10.000');

        // One JE should exist for this movement
        $entry = JournalEntry::where('source_type', 'goods_receipt')
            ->where('source_id', $movementId)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);

        // Must have exactly 2 lines — no VAT leg
        $this->assertCount(2, $entry->lines);

        // Dr Inventory line
        $drLine = $entry->lines->where('account_id', $this->inventoryAccount->id)->first();
        $this->assertNotNull($drLine, 'Debit leg on Inventory account must exist');
        $this->assertSame('50.000', $drLine->debit);
        $this->assertSame('0.000', $drLine->credit);
        $this->assertNull($drLine->partner_id);

        // Cr GoodsReceivedNotInvoiced (408) line
        $crLine = $entry->lines->where('account_id', $this->grirAccount->id)->first();
        $this->assertNotNull($crLine, 'Credit leg on GoodsReceivedNotInvoiced (408) account must exist');
        $this->assertSame('0.000', $crLine->debit);
        $this->assertSame('50.000', $crLine->credit);
        $this->assertNull($crLine->partner_id);

        // Debits == Credits (TND scale 3)
        $totalDebit = $entry->lines->sum(fn ($l) => (float) $l->debit);
        $totalCredit = $entry->lines->sum(fn ($l) => (float) $l->credit);
        $this->assertEqualsWithDelta(0.0, $totalDebit - $totalCredit, 0.001, 'Entry must balance');

        // Hash chain verifies
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 2. Partial receipts accrue 408 incrementally
    // =========================================================================

    public function test_partial_receipts_accrue_gr_ir_incrementally(): void
    {
        $movementId1 = Str::uuid()->toString();
        $movementId2 = Str::uuid()->toString();

        // First receipt: 3 units @ 10.000
        $this->dispatchGoodsReceived($movementId1, '3.0000', '10.000');

        // Second receipt: 2 units @ 10.000
        $this->dispatchGoodsReceived($movementId2, '2.0000', '10.000');

        // Two distinct JEs
        $entries = JournalEntry::where('source_type', 'goods_receipt')
            ->whereIn('source_id', [$movementId1, $movementId2])
            ->get();

        $this->assertCount(2, $entries, 'Two receipt events must produce two JEs');

        // Total Cr 408 across both entries should be 50.000
        $totalCredit408 = $entries->flatMap(fn ($e) => $e->lines)
            ->where('account_id', $this->grirAccount->id)
            ->sum(fn ($l) => (float) $l->credit);

        $this->assertEqualsWithDelta(50.000, $totalCredit408, 0.001, 'Total 408 accrual must equal 50.000');

        // Each entry individually balances
        foreach ($entries as $entry) {
            $this->assertSame(JournalEntryStatus::Posted, $entry->status);
            $totalDebit = $entry->lines->sum(fn ($l) => (float) $l->debit);
            $totalCredit = $entry->lines->sum(fn ($l) => (float) $l->credit);
            $this->assertEqualsWithDelta(0.0, $totalDebit - $totalCredit, 0.001, "Entry {$entry->id} must balance");
        }

        // Full hash chain still verifies after two entries
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    // =========================================================================
    // 3. Idempotency: re-dispatching same movementId must not create a second JE
    // =========================================================================

    public function test_idempotent_on_same_movement_id(): void
    {
        $movementId = Str::uuid()->toString();

        $this->dispatchGoodsReceived($movementId, '5.0000', '10.000');
        $this->dispatchGoodsReceived($movementId, '5.0000', '10.000'); // same movementId again

        $count = JournalEntry::where('source_type', 'goods_receipt')
            ->where('source_id', $movementId)
            ->count();

        $this->assertSame(1, $count, 'Duplicate GoodsReceived with same movementId must not post a second JE');
    }
}
