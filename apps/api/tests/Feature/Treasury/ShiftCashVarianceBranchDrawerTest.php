<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Listeners\PostShiftCashVarianceAdjustment;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Campaign lane N-12, brief deliverable 2 — a branch shift's variance belongs
 * to the BRANCH's drawer.
 *
 * The listener change shipped unpinned: gate r1 (both lenses) found that no
 * test anywhere asserted where a variance lands, and the existing variance
 * suite passes only because its fixtures leave the till unattributed (tier 2).
 * A shift-close adjustment booked against Main's drawer while that shift's own
 * sales sit in the branch's is precisely the commingling this lane removes —
 * and it would be invisible until a per-branch cash count failed.
 *
 * Also pins the two degradation paths the gate ruled on:
 *   - finding B: a REPLAY reuses the repository the adjustment was first posted
 *     against, so a redelivery after the drawer moved is a clean replay and not
 *     a permanent `IdempotencyConflictException` dead-letter;
 *   - finding D: a terminal whose row cannot be read resolves against the
 *     company's DEFAULT location, never the company-wide set (which, after the
 *     N-12 backfill, is Main's till).
 */
final class ShiftCashVarianceBranchDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Account $cashAccount;

    private User $cashier;

    private Location $mainLocation;

    private Location $branchLocation;

    private Terminal $branchTerminal;

    private PaymentRepository $mainTill;

    private PaymentMethod $cashMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashAccount = Account::query()
            ->where('company_id', $this->company->id)
            ->where('system_purpose', SystemAccountPurpose::Cash->value)
            ->firstOrFail();

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // Main is created first so that, under time-ordered uuid7, its till also
        // sorts first by id — i.e. the pre-N-12 company-wide fallback would pick
        // it. Without that ordering these tests could pass by luck.
        $this->mainLocation = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Main Location',
            'is_default' => true,
            'pos_enabled' => true,
        ]);
        $this->branchLocation = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Boutique Ariana',
            'pos_enabled' => true,
        ]);

        $this->branchTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->branchLocation->id,
        ]);

        $this->mainTill = $this->drawer('CASH-01', $this->mainLocation->id, '400.000');

        // Deliberately UNMAPPED: a `default_repository_id` would decide the
        // answer before the location tier ever ran, which is not what these
        // cases are about.
        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Espèces',
            'is_physical' => true,
            'is_cash_tender' => true,
            'is_active' => true,
            'default_repository_id' => null,
        ]);

        config()->set('treasury.shift_variance_gl_enabled', true);

        app(CompanyContext::class)->clear();
    }

    public function test_a_branch_shifts_variance_books_to_the_branch_drawer(): void
    {
        $branchTill = $this->drawer('CASH-02', $this->branchLocation->id, '200.000');

        $shiftId = (string) Str::uuid();
        $this->runOnWorker($this->cashCountEvent($shiftId, $this->branchTerminal->id));

        $adjustment = RepositoryAdjustment::query()->sole();
        $this->assertSame($branchTill->id, $adjustment->payment_repository_id);

        // A 5.000 shortfall out of the branch till, and Main untouched.
        $this->assertSame(0, bccomp((string) $branchTill->fresh()?->balance, '195.000', 3));
        $this->assertSame(0, bccomp((string) $this->mainTill->fresh()?->balance, '400.000', 3));
    }

    public function test_a_branch_with_no_drawer_refuses_and_names_the_location(): void
    {
        $shiftId = (string) Str::uuid();
        $this->runOnWorker($this->cashCountEvent($shiftId, $this->branchTerminal->id));

        $refusal = DB::table('audit_events')
            ->where('aggregate_id', $shiftId)
            ->where('event_type', 'treasury.shift_variance_gl_skipped')
            ->firstOrFail();

        $payload = (string) $refusal->payload;
        $this->assertStringContainsString('no_repository_resolved', $payload);
        $this->assertStringContainsString($this->branchLocation->id, $payload);

        // A refusal is a record, not a fallback: Main's drawer is untouched.
        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, bccomp((string) $this->mainTill->fresh()?->balance, '400.000', 3));
    }

    /**
     * Gate r1 finding B — existing history wins over mutable routing.
     *
     * The first attempt books against the branch till. The drawer is then
     * re-attributed (an operator fixing an attribution, or the N-12 backfill
     * itself), and the event is redelivered. Re-resolving would pick a different
     * repository than the document already carries, and the movement port would
     * throw `IdempotencyConflictException` — a clean replay turned into a
     * permanent dead letter.
     */
    public function test_a_replay_after_the_drawer_moved_reuses_the_original_repository(): void
    {
        $branchTill = $this->drawer('CASH-02', $this->branchLocation->id, '200.000');

        $shiftId = (string) Str::uuid();
        $event = $this->cashCountEvent($shiftId, $this->branchTerminal->id);
        $this->runOnWorker($event);

        $this->assertSame($branchTill->id, RepositoryAdjustment::query()->sole()->payment_repository_id);

        // The branch is given a different drawer between the two attempts. The
        // original moves to a third location — not to Main, because the partial
        // unique index this lane adds (one usable drawer per location per type)
        // correctly refuses a second till there.
        $thirdLocation = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Entrepôt',
            'pos_enabled' => false,
        ]);
        $branchTill->forceFill(['location_id' => $thirdLocation->id])->save();
        $replacement = $this->drawer('CASH-03', $this->branchLocation->id, '50.000');

        $this->runOnWorker($event);

        $this->assertSame(1, RepositoryAdjustment::query()->count());
        $this->assertSame($branchTill->id, RepositoryAdjustment::query()->sole()->payment_repository_id);
        $this->assertSame(1, DB::table('repository_movements')->count());
        $this->assertSame(
            0,
            bccomp((string) $replacement->fresh()?->balance, '50.000', 3),
            'The replay must not touch the drawer it would resolve today.',
        );
    }

    /**
     * Gate r1 finding D — an unreadable terminal resolves against the company's
     * DEFAULT location, not the company-wide set.
     *
     * The company-wide set is ordered `cash_register` first, so before this the
     * one degradation path the lane left open produced exactly the outcome the
     * lane exists to prevent. Here the default location (Main) and the branch
     * both own a drawer, and an event whose terminal row does not exist must
     * land in MAIN's — deliberately, because that is the company's default —
     * rather than in whichever drawer happens to sort first.
     */
    public function test_an_unreadable_terminal_falls_back_to_the_default_locations_drawer(): void
    {
        $branchTill = $this->drawer('CASH-02', $this->branchLocation->id, '200.000');

        $shiftId = (string) Str::uuid();
        $this->runOnWorker($this->cashCountEvent($shiftId, (string) Str::uuid()));

        $this->assertSame($this->mainTill->id, RepositoryAdjustment::query()->sole()->payment_repository_id);
        $this->assertSame(0, bccomp((string) $branchTill->fresh()?->balance, '200.000', 3));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function drawer(string $code, ?string $locationId, string $balance): PaymentRepository
    {
        return PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'type' => RepositoryType::CashRegister,
            'location_id' => $locationId,
            'gl_account_id' => $this->cashAccount->id,
            'currency' => 'TND',
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    /**
     * Run the listener the way a worker does — resolved instance, a real job
     * bound to it, and NO CompanyContext (rule 20).
     */
    private function runOnWorker(CashCountRecorded $event): void
    {
        app(CompanyContext::class)->clear();

        /** @var PostShiftCashVarianceAdjustment $listener */
        $listener = app(PostShiftCashVarianceAdjustment::class);
        $listener->setJob(new FakeJob);
        $listener->handle($event);
    }

    private function cashCountEvent(string $shiftId, string $terminalId): CashCountRecorded
    {
        $variance = bcsub('115.0000', '120.0000', 4);

        return new CashCountRecorded(
            zReportId: (string) Str::uuid(),
            shiftId: $shiftId,
            terminalId: $terminalId,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            cashierId: $this->cashier->id,
            managerOverrideBy: null,
            blindCountUsed: false,
            currencyCode: 'TND',
            aggregateVariance: new VarianceAmount(amount: $variance, currencyCode: 'TND'),
            varianceDirection: VarianceDirection::fromSignedAmount($variance),
            severity: VarianceSeverity::Warning,
            tenderBreakdown: [new CashCountBreakdownDTO(
                paymentMethodId: $this->cashMethod->id,
                currencyCode: 'TND',
                expectedAmount: '120.0000',
                actualAmount: '115.0000',
                varianceAmount: $variance,
                varianceDirection: VarianceDirection::fromSignedAmount($variance),
                transactionCount: 1,
            )],
            descriptionCode: 'pos.cash_count.warning',
            descriptionParams: [],
            recordedAt: now()->toIso8601String(),
        );
    }
}
