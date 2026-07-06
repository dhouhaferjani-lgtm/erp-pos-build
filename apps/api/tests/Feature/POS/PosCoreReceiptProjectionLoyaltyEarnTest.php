<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\EarningRule;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 3 — `PosCoreReceiptProjection` loyalty-earn hook.
 *
 * Verifies that `earnLoyaltyPoints()` fires correctly after the receipt
 * projection succeeds, and that the earn-eligibility guards (receipt-type +
 * training-flag) and the idempotency anchor (fiscal_event_id fast-path) all
 * behave correctly.
 *
 * Mirrors `PosCoreReceiptProjectionTest` for the fiscal-event fixture builder,
 * but scopes setUp() to the loyalty-earn concern: adds an active Spend program,
 * a LoyaltyMember linked to `$contactId`, and an active Enrollment.
 */
final class PosCoreReceiptProjectionLoyaltyEarnTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $paymentMethodId;

    /**
     * A real Partner row — used as the buyer's `customer_id` in the canonical
     * payload so that `pos_receipts.partner_id` satisfies its FK constraint, and
     * as the `customer_id` on `LoyaltyMember` so `SaleEarningService::resolveMember()`
     * can find the enrolled member via the partner path.
     */
    private string $partnerId;

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
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        $this->paymentMethodId = $method->id;

        // Chart of accounts required by VoucherRedemptionService GL posting.
        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);

        // A real Partner satisfies the `pos_receipts.partner_id` FK constraint.
        // We use it as the buyer's `customer_id` so the loyalty member lookup via
        // `SaleEarningService::resolveMember()` can find the member by `customer_id`.
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => 'customer',
        ]);
        $this->partnerId = $partner->id;
    }

    // =================================================================
    // Loyalty fixture helpers
    // =================================================================

    /**
     * Seed an active Spend-type LoyaltyProgram (scoped to tenant) with one
     * active EarningRule at the given rate (points-per-currency-unit).
     */
    private function seedActiveSpendProgram(string $rate = '1'): LoyaltyProgram
    {
        $program = LoyaltyProgram::factory()->create([
            'tenant_id' => $this->tenantId,
            'status' => ProgramStatus::Active,
        ]);

        EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => EarningRuleType::Spend,
            'reward_value' => $rate,
            'is_active' => true,
            'conditions' => [],
        ]);

        return $program;
    }

    /**
     * Create a LoyaltyMember linked to `$this->partnerId` (via the `customer_id`
     * field) and enrol it in `$programId` with an Active status.
     *
     * `SaleEarningService::resolveMember()` checks `loyaltyable_type/id` then
     * falls back to `customer_id`. We seed via `customer_id` directly to avoid
     * needing a Contact row (no ContactFactory exists; Contact FK would fail).
     */
    private function enrollPartner(string $programId): Enrollment
    {
        $member = LoyaltyMember::factory()->create([
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->partnerId,
            'loyaltyable_type' => 'partner',
            'loyaltyable_id' => $this->partnerId,
        ]);

        return Enrollment::factory()->create([
            'program_id' => $programId,
            'member_id' => $member->id,
            'status' => EnrollmentStatus::Active,
            'current_balance' => '0.000',
        ]);
    }

    /**
     * Minimal buyer block for the canonical payload — maps `$this->partnerId`
     * as `customer_id` so `SaleEarningService::resolveMember()` finds the
     * LoyaltyMember, and satisfies `pos_receipts.partner_id` FK constraint.
     *
     * @return array<string, mixed>
     */
    private function buyerBlock(): array
    {
        return [
            'address' => null,
            'codice_fiscale' => null,
            'contact_id' => null,
            'customer_id' => $this->partnerId,
            'name' => 'Test Buyer',
            'tax_number' => null,
        ];
    }

    // =================================================================
    // 5 earn-trigger tests
    // =================================================================

    public function test_sale_with_enrolled_buyer_credits_points_once(): void
    {
        $program = $this->seedActiveSpendProgram('1');
        $enrollment = $this->enrollPartner($program->id);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: $this->buyerBlock(),
            total: '10.00',
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // Exactly one Earn transaction for the enrollment.
        $earns = Transaction::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('transaction_type', TransactionType::Earn)
            ->get();

        $this->assertCount(1, $earns);
        // rate=1, earnBase=normalize('10.00')='10.000' → points='10.000'.
        $this->assertSame('10.000', $earns->first()->amount);
    }

    public function test_sale_without_buyer_credits_nothing(): void
    {
        $program = $this->seedActiveSpendProgram('1');
        $this->enrollPartner($program->id);

        // buyer=null → contactId/partnerId=null → resolveMember() returns null → no earn.
        $event = $this->storeSaleReceiptFiscalEvent(buyer: null);

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(0, DB::table('loyalty_transactions')->count());
    }

    public function test_refund_receipt_credits_nothing(): void
    {
        // Codex B3: project the original SALE first so the REFUND can resolve
        // its original_receipt_reference without throwing OriginalReceiptUnresolvableException.
        $program = $this->seedActiveSpendProgram('1');
        $enrollment = $this->enrollPartner($program->id);

        // Step 1: project the original SALE (earns 1 Earn transaction).
        $saleEvent = $this->storeSaleReceiptFiscalEvent(
            buyer: $this->buyerBlock(),
            sequenceNumber: 1,
        );
        $projector = $this->app->make(PosCoreReceiptProjection::class);
        $projector->apply($saleEvent);

        $this->assertSame(
            1,
            DB::table('loyalty_transactions')->count(),
            'sanity: original SALE must create exactly one Earn transaction',
        );

        // Step 2: build+apply a REFUND event that resolves to the projected SALE.
        $refundEvent = $this->storeSaleReceiptFiscalEvent(
            buyer: $this->buyerBlock(),
            invoiceTypeCode: 'REFUND',
            originalReceiptReference: [
                'fiscal_event_id' => $saleEvent->id,
                'original_business_date' => now()->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'Test refund',
            ],
            sequenceNumber: 2,
        );
        $projector->apply($refundEvent);

        // REFUND maps to ReceiptType::Return → earnLoyaltyPoints guard returns early → no new earn.
        $this->assertSame(
            1,
            Transaction::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)
                ->count(),
            'REFUND receipt must not create any new Earn transaction',
        );
    }

    public function test_training_receipt_credits_nothing(): void
    {
        // Codex B2: training_flag=true → earnLoyaltyPoints guard returns early even
        // though receiptType=Sale.
        $program = $this->seedActiveSpendProgram('1');
        $this->enrollPartner($program->id);

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: $this->buyerBlock(),
            trainingFlag: true,
        );

        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        $this->assertSame(0, DB::table('loyalty_transactions')->count());
    }

    public function test_replaying_the_same_fiscal_event_credits_once(): void
    {
        // Rule 20 (idempotency): the fiscal_event_id fast-path guard in apply()
        // short-circuits the second call before earnLoyaltyPoints is ever reached.
        $program = $this->seedActiveSpendProgram('1');
        $enrollment = $this->enrollPartner($program->id);

        $event = $this->storeSaleReceiptFiscalEvent(buyer: $this->buyerBlock());

        $projector = $this->app->make(PosCoreReceiptProjection::class);
        $projector->apply($event);
        $projector->apply($event); // second apply → fast-path early return

        $this->assertSame(
            1,
            Transaction::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('transaction_type', TransactionType::Earn)
                ->count(),
            'Replaying the same fiscal event must not credit points twice',
        );
    }

    public function test_loyalty_earn_failure_does_not_break_the_sale(): void
    {
        // Bind a stub that always throws, BEFORE resolving the projection so the
        // container injects it into PosCoreReceiptProjection's constructor.
        $this->app->bind(
            LoyaltyEarningContract::class,
            fn () => new class implements LoyaltyEarningContract
            {
                public function earnForSale(SaleEarnContext $c): void
                {
                    throw new RuntimeException('boom');
                }
            },
        );

        $event = $this->storeSaleReceiptFiscalEvent(
            buyer: $this->buyerBlock(),
            total: '10.00',
        );

        // Resolve and apply AFTER the stub binding.
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        // The sale must have projected successfully despite the loyalty failure
        // — the earnLoyaltyPoints() try/catch boundary must have swallowed the
        // RuntimeException and left the outer DB::transaction intact.
        $this->assertTrue(
            DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->exists(),
            'pos_receipts row must exist even when loyalty earn throws',
        );
    }

    // =================================================================
    // Fixture builder — mirrors PosCoreReceiptProjectionTest with an added
    // `trainingFlag` parameter (Codex B2).
    // =================================================================

    /**
     * Persist a verified SALE_RECEIPT fiscal_events row directly via the
     * Eloquent model — bypasses OutboxIngestor (Task 19).
     *
     * Mirrors the sibling `PosCoreReceiptProjectionTest::storeSaleReceiptFiscalEvent`
     * exactly, except:
     *   (a) `trainingFlag` is a first-class parameter (Codex B2 — the sibling
     *       hardcodes `training_flag => false`).
     *   (b) Only the parameters needed by the loyalty-earn tests are exposed.
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride
     * @param  list<array<string, mixed>>|null  $lines
     * @param  list<array<string, mixed>>|null  $vatBreakdown
     * @param  array<string, mixed>|null  $buyer
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function storeSaleReceiptFiscalEvent(
        ?array $paymentLinesOverride = null,
        ?array $lines = null,
        ?array $vatBreakdown = null,
        string $total = '10.00',
        string $subtotal = '10.00',
        string $discountTotal = '0.00',
        string $taxTotal = '0.00',
        int $sequenceNumber = 1,
        ?array $buyer = null,
        string $invoiceTypeCode = 'SALE',
        ?array $originalReceiptReference = null,
        ?string $terminalId = null,
        bool $trainingFlag = false,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $payments = [];
        $payLines = $paymentLinesOverride ?? [
            ['payment_method_id' => $this->paymentMethodId, 'amount' => '10.00', 'method_code' => 'CASH'],
        ];
        foreach ($payLines as $pl) {
            $payments[] = [
                'amount' => $pl['amount'] ?? '10.00',
                'foreign_currency_amount' => $pl['foreign_currency_amount'] ?? null,
                'foreign_currency_code' => $pl['foreign_currency_code'] ?? null,
                'instrument_serial' => $pl['instrument_serial'] ?? null,
                'instrument_type' => $pl['instrument_type'] ?? null,
                'method_code' => $pl['method_code'] ?? 'CASH',
            ];
        }

        $lineItems = [];
        $rawLines = $lines ?? [
            ['sku' => 'X', 'unit_price' => '10.00', 'line_total' => '10.00', 'quantity' => '1', 'tax_rate' => '0', 'tax_amount' => '0.00'],
        ];
        foreach ($rawLines as $rl) {
            $lineItems[] = [
                'gtin' => $rl['gtin'] ?? null,
                'line_discount_amount' => $rl['discount_amount'] ?? '0.00',
                'line_discount_reason' => $rl['discount_reason'] ?? null,
                'line_subtotal' => $rl['line_total'] ?? '10.00',
                'line_vat' => $rl['tax_amount'] ?? '0.00',
                'name' => $rl['product_name'] ?? ($rl['sku'] ?? 'Default item'),
                'non_collected_subtype' => null,
                'product_id' => $rl['product_id'] ?? 'prod-default',
                'quantity' => str_contains((string) ($rl['quantity'] ?? '1.000'), '.') ? (string) ($rl['quantity'] ?? '1.000') : ((string) ($rl['quantity'] ?? '1')).'.000',
                'sku' => $rl['sku'] ?? 'SKU-X',
                'tax_category_code' => 'Z',
                'unit_price' => $rl['unit_price'] ?? '10.00',
                'variant_id' => $rl['variant_id'] ?? null,
                'variant_name' => $rl['variant_name'] ?? null,
                'variant_sku' => $rl['variant_sku'] ?? null,
                'vat_rate' => $this->padToScaleTwo($rl['tax_rate'] ?? '0'),
            ];
        }

        $vatRows = [];
        $rawVat = $vatBreakdown ?? [
            ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
        ];
        foreach ($rawVat as $vr) {
            $base = $vr['base'] ?? '0.00';
            $amount = $vr['amount'] ?? '0.00';
            /** @var numeric-string $baseN */
            $baseN = $base;
            /** @var numeric-string $amountN */
            $amountN = $amount;
            $gross = bcadd($baseN, $amountN, 2);
            $vatRows[] = [
                'gross_amount' => $gross,
                'net_amount' => $base,
                'rate' => $this->padToScaleTwo($vr['rate'] ?? '0'),
                'tax_category_code' => 'Z',
                'vat_amount' => $amount,
            ];
        }

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => $buyer,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-06-27T10:00:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => $lineItems,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => $payments,
            'receipt_uuid' => Str::uuid()->toString(),
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue de la Kasbah'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567ABCDE',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => $trainingFlag,
            'transaction_discount_amount' => $discountTotal,
            'transaction_discount_reason' => null,
            'vat_breakdown' => $vatRows,
            'vat_total' => $taxTotal,
            'vouchers_redeemed' => [],
        ];

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $terminalId ?? $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        $event = FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $terminalId ?? $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    private function padToScaleTwo(string $val): string
    {
        if ($val === '') {
            return '0.00';
        }
        if (str_contains($val, '.')) {
            return $val;
        }

        return $val.'.00';
    }

    /**
     * Spec §4 JCS canonical encoding (test-local). Sorts keys at every depth,
     * no whitespace. Sufficient to drive the projector path.
     *
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }
}
