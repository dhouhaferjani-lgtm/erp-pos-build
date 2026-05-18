<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
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
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 22 — `TreasuryReceiptBridge::apply()` (spec v7 §7.4 + §13 + SoT §13.6/D16).
 *
 * Verifies the Treasury-operational projector that runs only when the
 * Treasury module is active and translates a verified `SALE_RECEIPT`
 * fiscal event into:
 *   - one `payments` row per `payload['payment_lines'][]` entry, stamped
 *     `origin = PaymentOrigin::Pos` and `fiscal_event_id = $event->id`
 *     (spec §13 writer row 1).
 *   - one `general_ledger_entries` (and `journal_lines`) row per Payment
 *     via `GeneralLedgerService::createPOSPaymentEntry()`, posted
 *     immediately and back-linked via `payments.journal_entry_id`.
 *
 * Discriminated-union variants covered:
 *   - single-payment (cash)
 *   - split-payment (two payment_lines)
 *   - voucher tender (instrument_type=store_voucher carries no
 *     Treasury-side voucher logic — that's POS-core; the bridge still
 *     writes the Treasury Payment row + GL post)
 *   - cross-tenant repository_id fail-closed
 *   - missing repository_id fail-loud
 *
 * Idempotency exercised: re-apply produces no duplicate Payment rows
 * and no duplicate GL entries (the (fiscal_event_id, origin=pos) probe
 * is the projector-level guard documented in the bridge class).
 *
 * Boundary discipline: a deployment without a pre-existing `pos_receipts`
 * row for the event (POS-core projection not yet run) is handled as a
 * deferred bail-out — the bridge does not crash; Task 23 retries.
 */
