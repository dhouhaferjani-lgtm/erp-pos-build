<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Treasury\Concerns\PaymentApplicabilityScaffold;
use Tests\TestCase;

/**
 * C-0a0 fix round r1 / gate F-2 (IMPORTANT) — the fail-closed refusal of
 * historical openings is narrowed to the side that actually books wrong-direction
 * GL, and the discriminator is the one that already exists on base.
 *
 * r1 shipped a blanket refusal on the stated grounds that "there is no column to
 * tell the two apart yet". The gate disproved that: `OpeningBalanceBatchService`
 * writes `opening_balance_import_rows.row_type` = `AR`/`AP` at insert
 * (`:200-218`, from `OpeningBatchType::rowType()`) and links the posted row to
 * the document it minted via `mapped_entity_id` (`markRowsPosted():636-658`).
 * Both are durable and both predate this lane.
 *
 * So refusing the AR side was a CHOICE, and an expensive one: a first-client
 * cutover imports its open receivables as historical openings, and a blanket
 * refusal means those invoices cannot be collected by ANY route — the AP branch
 * of `PaymentController::store()` requires `type === SupplierInvoice` and an
 * opening is minted as `Invoice`, so there is no third door.
 *
 * The rule, as RULED by the owner on OQ-74 (recorded `d0cfa624f` — **ALLOW**,
 * with the standing rule that standard ERP logic applies as soon as it is
 * implemented, so a correctly built flow is never refused as a fail-safe):
 * - historical **AR** opening + posted  ⇒ `ReceivableClearing`. A real 411 exists
 *   (the opening JE created it); collecting it is an ordinary collection.
 * - historical **AP** opening           ⇒ **PAID through the supplier arm**,
 *   Dr 401 / Cr bank, cash OUT. W4-3 mints it as a `DocumentType::SupplierInvoice`
 *   with a posted `supplier_invoice` entry carrying `Cr 401` partner-tagged, which
 *   is exactly what `PaymentController::store()`'s supplier branch requires. The
 *   earlier refusal rested on an AP opening being indistinguishable from an AR one
 *   (both were minted as `Invoice`); that premise is gone.
 *   It is STILL refused on the AR-direction routes — those post Dr bank / Cr 411,
 *   which is the defect this lane exists to kill — and that is pinned below.
 * - side UNRESOLVABLE                   ⇒ REFUSED. Fail closed is only correct
 *   where the evidence is genuinely absent.
 */
