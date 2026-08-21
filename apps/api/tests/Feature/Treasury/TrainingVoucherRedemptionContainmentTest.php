<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Voucher;
use App\Modules\Voucher\Domain\VoucherLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEDGER gate G-3, third surface — **a TRAINING receipt must not burn a REAL
 * voucher.**
 *
 * `PosCoreReceiptProjection::redeemVouchers()` carried exactly one gate:
 * `$receiptType !== ReceiptType::Sale`. But `resolveReceiptType()` maps
 * TRAINING to `ReceiptType::Sale` (it only diverts REFUND/VOID with a resolved
 * original), so a training receipt sailed straight through it and redemption
 * ran for real. Redemption is not a read-model write — it extinguishes the
 * customer's outstanding voucher balance AND posts
 * `Dr VoucherLiability / Cr PosTenderClearing`.
 *
 * That was survivable while `TreasuryReceiptBridge` also processed training
 * receipts, because the bridge's tender leg supplied the offsetting debit.
 * Once the bridge started returning early for training (the G-3 fix in this
 * same wave), the voucher credit lost its counterpart: a permanently unmatched
 * `PosTenderClearing` balance sitting on top of a real voucher the customer can
 * no longer spend. This file pins the second half of that pair.
 *
 * **Reachability — defense-in-depth, like its two siblings.** The voucher
 * TENDER carries no training check of its own (`VoucherTenderModal` applies
 * none, and the device deliberately keeps the redeemed voucher row in the
 * sealed payload), so nothing on the voucher side would stop this. What stops
 * it today is the same upstream refusal that covers the other two G-3 surfaces:
 * `FiscalEventEngine` will not seal a `training_flag` event on an operational
 * chain (`:565`, `:815-818`). So this guard closes the shape for legacy,
 * replayed, quarantine-repaired and directly-inserted events now, and closes it
 * on the live path the moment training authoring is enabled.
 *
 * The control arm is the load-bearing half: an otherwise identical NON-training
 * receipt must still redeem the voucher and post the GL pair, so the guard can
 * never be "fixed" by breaking redemption outright.
 *
 * Rule 20: the projector runs on a Horizon worker with NO `CompanyContext`
 * bound — `project()` clears it before every `apply()`.
 */
final class TrainingVoucherRedemptionContainmentTest extends TestCase
{
    use RefreshDatabase;

