<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
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
 * Boundary discipline: if `PosCoreReceiptProjection` hasn't yet committed
 * the `pos_receipts` row for the event, the bridge throws
 * `ProjectionDependencyMissingException` — `ApplyFiscalEventProjectionJob`'s
 * fail-closed catch advances attempt accounting + re-throws → Horizon
 * retries with backoff → POS-core's sibling job lands first → the bridge's
 * next attempt sees the receipt → succeeds. Codex T23-B2 round-2 closure;
 * round-1 returned cleanly which let the job mark `applied` with zero
 * Treasury effects under the multi-worker dispatch-order race.
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

    public function test_bridge_applies_without_a_bound_company_context_like_a_queue_worker(): void
    {
        // 2026-06-12 reports audit: ApplyFiscalEventProjectionJob runs on a
        // Horizon worker with NO CompanyContext bound (no middleware). The
        // bridge's GL post path must therefore never consult the no-arg
        // CurrencyScaleResolver::getScale() — it threw
        // UnboundCompanyContextException in production the first time the
        // fiscal-projections queue was actually consumed. This test mirrors
        // the worker reality by clearing the context the setUp() bound.
        $event = $this->projectedSaleReceiptFiscalEvent();
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        app(CompanyContext::class)->clear();

        $bridge->apply($event);

        $this->assertSame(1, DB::table('payments')->count());
        $payment = DB::table('payments')->first();
        $this->assertNotNull($payment);
        $this->assertNotNull($payment->journal_entry_id);
    }

    public function test_requires_module_is_canonical_treasury_token(): void
    {
        // The PascalCase token is load-bearing — `CompanyConfig::hasModule()`
        // strict-compares against the `config/verticals.php` `default_modules`
        // set (read via `VerticalConfigService`); a
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
        // Pass 2A.PHP.2 — `payment_method_id` no longer on canonical
        // payload. The bridge resolves via PaymentMethodResolver
        // (tenant-scoped). A method_code that exists ONLY in a foreign
        // tenant resolves to null in this tenant → fail-closed
        // RuntimeException → atomic rollback.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        PaymentMethod::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'code' => 'CARD',
            'name' => 'Card',
        ]);

        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                // 'CARD' exists ONLY in $otherTenant, not in $this->tenantId.
                ['amount' => '10.00', 'method_code' => 'CARD'],
            ],
        );
        $this->seedPosReceiptRowFor($event);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected RuntimeException for cross-tenant method_code');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment_method_not_found', $e->getMessage());
            $this->assertStringContainsString('CARD', $e->getMessage());
            // Sanity — the bridge threw, not PosCoreReceiptProjection.
            $this->assertStringContainsString('TreasuryReceiptBridge', $e->getMessage());
        }

        // Atomic rollback — no Payment row, no GL entry.
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_no_repository_for_tenant_rejects_fail_loud(): void
    {
        // Pass 2A.PHP.2 — `repository_id` no longer carried on the
        // canonical payload (synthesis v5 §3 omits per-payment repository
        // routing). The bridge resolves the default repository via a
        // tenant+company-scoped first-row lookup. When NO repository
        // exists with a GL-linked account in the event's tenant scope,
        // the bridge throws RuntimeException → atomic rollback.
        //
        // We exercise the failure mode by setting up the event under a
        // foreign tenant where no repository exists. The cross-tenant
        // security stance is preserved: a foreign repository CANNOT be
        // selected because the lookup is tenant-scoped.
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignTerminal = Terminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => Location::factory()->create(['company_id' => $otherCompany->id])->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        // Foreign tenant has a repository — but it's NOT in this event's
        // tenant scope. The resolver MUST not cross tenants.
        PaymentRepository::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'type' => RepositoryType::CashRegister,
        ]);

        // Create an event scoped to a THIRD tenant that has no repository.
        $thirdTenant = Tenant::factory()->create();
        $thirdCompany = Company::factory()->create(['tenant_id' => $thirdTenant->id]);
        PaymentMethod::factory()->create([
            'tenant_id' => $thirdTenant->id,
            'company_id' => $thirdCompany->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        // Switch fixture to the third tenant scope, then stage the event.
        $origTenant = $this->tenantId;
        $origCompany = $this->companyId;
        $this->tenantId = $thirdTenant->id;
        $this->companyId = $thirdCompany->id;
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [['amount' => '10.00', 'method_code' => 'CASH']],
        );
        $this->seedPosReceiptRowFor($event);
        $this->tenantId = $origTenant;
        $this->companyId = $origCompany;

        unset($foreignTerminal);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected RuntimeException for missing repository in tenant scope');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no GL-linked payment_repository found', $e->getMessage());
        }

        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_unresolved_method_code_rolls_bridge_back(): void
    {
        // Pass 2A.PHP.2 — `payment_method_id` no longer carried on the
        // canonical payload. The bridge resolves the FK via the
        // PaymentMethodResolver (Shared/Contracts) seam, same surface
        // PosCoreReceiptProjection uses. An unknown method_code returns
        // null → fail-closed RuntimeException → atomic rollback.
        $event = $this->storeSaleReceiptFiscalEvent(
            paymentLinesOverride: [
                ['amount' => '10.00', 'method_code' => 'XENO_CODE_NEVER_SEEDED'],
            ],
        );
        $this->seedPosReceiptRowFor($event);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected RuntimeException for unresolved method_code');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payment_method_not_found', $e->getMessage());
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

    public function test_no_pos_receipt_for_event_throws_projection_dependency_missing(): void
    {
        // Boundary discipline — if PosCoreReceiptProjection hasn't yet
        // written the pos_receipts row for the event, the bridge MUST
        // throw `ProjectionDependencyMissingException` so the wrapping
        // `ApplyFiscalEventProjectionJob` advances attempt accounting +
        // re-throws → Horizon retries → the sibling POS-core job commits
        // → the bridge's next attempt succeeds.
        //
        // Task 23 round-2 (Codex T23-B2 BLOCKER) closure — round-1 returned
        // cleanly (`Log::warning + return`) and the job marked the row
        // `applied` with zero Treasury effects. The renamed test pins the
        // retry contract: this is the "deferred-bail-out test smell"
        // lesson from Task 22 inverted — a test that asserts "no crash"
        // can mask a silent-applied bug. The contract is "throws the
        // retryable exception", not "returns silently".
        $event = $this->projectedSaleReceiptFiscalEvent(project: false);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected ProjectionDependencyMissingException when pos_receipts row is missing.');
        } catch (ProjectionDependencyMissingException $e) {
            $this->assertSame('treasury_receipt_bridge', $e->projectorName);
            $this->assertSame($event->id, $e->fiscalEventId);
            $this->assertStringContainsString('pos_receipts', $e->missingDependency);
            $this->assertStringContainsString('RETRYABLE', $e->getMessage());
        }

        // No Payment rows written — the throw happened before the
        // wrapping DB::transaction was opened, but assert the invariant
        // anyway so a regression that moves the lookup INSIDE the
        // transaction without re-checking idempotency still fails loud.
        $this->assertSame(0, DB::table('payments')->count());
        // No GL entries written either — Treasury writes both atomically.
        $this->assertSame(0, DB::table('journal_entries')->count());
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

        // Pass 2A.PHP.2 — emit 28-key Candidate C-v3 SALE_RECEIPT payload.
        // Old-shape overrides translated to canonical {method_code, amount,
        // instrument_*} per payment. `payment_method_id` + `repository_id`
        // no longer in canonical — resolved by the bridge via the
        // PaymentMethodResolver seam + tenant-scoped default repository.
        $payments = [];
        $payLines = $paymentLinesOverride ?? [
            ['amount' => '10.00', 'method_code' => 'CASH'],
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
            $rateRaw = (string) ($vr['rate'] ?? '0');
            $rate = str_contains($rateRaw, '.') ? $rateRaw : $rateRaw.'.00';
            $vatRows[] = [
                'gross_amount' => $gross,
                'net_amount' => $base,
                'rate' => $rate,
                'tax_category_code' => 'Z',
                'vat_amount' => $amount,
            ];
        }

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => $subtotal,
                'line_vat' => $taxTotal,
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => $subtotal,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => $payments,
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
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
