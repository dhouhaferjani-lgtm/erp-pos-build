<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
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
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Infrastructure\CurrencyScaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RecordingCurrencyScaleResolver;
use Tests\TestCase;

/**
 * LEDGER row **C-5** — a voucher-paid receipt must project on a REAL Horizon
 * worker, i.e. with **no `CompanyContext` bound** (house rule 20).
 *
 * The defect this file pins: `VoucherRedemptionService::redeem()` →
 * `GeneralLedgerService::createVoucherLedgerEntry()` resolved its bcmath scale
 * through the private `scale()` helper, which is a BARE no-arg
 * `CurrencyScaleResolver::getScale()`. `ApplyFiscalEventProjectionJob` binds no
 * `CompanyContext`, so that call threw `UnboundCompanyContextException`
 * (audit finding F-RES-1). `PosCoreReceiptProjection::redeemVouchers()` has no
 * try/catch — unlike its sibling `earnLoyaltyPoints()` — so the exception
 * escaped `apply()`, rolled the ENTIRE `SALE_RECEIPT` projection transaction
 * back, and the job retried forever. Any ordinary (non-training) receipt paid
 * with a store voucher was unprojectable in production.
 *
 * NO arm below may bind `CompanyContext`. Binding it restores the
 * request-context comfort that a queue worker never has, and makes every arm
 * pass against the broken code — which is exactly how this bug survived until
 * the G-3 lane's control arm tripped over it.
 *
 * Three arms:
 *
 *  1. **TND, scale 3, full projection.** The receipt tenders `10.005` — a legal
 *     TND amount whose third decimal is BELOW the minor unit of a scale-2
 *     currency. At scale 2 `bcmul` truncates the GL legs to `10.00` while the
 *     voucher ledger still draws down `10.005`, desynchronising ledger from GL.
 *  2. **EUR, scale 2, full projection.** The same flow at the other scale.
 *  3. **The seam, TND entity inside a EUR company.** See its own docblock — it
 *     is the arm that separates "resolved from the entity currency" from
 *     "hardcoded" and from "read off the company record".
 *
 * Every arm wraps the container's resolver in a pass-through
 * {@see RecordingCurrencyScaleResolver} (it fakes nothing — it delegates and
 * records, with caller attribution), so the assertions are about the argument
 * the seam actually passed, not merely about a value that would agree at more
 * than one scale.
 *
 * **Mutation-verified.** Against the fixed code, each of these fails:
 *   - `$scale = $this->scale()` (the original bug) → all 3 arms, UnboundCompanyContextException
 *   - `$scale = 2`                                 → all 3 arms
 *   - `$scale = 3`                                 → all 3 arms (no resolver call attributed to the seam)
 *   - scale from `currencyCodeForCompany()`        → arm 3 (records EUR, not TND)
 */
final class VoucherRedemptionProjectionWorkerContextTest extends TestCase
{
    use RefreshDatabase;

    private const VOUCHER_CODE = 'GC-C5-WORKER-CTX-0001';

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $operatorId;

    private RecordingCurrencyScaleResolver $scaleRecorder;

    // =================================================================
    // The gate — TND (scale 3), worker reality: NO CompanyContext
    // =================================================================

    public function test_voucher_paid_receipt_projects_on_a_worker_with_no_company_context(): void
    {
        $this->bootCompany(countryCode: 'TN', currency: 'TND');
        $voucher = $this->seedRedeemableVoucher('TND');

        $event = $this->buildEvent(currency: 'TND', currencyScale: 3, amount: '10.005');

        // Rule 20 — the projector runs on a Horizon worker with NO CompanyContext.
        // Do NOT bind it to make this pass.
        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $voucher->refresh();

        $this->assertSame(
            VoucherStatus::PartiallyRedeemed,
            $voucher->status,
            'A voucher-paid receipt must actually redeem the voucher on a worker.',
        );
        $this->assertSame(
            0,
            bccomp($voucher->current_balance, '39.99500', 5),
            'The voucher must be drawn down by the full tendered 10.005 TND.',
        );

        /** @var VoucherLedger|null $redemption */
        $redemption = VoucherLedger::query()
            ->where('voucher_id', $voucher->id)
            ->where('event', VoucherEvent::Redeemed)
            ->first();

        $this->assertNotNull($redemption, 'A Redeemed ledger row must be appended.');
        $this->assertSame('TND', $redemption->currency);
        $this->assertNotNull(
            $redemption->gl_journal_entry_id,
            'The redemption ledger row must carry its GL journal entry id.',
        );

        $this->assertGlPair(expectedAmount: '10.005', scale: 3);

        // The scale that governed the GL legs came from the ENTITY currency.
        $this->assertVoucherGlScaleResolvedFrom('TND', 3);
    }