final class HistoricalOpeningSideSettlementTest extends TestCase
{
    use PaymentApplicabilityScaffold;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentApplicabilityFixture('hist-side');
    }

    public function test_a_historical_ar_opening_can_be_collected_on_the_direct_payment_endpoint(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ArOpenItems, $this->customer, '100.000');

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '40.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $opening->id, 'amount' => '40.000'],
            ],
        ]);

        $response->assertCreated();

        $this->assertSame(
            0,
            bccomp((string) PaymentAllocation::query()->where('document_id', $opening->id)->sum('amount'), '40.000', 3),
        );
        // A historical AR opening carries a REAL 411 from the opening JE, so the
        // money CLEARS it. Booking it as a 419 advance would leave the receivable
        // standing and invent a liability the company does not owe.
        $this->assertSame(
            0,
            PaymentAllocation::query()
                ->where('document_id', $opening->id)
                ->where('booked_as_advance', true)
                ->count(),
            'a historical AR opening settles a receivable; it is never an advance',
        );
    }

    public function test_a_historical_ar_opening_is_offered_and_collected_by_the_auto_sweep(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ArOpenItems, $this->customer, '100.000');
        $payment = $this->makeUnallocatedPayment('40.000', $this->customer);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'fifo',
        ]);

        $response->assertOk();
        $this->assertSame(
            0,
            bccomp((string) PaymentAllocation::query()->where('document_id', $opening->id)->sum('amount'), '40.000', 3),
        );
    }

    /**
     * OQ-74 = ALLOW. The AP opening is a real payable and settles the way every
     * other supplier invoice does: Dr 401 (partner-tagged) / Cr bank, cash OUT.
     *
     * The campaign's W4-3 defect was the mirror of this — the same payment booked
     * `Dr 512 / Cr 411` with the repository movement direction IN, the 401 debt
     * left standing and the document marked paid. So this test asserts the MONEY,
     * not the status code.
     */
    public function test_a_historical_ap_opening_is_paid_through_the_supplier_arm(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');
        $bank = $this->ledgeredBankRepository();

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->vendor->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $bank->id,
            'amount' => '40.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $opening->id, 'amount' => '40.000'],
            ],
        ]);

        $response->assertStatus(201);

        $entry = JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'supplier_payment')
            ->with('lines')
            ->first();

        self::assertNotNull($entry, 'a payable settles through the supplier arm, not the customer one');

        $payable = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $payableLine = $entry->lines->firstWhere('account_id', $payable->id);

        self::assertNotNull($payableLine);
        self::assertSame(0, bccomp((string) $payableLine->debit, '40.000', 3), 'the payable is cleared, not the receivable');
        self::assertSame($this->vendor->id, $payableLine->partner_id);

        $bankLine = $entry->lines->firstWhere('account_id', $bank->gl_account_id);
        self::assertNotNull($bankLine);
        self::assertSame(0, bccomp((string) $bankLine->credit, '40.000', 3), 'cash leaves the bank');

        self::assertSame(
            0,
            JournalEntry::query()->where('company_id', $this->company->id)->where('source_type', 'customer_payment')->count(),
            'no customer_payment entry — that is the wrong direction for a payable',
        );

        $movement = DB::table('repository_movements')->where('payment_repository_id', $bank->id)->first();
        self::assertNotNull($movement);
        self::assertSame(MovementDirection::Out->value, $movement->direction, 'the campaign recorded direction IN for money going out');
    }

    /**
     * The refusal that DOES still stand: the AR-direction allocation routes post
     * Dr bank / Cr 411, so a payable offered to them is refused for being a
     * payable — not for being historical.
     */
    public function test_a_historical_ap_opening_is_still_refused_on_the_receivable_path(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ApOpenItems, $this->vendor, '100.000');
        $payment = $this->makeUnallocatedPayment('40.000', $this->vendor);

        $response = $this->actingAs($this->user)->postJson('/api/v1/smart-payment/apply-allocation', [
            'payment_id' => $payment->id,
            'allocation_method' => 'manual',
            'manual_allocations' => [
                ['document_id' => $opening->id, 'amount' => '40.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SUPPLIER_INVOICE_NOT_PAYABLE_HERE');
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
    }

    /**
     * Supplier payments require a repository with a `gl_account_id` to carry the
     * Cr-bank leg; the scaffold's cash register starts at 0.000 and refuses to go
     * overdrawn, which is a treasury rule, not an opening one.
     */
    private function ledgeredBankRepository(): PaymentRepository
    {
        return PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK-OQ74-'.substr((string) Str::uuid(), 0, 6),
            'name' => 'Compte bancaire',
            'type' => RepositoryType::BankAccount,
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'account_id' => null,
            'is_active' => true,
        ]);
    }

    /**
     * Fail closed where the evidence is genuinely absent: an `is_historical`
     * document with no posted import row behind it (a legacy import, a
     * hand-corrected row) has no provable side, so it is refused.
     */
    public function test_a_historical_opening_whose_side_cannot_be_resolved_is_refused(): void
    {
        $opening = $this->makeHistoricalOpening(OpeningBatchType::ArOpenItems, $this->customer, '100.000');

        // Break the link the resolver reads — the document keeps every marker
        // that identifies it as an opening, and loses only the AR/AP evidence.
        OpeningBalanceImportRow::query()
            ->where('mapped_entity_id', $opening->id)
            ->update(['mapped_entity_id' => null]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/payments', [
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '40.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'allocations' => [
                ['document_id' => $opening->id, 'amount' => '40.000'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.details.reason', 'historical_opening_provenance');
        $this->assertSame(0, PaymentAllocation::query()->where('document_id', $opening->id)->count());
    }
}
