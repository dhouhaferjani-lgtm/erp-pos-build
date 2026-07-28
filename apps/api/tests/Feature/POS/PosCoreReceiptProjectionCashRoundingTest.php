<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

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
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Loyalty\LoyaltyEarningContract;
use App\Shared\Contracts\Loyalty\SaleEarnContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Cash-rounding Phase 1 / Task 8 — `PosCoreReceiptProjection` v3-gated writes.
 *
 * `fiscal_events.event_version` is the SINGLE cutover discriminator shared by
 * this read model and the Treasury bridge, so the two can never diverge:
 *
 *   - **v1/v2** events must project BYTE-IDENTICALLY to today. All four
 *     rounding-era columns (`cash_rounding_adjustment`,
 *     `cash_rounding_denomination`, `change_due`, `tolerance_writeoff`) stay
 *     NULL, on first apply AND on replay. Pinned by
 *     `test_v2_event_writes_nothing_new`.
 *   - **v3** events write the two signed rounding columns, plus
 *     `change_due = max(0, Σ payments − total)` and
 *     `tolerance_writeoff = max(0, total − Σ payments)` (NULL on training).
 *
 * The `pos_receipts_totals` CHECK (Task 7) is
 * `total = subtotal + tax_amount - discount_amount + COALESCE(cash_rounding_adjustment, 0)`,
 * so on PostgreSQL a v3 row that omits the adjustment column fails the CHECK
 * outright — these tests are PG-meaningful by construction. Every fixture
 * below therefore satisfies `total = subtotal + adjustment`.
 *
 * Rule 20: the projector runs on a Horizon worker with NO `CompanyContext`.
 * Every `apply()` here goes through `project()`, which clears the context
 * first so the tests reproduce the worker reality rather than masking it.
 */
final class PosCoreReceiptProjectionCashRoundingTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $paymentMethodId;

    /** Set by the `captureLoyaltyEarning()` double. */
    private ?SaleEarnContext $capturedEarn = null;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        // `tunisia()` pins `companies.country_code = 'TN'`, which is what
        // `reconcileRoundingPolicy()` joins on to reach the live policy row.
        $company = Company::factory()->tunisia()->create(['tenant_id' => $this->tenantId]);
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

        $this->seedTunisianRoundingPolicy();
    }

    // =================================================================
    // v3-gated column writes
    // =================================================================

    public function test_v3_event_writes_the_rounding_columns(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($receipt->cash_rounding_adjustment), '-0.023', 3));
        $this->assertSame(0, bccomp($this->numeric($receipt->cash_rounding_denomination), '0.0500', 4));
    }

    public function test_v2_event_writes_nothing_new(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(total: '9.973', subtotal: '9.973', eventVersion: 2);

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertNull($receipt->cash_rounding_adjustment);
        $this->assertNull($receipt->cash_rounding_denomination);
        $this->assertNull($receipt->change_due);
        $this->assertNull($receipt->tolerance_writeoff);
    }

    /**
     * The v2 no-write guarantee must survive a REPLAY too — a re-dispatched
     * historical event must not acquire rounding columns retroactively.
     */
    public function test_v2_replay_still_writes_nothing_new(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(total: '9.973', subtotal: '9.973', eventVersion: 2);

        $this->project($event);
        $this->project($event);

        $this->assertSame(1, Receipt::query()->where('fiscal_event_id', $event->id)->count());

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertNull($receipt->cash_rounding_adjustment);
        $this->assertNull($receipt->cash_rounding_denomination);
        $this->assertNull($receipt->change_due);
        $this->assertNull($receipt->tolerance_writeoff);
    }

    public function test_v1_event_writes_nothing_new(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(total: '9.973', subtotal: '9.973');

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertNull($receipt->cash_rounding_adjustment);
        $this->assertNull($receipt->cash_rounding_denomination);
        $this->assertNull($receipt->change_due);
        $this->assertNull($receipt->tolerance_writeoff);
    }

    public function test_v3_shortfall_becomes_a_tolerance_writeoff(): void
    {
        // total 9.950, tendered 9.900 => shortfall 0.050
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($receipt->tolerance_writeoff), '0.050', 3));
        $this->assertSame(0, bccomp($this->numeric($receipt->change_due), '0.000', 3));
    }

    public function test_v3_over_tender_becomes_change_due(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            paymentLinesOverride: [['amount' => '10.000', 'method_code' => 'CASH']],
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($receipt->change_due), '0.050', 3));
        $this->assertSame(0, bccomp($this->numeric($receipt->tolerance_writeoff), '0.000', 3));
    }

    public function test_training_receipt_records_no_tolerance_writeoff(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            paymentLinesOverride: [['amount' => '9.900', 'method_code' => 'CASH']],
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
            training: true,
        );

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertNull($receipt->tolerance_writeoff);
    }

    public function test_replay_is_idempotent(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);
        $this->project($event);

        $this->assertSame(1, Receipt::query()->where('fiscal_event_id', $event->id)->count());
    }

    // =================================================================
    // Loyalty earn base
    // =================================================================

    public function test_loyalty_earn_base_excludes_the_rounding_adjustment(): void
    {
        // Round UP so the earn base is provably lower than the collected total.
        $event = $this->storeSaleReceiptFiscalEvent(
            total: '10.000',
            subtotal: '9.973',
            cashRoundingAdjustment: '0.027',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->captureLoyaltyEarning();

        $this->project($event);

        $captured = $this->capturedEarn;
        $this->assertInstanceOf(SaleEarnContext::class, $captured);
        $this->assertSame(0, bccomp($this->numeric($captured->earnBase), '9.973', 3));
    }

    /**
     * v1/v2 loyalty behaviour is unchanged: with no adjustment in the payload
     * the earn base stays the projected total.
     */
    public function test_loyalty_earn_base_is_unchanged_on_a_v2_event(): void
    {
        $event = $this->storeSaleReceiptFiscalEvent(total: '9.973', subtotal: '9.973', eventVersion: 2);

        $this->captureLoyaltyEarning();

        $this->project($event);

        $captured = $this->capturedEarn;
        $this->assertInstanceOf(SaleEarnContext::class, $captured);
        $this->assertSame(0, bccomp($this->numeric($captured->earnBase), '9.973', 3));
    }

    // =================================================================
    // Signed-vs-live denomination reconciliation
    // =================================================================

    public function test_policy_mismatch_projects_and_alerts(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => '0.1000',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $this->assertDatabaseHas('pos_receipts', ['fiscal_event_id' => $event->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    public function test_matching_policy_denomination_does_not_alert_despite_differing_string_scale(): void
    {
        // PG returns '0.0500' from decimal(15,4); the signed value is '0.050'.
        // A string comparison would false-alarm on EVERY rounded receipt.
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => '0.0500',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $this->assertDatabaseMissing('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_id' => $event->id,
        ]);
    }

    /**
     * The alert must be single-shot per fiscal event.
     *
     * Replaying `apply()` does NOT exercise this: the second call exits at the
     * `fiscal_event_id` fast-path probe long before the reconciliation, so a
     * replay-based test would pass with the probe deleted. The probe is
     * therefore driven directly — an alert row is planted BEFORE the single
     * `apply()`, and the count must stay at 1.
     */
    public function test_policy_mismatch_alert_is_not_duplicated_when_one_already_exists(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => '0.1000',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->plantPolicyMismatchAlert($event);

        $this->project($event);

        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'pos.rounding.policy_mismatch')
            ->where('aggregate_id', $event->id)
            ->count());
    }

    /**
     * The canonical drift incident: an operator switches cash rounding OFF
     * while a stale terminal keeps signing rounded receipts.
     *
     * Migration A2 seeds TN with `cash_rounding_enabled = false` AND a
     * denomination of 0.0500, so a reconciliation that read the raw column
     * would compare 0.0500 against 0.0500 and stay silent on exactly the
     * scenario the telemetry exists for. Reading the EFFECTIVE policy through
     * `PosPaymentPolicyResolver` makes "rounding disabled" a mismatch.
     */
    public function test_policy_with_rounding_disabled_alerts_despite_matching_stored_denomination(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => false,
            'cash_rounding_denomination' => '0.0500',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $this->assertDatabaseHas('pos_receipts', ['fiscal_event_id' => $event->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    /**
     * A stored denomination the resolver REFUSES to emit is not the live
     * policy either. 0.0025 does not round-trip at the TND scale-3 currency
     * scale (`bcformatStrict` truncates it to 0.002), so the resolver reports
     * rounding disabled — and a receipt signed against it must be flagged.
     */
    public function test_resolver_rejected_stored_denomination_alerts(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '0.0025',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $this->assertDatabaseHas('pos_receipts', ['fiscal_event_id' => $event->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
        ]);
    }

    /**
     * Telemetry must never cost a sale.
     *
     * Soft-deleting the company makes the reconciliation throw: the resolver's
     * `Company::findOrFail` raises `ModelNotFoundException`, and had it not,
     * `AuditService::record` would raise `InvalidArgumentException` from
     * `AuditEvent`'s `Company::find(...) === null` guard. Either way the
     * receipt — already inserted, with lines, VAT rows and payments — must
     * survive, because an uncontained throw here rolls the whole projection
     * back and the deterministic retry dead-letters the event.
     */
    public function test_a_failing_reconciliation_does_not_roll_back_the_receipt(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => '0.1000',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.950',
            subtotal: '9.973',
            cashRoundingAdjustment: '-0.023',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        Company::query()->findOrFail($this->companyId)->delete();

        $this->project($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(0, bccomp($this->numeric($receipt->cash_rounding_adjustment), '-0.023', 3));
        $this->assertGreaterThan(0, DB::table('pos_receipt_lines')->where('receipt_id', $receipt->id)->count());
        $this->assertGreaterThan(0, DB::table('pos_receipt_payments')->where('receipt_id', $receipt->id)->count());

        // The alert itself is the thing that was lost, and that is the trade.
        $this->assertDatabaseMissing('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_id' => $event->id,
        ]);
    }

    /**
     * A v3 receipt with a ZERO adjustment never signed a denomination, so it
     * must not be reconciled against policy at all.
     */
    public function test_zero_adjustment_v3_receipt_is_not_reconciled(): void
    {
        DB::table('country_payment_settings')->where('country_code', 'TN')->update([
            'cash_rounding_denomination' => '0.1000',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            total: '9.973',
            subtotal: '9.973',
            cashRoundingAdjustment: '0.000',
            cashRoundingDenomination: '0.050',
            eventVersion: 3,
        );

        $this->project($event);

        $this->assertDatabaseMissing('audit_events', [
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_id' => $event->id,
        ]);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /**
     * Apply the projector the way Horizon does — with NO `CompanyContext`
     * bound (rule 20). Binding the context in `setUp()` and leaving it bound
     * would mask any accidental no-arg scale/company resolution.
     */
    private function project(FiscalEvent $event): void
    {
        app(CompanyContext::class)->clear();

        app(PosCoreReceiptProjection::class)->apply($event);
    }

    /**
     * Read a decimal value as a numeric-string, failing the test loudly (and
     * narrowing the type honestly, via `fail(): never`) when the projector
     * left the column NULL or wrote something non-numeric.
     *
     * @return numeric-string
     */
    private function numeric(?string $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail(sprintf('Expected a numeric value, got %s.', var_export($value, true)));
        }

        return $value;
    }

    /**
     * `country_payment_settings.country_code` is FK-constrained on
     * `countries.code`, and migrations alone leave `countries` empty in a
     * `RefreshDatabase` run, so migration A2's TN upsert self-skips. Seed both
     * rows here so the policy lookup has something real to read.
     */
    /**
     * Plant an existing `pos.rounding.policy_mismatch` alert on the same
     * `(tenant_id, event_type, aggregate_type, aggregate_id)` key the
     * projection probes, so the probe is exercised directly rather than
     * through a replay that never reaches it.
     */
    private function plantPolicyMismatchAlert(FiscalEvent $event): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'user_id' => $this->operatorId,
            'event_type' => 'pos.rounding.policy_mismatch',
            'aggregate_type' => 'fiscal_event',
            'aggregate_id' => $event->id,
            'payload' => json_encode(['planted' => true]),
            'metadata' => json_encode([]),
            'event_hash' => str_repeat('0', 64),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTunisianRoundingPolicy(): void
    {
        DB::table('countries')->insertOrIgnore([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            // NOT the column default (2). `PosPaymentPolicyResolver` reads the
            // company currency scale from HERE, and at scale 2 the 0.050
            // denomination would fail its round-trip check and report rounding
            // disabled — every test would then "pass" through the wrong branch.
            'currency_decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
        ]);

        DB::table('country_payment_settings')->where('country_code', 'TN')->delete();
        DB::table('country_payment_settings')->insert([
            'id' => (string) Str::uuid(),
            'country_code' => 'TN',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.1000',
            'pos_tolerance_enabled' => true,
            'cash_rounding_enabled' => true,
            'cash_rounding_denomination' => '0.0500',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Swap the POS→Loyalty seam for a capturing double so the earn base can be
     * asserted directly, without standing up a program / member / enrollment.
     * Read `$this->capturedEarn` after `project()`.
     */
    private function captureLoyaltyEarning(): void
    {
        $capture = function (SaleEarnContext $context): void {
            $this->capturedEarn = $context;
        };

        $this->app->instance(LoyaltyEarningContract::class, new class($capture) implements LoyaltyEarningContract
        {
            /**
             * @param  Closure(SaleEarnContext): void  $capture
             */
            public function __construct(private readonly Closure $capture) {}

            public function earnForSale(SaleEarnContext $context): void
            {
                ($this->capture)($context);
            }
        });
    }

    /**
     * Fiscal-event fixture builder — copied from
     * `Tests\Feature\Fiscal\PosCoreReceiptProjectionTest::storeSaleReceiptFiscalEvent()`
     * and extended with the three cash-rounding parameters. `$eventVersion`
     * stamps BOTH `fiscal_events.event_version` and the canonical envelope's
     * `event_version`; the two payload keys are added only at v3+, exactly as
     * `SaleReceiptPayload::toArray()` emits them.
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride  legacy {payment_method_id, amount, method_code, instrument_*} shape
     * @param  list<array<string, mixed>>|null  $lines  legacy {sku, unit_price, line_total, quantity, tax_rate, tax_amount, product_id} shape
     * @param  list<array<string, mixed>>|null  $vatBreakdown  legacy {rate, base, amount} shape
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
        string $invoiceTypeCode = 'SALE',
        bool $training = false,
        ?string $cashRoundingAdjustment = null,
        ?string $cashRoundingDenomination = null,
        int $eventVersion = 1,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $payments = [];
        $payLines = $paymentLinesOverride ?? [
            ['payment_method_id' => $this->paymentMethodId, 'amount' => $total, 'method_code' => 'CASH'],
        ];
        foreach ($payLines as $pl) {
            $payments[] = [
                'amount' => $pl['amount'] ?? $total,
                'foreign_currency_amount' => $pl['foreign_currency_amount'] ?? null,
                'foreign_currency_code' => $pl['foreign_currency_code'] ?? null,
                'instrument_serial' => $pl['instrument_serial'] ?? null,
                'instrument_type' => $pl['instrument_type'] ?? null,
                'method_code' => $pl['method_code'] ?? 'CASH',
            ];
        }

        $lineItems = [];
        $rawLines = $lines ?? [
            ['sku' => 'X', 'unit_price' => $subtotal, 'line_total' => $subtotal, 'quantity' => '1', 'tax_rate' => '0', 'tax_amount' => '0.00'],
        ];
        foreach ($rawLines as $rl) {
            $lineItems[] = [
                'gtin' => null,
                'line_discount_amount' => $rl['discount_amount'] ?? '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => $rl['line_total'] ?? $subtotal,
                'line_vat' => $rl['tax_amount'] ?? '0.00',
                'name' => $rl['product_name'] ?? ($rl['sku'] ?? 'Default item'),
                'non_collected_subtype' => null,
                'product_id' => $rl['product_id'] ?? 'prod-default',
                'quantity' => str_contains((string) ($rl['quantity'] ?? '1.000'), '.')
                    ? (string) ($rl['quantity'] ?? '1.000')
                    : ((string) ($rl['quantity'] ?? '1')).'.000',
                'sku' => $rl['sku'] ?? 'SKU-X',
                'tax_category_code' => 'Z',
                'unit_price' => $rl['unit_price'] ?? $subtotal,
                'variant_id' => null,
                'variant_name' => null,
                'variant_sku' => null,
                'vat_rate' => $this->padToScaleTwo($rl['tax_rate'] ?? '0'),
            ];
        }

        $vatRows = [];
        $rawVat = $vatBreakdown ?? [
            ['rate' => '0', 'base' => $subtotal, 'amount' => '0.00'],
        ];
        foreach ($rawVat as $vr) {
            /** @var numeric-string $baseN */
            $baseN = $vr['base'] ?? '0.00';
            /** @var numeric-string $amountN */
            $amountN = $vr['amount'] ?? '0.00';
            $vatRows[] = [
                'gross_amount' => bcadd($baseN, $amountN, 3),
                'net_amount' => $baseN,
                'rate' => $this->padToScaleTwo($vr['rate'] ?? '0'),
                'tax_category_code' => 'Z',
                'vat_amount' => $amountN,
            ];
        }

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            // TND / scale 3. The money in these fixtures is scale-3
            // ("9.973", "-0.023"), which a scale-2 currency could never have
            // survived ingestion with — EUR here would be a fixture that
            // cannot exist in production.
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => $lineItems,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => $payments,
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 avenue Habib Bourguiba'],
                'name' => 'Default Seller SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => $training,
            'transaction_discount_amount' => $discountTotal,
            'transaction_discount_reason' => null,
            'vat_breakdown' => $vatRows,
            'vat_total' => $taxTotal,
            'vouchers_redeemed' => [],
        ];

        // The two rounding keys exist ONLY at v3+ — a v1/v2 payload must stay
        // key-for-key identical to what the device signs today.
        if ($eventVersion >= 3) {
            $payload['cash_rounding_adjustment'] = $cashRoundingAdjustment;
            $payload['cash_rounding_denomination'] = $cashRoundingDenomination;
        }

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => $eventVersion,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
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
     * Spec §4 JCS canonical encoding (test-local) — sorts keys at every depth.
     *
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