    // =================================================================
    // Scale discrimination — EUR (scale 2), same worker reality
    // =================================================================

    public function test_resolved_scale_follows_the_entity_currency_not_a_hardcoded_three(): void
    {
        $this->bootCompany(countryCode: 'FR', currency: 'EUR');
        $voucher = $this->seedRedeemableVoucher('EUR');

        $event = $this->buildEvent(currency: 'EUR', currencyScale: 2, amount: '10.00');

        app(CompanyContext::class)->clear();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $voucher->refresh();
        $this->assertSame(0, bccomp($voucher->current_balance, '40.00000', 5));

        $this->assertGlPair(expectedAmount: '10.00', scale: 3);

        // The load-bearing half: the SAME seam asked for EUR here and for TND
        // in the arm above, and got 2 — not the 3 a hardcoded scale or a
        // TND-shaped assumption would have produced.
        $this->assertVoucherGlScaleResolvedFrom('EUR', 2);
        $this->assertNotContains(
            'TND',
            $this->scaleRecorder->currenciesSeen(),
            'A EUR receipt must never resolve scale against another currency.',
        );
    }

    // =================================================================
    // Scale discrimination, at the seam — the arm that actually bites
    // =================================================================

    /**
     * The two full-flow arms above cannot, by themselves, distinguish the fix
     * from a scale HARDCODED to 3: 3 is the global maximum in
     * {@see CurrencyScale}, `bcmul` only ever truncates, and
     * the whole-projection recorder still sees a `getScale('EUR')` from
     * `VoucherRedemptionService:150` no matter what the GL layer does. Verified
     * by mutation: `$scale = 2` fails the TND arm, `$scale = 3` passes both.
     *
     * So this arm isolates the seam. It calls `createVoucherLedgerEntry()`
     * directly with the recorder reset immediately beforehand, so every
     * recorded call belongs to the method under test, and it puts the entity
     * currency (TND, scale 3) DELIBERATELY at odds with the company currency
     * (EUR, scale 2) — the company being the other plausible source, and the
     * one `GeneralLedgerService::currencyCodeForCompany()` sitting right below
     * `scale()` would have supplied.
     *
     * It therefore fails on all three wrong answers:
     *   - bare no-arg `getScale()`   → UnboundCompanyContextException (context cleared)
     *   - company-sourced currency   → records 'EUR', truncates 10.005 to 10.00
     *   - hardcoded constant         → records NO getScale call at all
     */
    public function test_voucher_gl_scale_comes_from_the_ledger_row_currency_not_the_company_or_a_constant(): void
    {
        $this->bootCompany(countryCode: 'FR', currency: 'EUR');
        $voucher = $this->seedRedeemableVoucher('TND');

        $redemption = new VoucherLedger;
        $redemption->id = (string) Str::uuid();
        $redemption->tenant_id = $this->tenantId;
        $redemption->company_id = $this->companyId;
        $redemption->voucher_id = $voucher->id;
        $redemption->event = VoucherEvent::Redeemed;
        $redemption->amount = '-10.00500';
        $redemption->currency = 'TND';
        $redemption->user_id = $this->operatorId;
        $redemption->occurred_at = now();

        app(CompanyContext::class)->clear();
        $this->scaleRecorder->reset();

        $this->app->make(GeneralLedgerService::class)->createVoucherLedgerEntry($redemption, $voucher);

        $this->assertVoucherGlScaleResolvedFrom('TND', 3);
        $this->assertGlPair(expectedAmount: '10.005', scale: 3);
    }

    // =================================================================
    // Assertions
    // =================================================================

    private function assertGlPair(string $expectedAmount, int $scale): void
    {
        /** @var JournalEntry|null $entry */
        $entry = JournalEntry::query()
            ->where('source_type', 'voucher_ledger')
            ->with('lines')
            ->first();

        $this->assertNotNull(
            $entry,
            'The Dr VoucherLiability / Cr PosTenderClearing pair must be posted.',
        );
        $this->assertCount(2, $entry->lines);

        $debit = $entry->lines->first(fn ($line): bool => bccomp((string) $line->debit, '0', $scale) > 0);
        $credit = $entry->lines->first(fn ($line): bool => bccomp((string) $line->credit, '0', $scale) > 0);

        $this->assertNotNull($debit, 'The entry must carry a debit leg.');
        $this->assertNotNull($credit, 'The entry must carry a credit leg.');

        $this->assertSame(
            0,
            bccomp((string) $debit->debit, $expectedAmount, 5),
            sprintf('Debit leg must be %s at the entity currency scale, got %s.', $expectedAmount, (string) $debit->debit),
        );
        $this->assertSame(
            0,
            bccomp((string) $credit->credit, $expectedAmount, 5),
            sprintf('Credit leg must be %s at the entity currency scale, got %s.', $expectedAmount, (string) $credit->credit),
        );
    }