final class TreasuryReceiptBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $paymentMethodId;

    private string $repositoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;

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

        // Mirrored reference data — payment_methods (inbound seam,
        // SoT §13.6/D16). Tenant+company-scoped per the test fixture
        // pattern carried forward from Task 21.
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        $this->paymentMethodId = $method->id;

        // Seed chart of accounts so GeneralLedgerService::createPOSPaymentEntry
        // can resolve `product_revenue` by system purpose. Without this seed
        // every bridge test that posts a GL entry would die at the resolver.
        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);

        // Payment repository with a real GL account_id wired through.
        // Account::findByPurposeOrFail is what GeneralLedgerService uses
        // internally (`getAccountByPurpose`); mirroring the production
        // resolution here makes the test setup fail loudly if the chart
        // seed changes shape.
        $cashAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Cash);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);
        $this->repositoryId = $repository->id;
    }

    // =================================================================
    // Plan §1652 test-locks
    // =================================================================

    public function test_bridge_creates_treasury_payment_and_gl_exactly_once(): void
    {
        $event = $this->projectedSaleReceiptFiscalEvent();
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        $bridge->apply($event);

        $this->assertSame(1, DB::table('payments')->count());
        $payment = DB::table('payments')->first();
        $this->assertNotNull($payment);
        $this->assertSame(PaymentOrigin::Pos->value, $payment->origin);
        $this->assertSame($event->id, $payment->fiscal_event_id);
        $this->assertGreaterThan(0, DB::table('journal_entries')->count());
        // The Payment row carries the back-link to its GL entry.
        $this->assertNotNull($payment->journal_entry_id);

        // Idempotent: re-apply does NOT create duplicates.
        $bridge->apply($event);
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_requires_module_is_canonical_treasury_token(): void
    {
        // The PascalCase token is load-bearing — `CompanyConfig::hasModule()`
        // strict-compares against the `Vertical::defaultModules()` set; a
        // lowercase `'treasury'` would silently always-deactivate (the
        // FiscalEventProjectionRegistryTest pins this behaviour).
        $this->assertSame(
            'Treasury',
            $this->app->make(TreasuryReceiptBridge::class)->requiresModule(),
        );
    }

    public function test_projector_publishes_canonical_metadata(): void
    {
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        $this->assertSame('treasury_receipt_bridge', $bridge->name());
        $this->assertTrue($bridge->handlesEventType(FiscalEventType::SALE_RECEIPT));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::CHAIN_BREAK_DETECTED));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::REFUND_RECEIPT));
    }

    public function test_bridge_is_registered_as_a_fiscal_event_projector_tag(): void
    {
        // Provider wiring (plan §1689): `TreasuryServiceProvider::register()`
        // tags the bridge so Task 18's registry picks it up via
        // `app->tagged(FiscalEventProjector::class)`. A missing tag would
        // leave a `SALE_RECEIPT` ingest with zero active Treasury bridge —
        // silent regression that would defeat the §13 fiscal-event linkage
        // on every projected payment row.
        /** @var list<FiscalEventProjector> $tagged */
        $tagged = iterator_to_array(
            $this->app->tagged(FiscalEventProjector::class),
            false,
        );
        $names = array_map(
            static fn (FiscalEventProjector $p): string => $p->name(),
            $tagged,
        );
        $this->assertContains('treasury_receipt_bridge', $names);
        // Task 21's POS-core projector remains tagged (additive, not
        // replacement).
        $this->assertContains('pos_core_receipt', $names);
    }

    public function test_bridge_declares_priority_after_pos_core(): void
    {
        // Task 22 round-2 (Codex T22-B1 / Opus F3 — convergent BLOCKER):
        // dispatch order is sorted by priority() at registry construction.
        // The bridge depends on PosCoreReceiptProjection having written
        // the `pos_receipts` row, so it MUST declare a higher priority
        // (== runs later). A regression that flipped these values would
        // silently re-introduce the production silent-applied bug.
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $posCore = $this->app->make(PosCoreReceiptProjection::class);

        $this->assertGreaterThan(
            $posCore->priority(),
            $bridge->priority(),
            'TreasuryReceiptBridge must declare a higher priority than '.
            'PosCoreReceiptProjection so the registry dispatches POS-core first. '.
            'See FiscalEventProjector::priority() docblock and Task 22 round-2 review.',
        );
    }

    public function test_registry_sorts_pos_core_before_treasury_bridge(): void
    {
        // Task 22 round-2 (Codex T22-B1 / Opus F3 — convergent BLOCKER):
        // pin the actual dispatch-order contract end-to-end in the
        // production container. Pre-round-2 the registry preserved
        // provider-registration order, which placed Treasury BEFORE
        // POS-core (TreasuryServiceProvider at bootstrap/providers.php:65,
        // POSServiceProvider at :76). After round-2 the registry sorts by
        // (priority ASC, name ASC) — so POS-core (50) lands before the
        // bridge (150) regardless of provider order.
        $registry = $this->app->make(
            FiscalEventProjectionRegistry::class,
        );

        // Build an event with the test fixture's tenant/company so the
        // production ModuleActivationResolver reports Treasury active
        // (assuming the test tenant has the Treasury module — the
        // resolver gate is exercised in FiscalEventProjectionRegistryTest;
        // here we only need the active list to contain both names in
        // priority order to prove the sort works in container resolution).
        $event = $this->storeSaleReceiptFiscalEvent();
        $active = $registry->activeProjectorsFor($event);

        $names = array_map(
            static fn (FiscalEventProjector $p): string => $p->name(),
            $active,
        );

        // POS-core is always-active; the Treasury bridge gates on the
        // resolver verdict. Either way, if both appear, POS-core comes first.
        $posCoreIndex = array_search('pos_core_receipt', $names, true);
        $bridgeIndex = array_search('treasury_receipt_bridge', $names, true);

        $this->assertNotFalse($posCoreIndex, 'pos_core_receipt must be active');
        if ($bridgeIndex !== false) {
            $this->assertLessThan(
                $bridgeIndex,
                $posCoreIndex,
                'POS-core projector must be dispatched before Treasury bridge — '.
                'see Task 22 round-2 BLOCKER fix.',
            );
        }
    }

    public function test_production_sequence_pos_core_then_bridge_writes_payment(): void
    {
        // Task 22 round-2 — simulate the production sequence: POS-core
        // projector applies first (writes pos_receipts), then the bridge
        // applies (writes Treasury Payment + GL). Re-applying the bridge
        // must be idempotent.
        $event = $this->projectedSaleReceiptFiscalEvent(); // POS-core has projected
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        // First apply — writes one Payment + GL entry.
        $bridge->apply($event);
        $this->assertSame(1, DB::table('payments')->count());
        $glAfterFirst = DB::table('journal_entries')->count();
        $this->assertGreaterThan(0, $glAfterFirst);

        // Re-apply — outer probe short-circuits, no duplicate writes.
        $bridge->apply($event);
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame($glAfterFirst, DB::table('journal_entries')->count());
    }

    // =================================================================
    // Discriminated-union variants
    // =================================================================

    public function test_split_payment_lines_each_produce_distinct_treasury_payment_rows(): void
    {
        $event = $this->projectedSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->paymentMethodId,
                    'amount' => '7.00',
                    'method_code' => 'CASH',
                    'repository_id' => $this->repositoryId,
                ],
                [
                    'payment_method_id' => $this->paymentMethodId,
                    'amount' => '3.00',
                    'method_code' => 'CASH',
                    'repository_id' => $this->repositoryId,
                ],
            ],
        );

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        // Two Treasury Payment rows + two GL posts.
        $payments = DB::table('payments')->orderBy('amount', 'desc')->get();
        $this->assertCount(2, $payments);
        $this->assertSame(2, DB::table('journal_entries')->count());

        // Both rows stamped with origin=pos + fiscal_event_id.
        foreach ($payments as $row) {
            $this->assertSame(PaymentOrigin::Pos->value, $row->origin);
            $this->assertSame($event->id, $row->fiscal_event_id);
        }

        // Sum equals the receipt total (10.00).
        $sum = '0';
        foreach ($payments as $row) {
            /** @var numeric-string $amount */
            $amount = (string) $row->amount;
            $sum = bcadd($sum, $amount, 3);
        }
        $this->assertSame('10.000', $sum);
    }

    public function test_bridge_is_idempotent_via_the_origin_pos_fiscal_event_id_probe(): void
    {
        $event = $this->projectedSaleReceiptFiscalEvent();
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        $bridge->apply($event);
        $paymentRowsAfterFirst = DB::table('payments')->count();
        $glEntriesAfterFirst = DB::table('journal_entries')->count();

        // Re-apply: outer probe short-circuits, no duplicate writes.
        $bridge->apply($event);

        $this->assertSame($paymentRowsAfterFirst, DB::table('payments')->count());
        $this->assertSame($glEntriesAfterFirst, DB::table('journal_entries')->count());
    }

    public function test_cross_tenant_payment_method_id_is_rejected_fail_closed(): void
    {
        // Task 22 round-2 (Opus F1 BLOCKER) — symmetric defense to the
        // repository_id gate. `payments.payment_method_id` is a FK to
        // `payment_methods.id` that only enforces PK existence; without
        // the application-side tenant-scoped lookup a foreign tenant's
        // payment_method_id smuggled into the payload would bind onto
        // our tenant's Payment row. The bridge throws RuntimeException
        // inside the wrapping `DB::transaction` — atomic rollback.
        //
        // PosCoreReceiptProjection also fails-closed on cross-tenant
        // payment_method_id (Task 21 round-2 Opus F3) — so the foreign
        // payload would crash POS-core's projection before the bridge
        // gets a chance to defend. To exercise the bridge's own gate in
        // isolation (the point of THIS test), bypass POS-core by:
        //   (1) building the fiscal_events row directly
        //       (`storeSaleReceiptFiscalEvent` — no projection),
        //   (2) seeding a stand-in pos_receipts row via the bridge-only
        //       `seedPosReceiptRowFor` helper.
        // Same harness as `test_malformed_payload_payment_method_id_rolls_bridge_back`.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignMethod = PaymentMethod::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'code' => 'CARD',
            'name' => 'Card',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    // Foreign tenant's payment_method_id; repository is valid.
                    'payment_method_id' => $foreignMethod->id,
                    'amount' => '10.00',
                    'method_code' => 'CARD',
                    'repository_id' => $this->repositoryId,
                ],
            ],
        );
        $this->seedPosReceiptRowFor($event);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected RuntimeException for cross-tenant payment_method_id');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment_method_id', $e->getMessage());
            $this->assertStringContainsString('not visible to tenant', $e->getMessage());
            // Sanity — the bridge threw, not PosCoreReceiptProjection.
            $this->assertStringContainsString('TreasuryReceiptBridge', $e->getMessage());
        }

        // Atomic rollback — no Payment row, no GL entry.
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_cross_tenant_repository_id_is_rejected_fail_closed(): void
    {
        // Task 21 round-2 Opus F3 standing pattern — application-side
        // tenant scoping on the inbound mirrored reference data lookup.
        // The `payments.repository_id` FK does NOT enforce tenant scope
        // on its own (it only requires the PK to exist), so without this
        // guard a foreign tenant's repository_id smuggled into the payload
        // would bind onto our tenant's Payment row. The bridge throws
        // RuntimeException inside the wrapping `DB::transaction`, rolling
        // back any prior writes atomically.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignRepo = PaymentRepository::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'type' => RepositoryType::CashRegister,
        ]);

        $event = $this->projectedSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->paymentMethodId,
                    'amount' => '10.00',
                    'method_code' => 'CASH',
                    'repository_id' => $foreignRepo->id,
                ],
            ],
        );

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected RuntimeException for cross-tenant repository_id');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not visible to tenant', $e->getMessage());
        }

        // Atomic rollback — no Payment row, no GL entry.
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_missing_repository_id_is_rejected_fail_loud(): void
    {
        // The bridge writes a POS-payment GL entry which needs a source
        // repository — a payment_line without `repository_id` cannot be
        // bridged. Mirror the legacy ReceiptPaymentService contract:
        // throw inside the transaction → atomic rollback.
        $event = $this->projectedSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                [
                    'payment_method_id' => $this->paymentMethodId,
                    'amount' => '10.00',
                    'method_code' => 'CASH',
                    // repository_id missing
                ],
            ],
        );

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected RuntimeException for missing repository_id');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('missing repository_id', $e->getMessage());
        }

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_malformed_payload_payment_method_id_rolls_bridge_back(): void
    {
        // Standing pattern: FiscalPayloadArrayGuards must throw on
        // missing required fields. No silent coercion.
        //
        // Bypass PosCoreReceiptProjection (which would reject the same
        // shape first via its own writePayments guard) by building the
        // fiscal_events row + a stand-in pos_receipts row manually. The
        // bridge depends on the receipt-row existence; the malformed-
        // payload guard inside the bridge is what we want to prove.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                // payment_method_id missing → requireString throws
                ['amount' => '10.00', 'method_code' => 'CASH', 'repository_id' => $this->repositoryId],
            ],
        );
        $this->seedPosReceiptRowFor($event);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected InvalidArgumentException from missing payment_method_id');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    /**
     * Build a minimal `pos_receipts` row pointing at the given fiscal event,
     * bypassing PosCoreReceiptProjection. Used when the test wants to drive
     * a payload shape that the POS-core projector itself would reject before
     * the bridge even runs.
     */
    private function seedPosReceiptRowFor(FiscalEvent $event): Receipt
    {
        $terminal = Terminal::query()->findOrFail($this->terminalId);

        return Receipt::factory()
            ->withTotal('10.000', '0.000')
            ->create([
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'location_id' => $terminal->location_id,
                'terminal_id' => $event->terminal_id,
                'cashier_id' => $event->operator_id,
                'currency' => 'EUR',
                'fiscal_event_id' => $event->id,
                'fiscal_hash' => $event->current_hash,
                'previous_hash' => $event->previous_hash,
                'chain_sequence' => $event->sequence_number,
            ]);
    }

    public function test_no_pos_receipt_for_event_is_a_deferred_bail_out_not_a_crash(): void
    {
        // Boundary discipline — if PosCoreReceiptProjection hasn't yet
        // written the pos_receipts row for the event, the bridge logs +
        // skips and returns cleanly. Task 23's job will retry per its
        // lifecycle. We test this by creating a fiscal event WITHOUT
        // first running the POS-core projector (the helper does this
        // when `project=false`).
        $event = $this->projectedSaleReceiptFiscalEvent(project: false);

        // Should NOT throw.
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        // No Payment rows written.
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_zero_payment_lines_event_writes_no_payments_no_gl(): void
    {
        // Open-tab / fully-discounted receipts have empty `payment_lines[]`.
        // The bridge should be a clean no-op for those — no Payment row,
        // no GL entry — without crashing.
        $event = $this->projectedSaleReceiptFiscalEvent(
            paymentLinesOverride: [],
            total: '0.00',
            subtotal: '10.00',
            discountTotal: '10.00',
            vatBreakdown: [['rate' => '0', 'base' => '10.00', 'amount' => '0.00']],
        );

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_payment_status_and_type_match_legacy_receipt_payment_service(): void
    {
        // The bridge replaces the legacy `ReceiptPaymentService` Payment
        // create — must match its status + type stamping so downstream
        // reports (Treasury dashboards, reconciliation) don't drift.
        $event = $this->projectedSaleReceiptFiscalEvent();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertSame(PaymentType::POS, $payment->payment_type);
        $this->assertSame(PaymentOrigin::Pos, $payment->origin);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Persist a verified SALE_RECEIPT fiscal_events row and, by default,
     * run PosCoreReceiptProjection so a pos_receipts row exists for the
     * bridge's downstream-receipt lookup (mirrors the production
     * dispatch order — POS-core's projection job is enqueued first).
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride
     * @param  list<array<string, mixed>>|null  $vatBreakdown
     */
    private function projectedSaleReceiptFiscalEvent(
        ?array $paymentLinesOverride = null,
        ?array $vatBreakdown = null,
        string $total = '10.00',
        string $subtotal = '10.00',
        string $discountTotal = '0.00',
        string $taxTotal = '0.00',
        int $sequenceNumber = 1,
        bool $project = true,
    ): FiscalEvent {
        $event = $this->storeSaleReceiptFiscalEvent(
            $paymentLinesOverride,
            $vatBreakdown,
            $total,
            $subtotal,
            $discountTotal,
            $taxTotal,
            $sequenceNumber,
        );

        if ($project) {
            $this->app->make(PosCoreReceiptProjection::class)->apply($event);
        }

        return $event;
    }

    /**
     * Build + persist a SALE_RECEIPT fiscal_events row. Adapted from
     * `PosCoreReceiptProjectionTest::storeSaleReceiptFiscalEvent` —
     * same shape so the POS-core projector accepts the payload cleanly.
     *
     * @param  list<array<string, mixed>>|null  $paymentLinesOverride
     * @param  list<array<string, mixed>>|null  $vatBreakdown
     */
    private function storeSaleReceiptFiscalEvent(
        ?array $paymentLinesOverride = null,
        ?array $vatBreakdown = null,
        string $total = '10.00',
        string $subtotal = '10.00',
        string $discountTotal = '0.00',
        string $taxTotal = '0.00',
        int $sequenceNumber = 1,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $paymentLines = $paymentLinesOverride ?? [
            [
                'payment_method_id' => $this->paymentMethodId,
                'amount' => '10.00',
                'method_code' => 'CASH',
                'repository_id' => $this->repositoryId,
            ],
        ];

        $linesPayload = [
            [
                'sku' => 'X',
                'unit_price' => '10.00',
                'line_total' => '10.00',
                'quantity' => '1',
                'tax_rate' => '0',
                'tax_amount' => '0.00',
            ],
        ];

        $vatPayload = $vatBreakdown ?? [
            ['rate' => '0', 'base' => '10.00', 'amount' => '0.00'],
        ];

        $payload = [
            'currency' => 'EUR',
            'currency_scale' => 2,
            'discount_total' => $discountTotal,
            'lines' => $linesPayload,
            'payment_lines' => $paymentLines,
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'vat_breakdown' => $vatPayload,
            'voucher_redemptions' => [],
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
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $currentHash = hash('sha256', $canonicalBytes);
        $eventId = Str::uuid()->toString();

        $event = FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
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

    /**
     * Spec §4 JCS canonical encoding (test-local).
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