    private const VOUCHER_CODE = 'GC-TRAINING-GATE-0001';

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;
        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
            'fiscal_schema_version' => 3,
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Voucher Training Cashier']);
        $this->operatorId = $user->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'VOUCHER',
            'name' => 'Store Voucher',
        ]);

        // The redemption GL pair needs real accounts carrying the
        // VoucherLiability + PosTenderClearing system purposes.
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
    }

    // =================================================================
    // The gate
    // =================================================================

    public function test_training_receipt_does_not_redeem_a_real_voucher(): void
    {
        $voucher = $this->seedRedeemableVoucher();

        $this->project($this->buildEvent(training: true));

        $voucher->refresh();
        $this->assertSame(
            VoucherStatus::Issued,
            $voucher->status,
            'A training receipt must not change the voucher status.',
        );
        $this->assertSame(
            0,
            bccomp($voucher->current_balance, '50.00000', 5),
            'A training receipt must not draw down the voucher balance.',
        );
        $this->assertSame(
            0,
            VoucherLedger::query()
                ->where('voucher_id', $voucher->id)
                ->where('event', VoucherEvent::Redeemed)
                ->count(),
            'A training receipt must not append a Redeemed ledger row.',
        );
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'voucher_ledger')->count(),
            'A training receipt must not post the Dr VoucherLiability / Cr PosTenderClearing pair.',
        );
    }

    /**
     * The gate arm above runs with NO `CompanyContext` (rule 20, worker
     * fidelity). When this file was written that raised a real ambiguity: the
     * then-unfixed unbound-context bug threw inside `redeem()` before anything
     * was written, so the arm could "pass its zeros" for the WRONG reason, and
     * a future `try`/`catch` "fix" for that bug would have silently disarmed
     * the file.
     *
     * That specific bug is gone — LEDGER row C-5 fixed it by resolving the
     * voucher GL scale from the entity currency
     * (`GeneralLedgerService::createVoucherLedgerEntry()`), and the control arm
     * below now proves redemption works with the context CLEARED. This third
     * arm is kept anyway, because the ambiguity it closes is not specific to
     * that bug: with `CompanyContext` BOUND, redemption is unconditionally
     * capable of succeeding, so zeros here can only be caused by the training
     * guard — never by any exception path, present or future.
     */
    public function test_training_receipt_does_not_redeem_even_with_company_context_bound(): void
    {
        $voucher = $this->seedRedeemableVoucher();

        $event = $this->buildEvent(training: true);
        app(CompanyContext::class)->setCompanyId($this->companyId);
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
        $this->assertSame(0, bccomp($voucher->current_balance, '50.00000', 5));
        $this->assertSame(
            0,
            VoucherLedger::query()
                ->where('voucher_id', $voucher->id)
                ->where('event', VoucherEvent::Redeemed)
                ->count(),
        );
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'voucher_ledger')->count(),
        );
    }

    // =================================================================
    // Control — the identical NON-training receipt still redeems
    // =================================================================

    /**
     * The load-bearing half of the gate: an otherwise identical NON-training
     * receipt must still redeem the voucher and post the GL pair, so G-3 can
     * never be "satisfied" by breaking redemption outright.
     *
     * It runs through {@see project()} — `CompanyContext` CLEARED, the real
     * Horizon worker reality (rule 20) — exactly like the gate arm, so the two
     * differ ONLY in the training pair.
     *
     * **This arm used to bind the context on purpose**, to mask LEDGER row C-5:
     * `VoucherRedemptionService::redeem()` reached a bare no-arg `getScale()`
     * in `GeneralLedgerService::createVoucherLedgerEntry()`, which threw
     * `UnboundCompanyContextException` (F-RES-1) on any real worker and rolled
     * the whole SALE_RECEIPT projection back. C-5 is fixed — the voucher GL
     * scale now comes from the entity currency — so the mask is gone and this
     * arm doubles as an independent regression guard for it: if C-5 ever
     * regresses, this arm goes red without any context binding to hide it.
     */
    public function test_control_non_training_receipt_redeems_the_voucher_and_posts_the_gl_pair(): void
    {
        $voucher = $this->seedRedeemableVoucher();

        $this->project($this->buildEvent(training: false));

        $voucher->refresh();
        $this->assertNotSame(
            VoucherStatus::Issued,
            $voucher->status,
            'The control fixture must actually redeem — otherwise the gate arm proves nothing.',
        );
        $this->assertSame(0, bccomp($voucher->current_balance, '40.00000', 5));
        $this->assertSame(
            1,
            VoucherLedger::query()
                ->where('voucher_id', $voucher->id)
                ->where('event', VoucherEvent::Redeemed)
                ->count(),
        );
        $this->assertSame(
            1,
            JournalEntry::query()->where('source_type', 'voucher_ledger')->count(),
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function seedRedeemableVoucher(): Voucher
    {
        return Voucher::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => self::VOUCHER_CODE,
            'currency' => 'EUR',
            'initial_balance' => '50.00000',
            'current_balance' => '50.00000',
            'status' => VoucherStatus::Issued,
            'redeemable_at_terminal_id' => $this->terminalId,
            'issued_by_user_id' => $this->operatorId,
        ]);
    }

    private function project(FiscalEvent $event): void
    {
        // Rule 20 — the projector runs with NO CompanyContext on a worker.
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
    }

    /**
     * A single 10.00 EUR store-voucher tender. The ONLY difference between the
     * gate arm and the control arm is the training pair
     * (`invoice_type_code` + `training_flag`), which the validator requires to
     * move together (`FiscalPayloadConstraintValidator:907-914`).
     */
    private function buildEvent(bool $training): FiscalEvent
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);

        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $lineTotal = '10.00';

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Voucher Training Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            // Contract-legal training shape: the validator couples
            // `training_flag` to `invoice_type_code === 'TRAINING'`.
            'invoice_type_code' => $training ? 'TRAINING' : 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => $lineTotal,
                'line_vat' => '0.00',
                'name' => 'Voucher Training Gate Item',
                'non_collected_subtype' => null,
                'product_id' => $product->id,
                'quantity' => '1.000',
                'sku' => 'SKU-VTRAIN',
                'tax_category_code' => 'Z',
                'unit_price' => $lineTotal,
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => $lineTotal,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => self::VOUCHER_CODE,
                'instrument_type' => 'store_voucher',
                'method_code' => 'VOUCHER',
            ]],
            'receipt_uuid' => (string) Str::uuid(),
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Voucher Gate Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $lineTotal,
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => $lineTotal,
            'training_flag' => $training,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $lineTotal,
                'net_amount' => $lineTotal,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        $canonicalBytes = json_encode($payload, JSON_THROW_ON_ERROR);

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 3,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'server_received_at' => $eventTime,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }
}