    /**
     * The seam assertion, attributed to `createVoucherLedgerEntry` specifically.
     *
     * Attribution is what makes this bite. Asserting merely that SOME call
     * resolved the entity currency proves nothing — a projection resolves scale
     * many times for many legitimate reasons (`VoucherRedemptionService:150`
     * resolves the tender currency; `GeneralLedgerHashService::serializeForHashing`
     * legitimately resolves the COMPANY currency to seal the entry). Only the
     * caller-attributed record can tell the seam under test from its
     * neighbours, and only it can detect a hardcoded scale — which makes no
     * call at all and so leaves `currencyResolvedBy()` null.
     */
    private function assertVoucherGlScaleResolvedFrom(string $currency, int $expectedScale): void
    {
        $seam = 'createVoucherLedgerEntry';

        $this->assertNotNull(
            $this->scaleRecorder->currencyResolvedBy($seam),
            "{$seam}() resolved no scale at all — a hardcoded constant, not the entity currency (rule 19).\n"
            ."Recorded:\n".$this->scaleRecorder->describe(),
        );
        $this->assertSame(
            $currency,
            $this->scaleRecorder->currencyResolvedBy($seam),
            "{$seam}() must resolve scale from the ENTITY currency — never the company record or the ambient context.\n"
            ."Recorded:\n".$this->scaleRecorder->describe(),
        );
        $this->assertSame(
            $expectedScale,
            $this->scaleRecorder->scaleResolvedBy($seam),
            sprintf('%s must resolve to scale %d.', $currency, $expectedScale),
        );
        $this->assertNotContains(
            '<bare>',
            $this->scaleRecorder->currenciesSeen(),
            "No bare no-arg getScale() may run on a projection path (rule 19/20, F-RES-1).\n"
            ."Recorded:\n".$this->scaleRecorder->describe(),
        );
    }

    // =================================================================
    // Fixture
    // =================================================================

    private function bootCompany(string $countryCode, string $currency): void
    {
        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => $countryCode,
            'currency' => $currency,
        ]);
        $this->companyId = $company->id;

        // Chart seeding is a request-shaped operation; bind for the fixture only
        // and clear again before the projection runs.
        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
            'fiscal_schema_version' => 3,
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'C5 Voucher Cashier']);
        $this->operatorId = $user->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'VOUCHER',
            'name' => 'Store Voucher',
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);

        $this->installScaleRecorder();
    }

    /**
     * Wrap — never replace — the real resolver, so every scale in this test is
     * the production value and only the CALL is observed.
     */
    private function installScaleRecorder(): void
    {
        /** @var CurrencyScaleResolverInterface $real */
        $real = $this->app->make(CurrencyScaleResolverInterface::class);
        $this->assertInstanceOf(CurrencyScaleResolver::class, $real);
        $this->scaleRecorder = new RecordingCurrencyScaleResolver($real);
        $recorder = $this->scaleRecorder;

        $this->app->singleton(
            CurrencyScaleResolverInterface::class,
            static fn (): RecordingCurrencyScaleResolver => $recorder,
        );
    }

    private function seedRedeemableVoucher(string $currency): Voucher
    {
        return Voucher::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => self::VOUCHER_CODE,
            'currency' => $currency,
            'initial_balance' => '50.00000',
            'current_balance' => '50.00000',
            'status' => VoucherStatus::Issued,
            'redeemable_at_terminal_id' => $this->terminalId,
            'issued_by_user_id' => $this->operatorId,
        ]);
    }

    /**
     * An ordinary (NON-training) single-line sale settled entirely by one store
     * voucher tender.
     */
    private function buildEvent(string $currency, int $currencyScale, string $amount): FiscalEvent
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);

        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $zero = bcadd('0', '0', $currencyScale);

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'C5 Voucher Cashier',
            'consumption_mode' => null,
            'currency_code' => $currency,
            'currency_scale' => $currencyScale,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => $zero,
                'line_discount_reason' => null,
                'line_subtotal' => $amount,
                'line_vat' => $zero,
                'name' => 'C5 Voucher Gate Item',
                'non_collected_subtype' => null,
                'product_id' => $product->id,
                'quantity' => '1.000',
                'sku' => 'SKU-C5VOUCHER',
                'tax_category_code' => 'Z',
                'unit_price' => $amount,
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => $amount,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => self::VOUCHER_CODE,
                'instrument_type' => 'store_voucher',
                'method_code' => 'VOUCHER',
            ]],
            'receipt_uuid' => (string) Str::uuid(),
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'C5 Voucher Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $amount,
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => $amount,
            'training_flag' => false,
            'transaction_discount_amount' => $zero,
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $amount,
                'net_amount' => $amount,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => $zero,
            ]],
            'vat_total' => $zero,
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
