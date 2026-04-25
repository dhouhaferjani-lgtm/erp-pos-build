<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Exceptions\CashCountValidationException;
use App\Modules\POS\Application\Exceptions\UnauthorizedManagerException;
use App\Modules\POS\Application\Services\CashCountValidationService;
use App\Modules\POS\Application\Services\FraudSettingsResolver;
use App\Modules\POS\Application\Services\ReportGenerationService;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Services\GrandtotalService;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Services\ZReportHashService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Domain\ZReportCount;
use App\Modules\POS\Infrastructure\Repositories\ZReportCountRepository;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

/**
 * End-to-end tests for ReportGenerationService::generateZReport with cash-count inputs (Task 18).
 *
 * Covers:
 * - Backwards-compatible legacy path (no new params)
 * - Happy path: balanced count → severity Info
 * - Warning tier with reason
 * - Critical tier with manager override
 * - Critical tier without manager → throws manager_pin_required
 * - Warning tier without reason → throws variance_reason_required
 * - Idempotent re-call: second call returns existing Z, no duplicate counts, no event
 * - Regression: closeShift must not overwrite cash-count-set actual_cash + variance
 * - CashCountRecorded.descriptionParams carries cashier_name, manager_name, terminal_code, z_number
 * - Cross-tenant manager override → throws UnauthorizedManagerException
 */
final class GenerateZReportWithCountsTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private ReportGenerationService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private User $manager;

    private Terminal $terminal;

    private Shift $shift;

    private PaymentMethod $cashMethod;

    private const VARIANCE_PERMISSION = 'pos.close_shift_with_variance';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReportGenerationService(
            $this->app->make(ShiftManagementService::class),
            $this->app->make(CashDrawerService::class),
            $this->app->make(ZReportHashService::class),
            $this->app->make(GrandtotalService::class),
            $this->mockCurrencyScale(4),
            $this->app->make(CashCountValidationService::class),
            $this->app->make(FraudSettingsResolver::class),
            $this->app->make(ZReportCountRepository::class),
        );

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // Wire up tenant scope and the close-shift-with-variance permission for the manager.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate(self::VARIANCE_PERMISSION, 'sanctum');
        $this->manager->givePermissionTo(self::VARIANCE_PERMISSION);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '50.0000',
        ]);
    }

    public function test_happy_path_balanced_count_persists_and_fires_event(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000');
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        Event::fake();

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '100.0000',
        )];

        $z = $this->service->generateZReport(
            $this->terminal,
            $this->cashier,
            $inputs,
            null,
            null,
            false,
        );

        $this->assertSame(1, ZReportCount::where('z_report_id', $z->id)->count());
        $count = ZReportCount::where('z_report_id', $z->id)->first();
        $this->assertNotNull($count);
        $this->assertSame('100.0000', $count->expected_amount);
        $this->assertSame('100.0000', $count->actual_amount);
        $this->assertSame('0.0000', $count->variance_amount);
        $this->assertSame('balanced', $count->variance_direction);
        $this->assertSame(1, $count->transaction_count);

        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);
        $this->assertSame('100.0000', (string) $shift->actual_cash);
        $this->assertSame('0.0000', (string) $shift->variance);
        $this->assertSame('info', $shift->variance_severity);
        $this->assertFalse($shift->blind_count_used);
        $this->assertNull($shift->manager_override_by);

        $reportData = $z->report_data;
        $this->assertSame(2, $reportData['schema_version']);
        $this->assertIsArray($reportData['cash_counts']);
        $this->assertCount(1, $reportData['cash_counts']);
        $this->assertSame('balanced', $reportData['variance_summary']['aggregate_direction']);
        $this->assertSame('info', $reportData['variance_summary']['severity']);
        $this->assertSame([
            'totalAmount' => '0.000',
            'currencyCode' => 'EUR',
            'writeoffCount' => 0,
        ], $reportData['tolerance_summary']);

        Event::assertDispatched(CashCountRecorded::class, function (CashCountRecorded $e) use ($z): bool {
            return $e->zReportId === $z->id
                && $e->shiftId === $this->shift->id
                && $e->terminalId === $this->terminal->id
                && $e->tenantId === $this->tenant->id
                && $e->companyId === $this->company->id
                && $e->cashierId === $this->cashier->id
                && $e->managerOverrideBy === null
                && $e->blindCountUsed === false
                && $e->currencyCode === 'EUR'
                && $e->severity->value === 'info'
                && $e->varianceDirection->value === 'balanced'
                && count($e->tenderBreakdown) === 1
                && $e->descriptionCode === 'pos.cash_count.info'
                && $e->recordedAt !== '';
        });
    }

    public function test_warning_tier_with_reason_persists_severity_and_notes(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000');
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        Event::fake();

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '101.5000', // +1.5 → over soft (1.0) but under hard (20.0) → warning
        )];

        $z = $this->service->generateZReport(
            $this->terminal,
            $this->cashier,
            $inputs,
            'Cashier overage from change drawer reset',
            null,
            false,
        );

        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);
        $this->assertSame('warning', $shift->variance_severity);
        $this->assertSame('1.5000', (string) $shift->variance);
        $this->assertNotNull($shift->notes);
        $this->assertStringContainsString('Variance reason:', (string) $shift->notes);
        $this->assertStringContainsString('Cashier overage from change drawer reset', (string) $shift->notes);

        $this->assertSame('warning', $z->report_data['variance_summary']['severity']);

        Event::assertDispatched(CashCountRecorded::class, fn (CashCountRecorded $e): bool => $e->severity->value === 'warning'
        );
    }

    public function test_critical_tier_with_manager_override_succeeds(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000');
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        Event::fake();

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '150.0000', // +50 → critical
        )];

        $z = $this->service->generateZReport(
            $this->terminal,
            $this->cashier,
            $inputs,
            'Massive overage — investigating',
            $this->manager->id,
            true,
        );

        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);
        $this->assertSame('critical', $shift->variance_severity);
        $this->assertSame($this->manager->id, $shift->manager_override_by);
        $this->assertTrue($shift->blind_count_used);

        Event::assertDispatched(CashCountRecorded::class, function (CashCountRecorded $e): bool {
            return $e->managerOverrideBy === $this->manager->id
                && $e->blindCountUsed === true
                && $e->severity->value === 'critical';
        });

        $this->assertSame('critical', $z->report_data['variance_summary']['severity']);
    }

    public function test_critical_without_manager_throws_manager_pin_required(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000', requirePin: true);
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '150.0000', // +50 → critical
        )];

        $this->expectException(CashCountValidationException::class);

        $this->service->generateZReport(
            $this->terminal,
            $this->cashier,
            $inputs,
            'Massive overage',
            null, // no manager
            false,
        );
    }

    public function test_warning_without_reason_throws_variance_reason_required(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000');
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '105.0000', // +5 → warning
        )];

        try {
            $this->service->generateZReport(
                $this->terminal,
                $this->cashier,
                $inputs,
                null, // missing reason
                null,
                false,
            );
            $this->fail('Expected CashCountValidationException to be thrown.');
        } catch (CashCountValidationException $e) {
            $this->assertSame('variance_reason_required', $e->firstCode());
        }

        $this->assertSame(0, ZReport::where('shift_id', $this->shift->id)->count());
    }

    public function test_legacy_path_without_cash_counts_remains_backwards_compatible(): void
    {
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        Event::fake();

        $z = $this->service->generateZReport($this->terminal, $this->cashier);

        // No counts persisted, no schema_version stamp, no event fired.
        $this->assertSame(0, ZReportCount::where('z_report_id', $z->id)->count());
        $this->assertArrayNotHasKey('schema_version', $z->report_data);
        $this->assertArrayNotHasKey('cash_counts', $z->report_data);
        $this->assertArrayNotHasKey('variance_summary', $z->report_data);

        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);
        $this->assertNull($shift->variance_severity);
        $this->assertNull($shift->actual_cash);
        $this->assertNull($shift->variance);
        $this->assertNull($shift->manager_override_by);

        Event::assertNotDispatched(CashCountRecorded::class);
    }

    public function test_idempotent_recall_returns_existing_z_without_duplicating_counts(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000');
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '100.0000',
        )];

        Event::fake();

        $first = $this->service->generateZReport($this->terminal, $this->cashier, $inputs);
        $second = $this->service->generateZReport($this->terminal, $this->cashier, $inputs);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->was_reused ?? false);
        $this->assertFalse($first->was_reused ?? true); // was_reused = false on the first call
        $this->assertSame(1, ZReport::where('shift_id', $this->shift->id)->count());
        $this->assertSame(1, ZReportCount::where('z_report_id', $first->id)->count());

        // Event fires once (only on the original generation).
        Event::assertDispatchedTimes(CashCountRecorded::class, 1);
    }

    /**
     * Regression: closeShift must NOT overwrite actual_cash + variance + variance_severity
     * that were already written by generateZReport's cash-count path.
     *
     * Previously, calling closeShift($shift, '999.0000', $cashier) after generateZReport
     * with cash-count inputs would unconditionally recompute and store '999.0000' + a new
     * variance, silently discarding the validated per-tender values from Task 18.
     */
    public function test_close_shift_preserves_cash_count_values_set_by_generate_z_report(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000');
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        // Generate Z with cash count: cashier submitted 95.0000 → variance = -5.0000, warning.
        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '95.0000', // -5 vs expected 100 → warning
        )];

        $this->service->generateZReport(
            $this->terminal,
            $this->cashier,
            $inputs,
            'Counted short; investigating',
            null,
            false,
        );

        // Reload to pick up the cash-count metadata written by generateZReport.
        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);
        $this->assertSame('warning', $shift->variance_severity, 'Pre-condition: variance_severity set by generateZReport');

        // Now simulate the UX flow calling closeShift with a different (wrong) actualCash value.
        /** @var ShiftManagementService $shiftService */
        $shiftService = $this->app->make(ShiftManagementService::class);
        $closedShift = $shiftService->closeShift($shift, '999.0000', $this->cashier);

        // The cash-count values set by generateZReport must be preserved.
        $this->assertSame('95.0000', (string) $closedShift->actual_cash, 'actual_cash must not be overwritten');
        $this->assertSame('-5.0000', (string) $closedShift->variance, 'variance must not be overwritten');
        $this->assertSame('warning', $closedShift->variance_severity, 'variance_severity must not be cleared');

        // Shift must still be fully closed.
        $this->assertSame(ShiftStatus::Closed, $closedShift->status);
        $this->assertNotNull($closedShift->closed_at);
        $this->assertSame($this->cashier->id, $closedShift->closed_by);
    }

    /**
     * CashCountRecorded.descriptionParams must carry cashier_name, manager_name, terminal_code,
     * and z_number so Task-19 fraud-alert listener can render i18n messages without an extra DB
     * round-trip. Rule #8: events are immutable forever, so we lock the payload now.
     */
    public function test_cash_count_recorded_description_params_contain_rich_context(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000');
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        Event::fake();

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '105.0000', // +5 → warning
        )];

        $z = $this->service->generateZReport(
            $this->terminal,
            $this->cashier,
            $inputs,
            'Till reset overage',
            null,
            false,
        );

        Event::assertDispatched(CashCountRecorded::class, function (CashCountRecorded $e) use ($z): bool {
            $p = $e->descriptionParams;

            // manager_name is null when no manager override was used — use array_key_exists
            // rather than isset() to avoid a false negative on the null value.
            return array_key_exists('cashier_name', $p)
                && array_key_exists('manager_name', $p)
                && array_key_exists('terminal_code', $p)
                && array_key_exists('z_number', $p)
                && $p['cashier_name'] === $this->cashier->name
                && $p['manager_name'] === null
                && $p['terminal_code'] === $this->terminal->code
                && $p['z_number'] === $z->z_number
                // Original 3 keys must still be present.
                && isset($p['severity'], $p['aggregate_amount'], $p['currency_code']);
        });
    }

    /**
     * A manager from a different tenant must not be able to authorise a Critical variance
     * on a terminal that belongs to another tenant.
     */
    public function test_cross_tenant_manager_override_throws_unauthorized(): void
    {
        $this->setFraudSettings(softOver: '1.0000', hardOver: '20.0000', softUnder: '1.0000', hardUnder: '20.0000', requirePin: true);
        $this->seedReceiptWithCashPayment(amount: '100.0000');

        // Create a manager in a DIFFERENT tenant with the required permission.
        $otherTenant = Tenant::factory()->create();
        $crossTenantManager = User::factory()->create(['tenant_id' => $otherTenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        Permission::findOrCreate(self::VARIANCE_PERMISSION, 'sanctum');
        $crossTenantManager->givePermissionTo(self::VARIANCE_PERMISSION);

        // Reset team id back to the original tenant so generateZReport runs in the right scope.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $inputs = [new CashCountInputDTO(
            paymentMethodId: $this->cashMethod->id,
            currencyCode: 'EUR',
            actualAmount: '150.0000', // +50 → critical
        )];

        $this->expectException(UnauthorizedManagerException::class);
        $this->expectExceptionMessageMatches('/different tenant/');

        $this->service->generateZReport(
            $this->terminal,
            $this->cashier,
            $inputs,
            'Cross-tenant attempt',
            $crossTenantManager->id,
            false,
        );
    }

    /**
     * Persist a CompanyFraudSettings row with the desired thresholds.
     *
     * @phpstan-param numeric-string $softOver
     * @phpstan-param numeric-string $hardOver
     * @phpstan-param numeric-string $softUnder
     * @phpstan-param numeric-string $hardUnder
     */
    private function setFraudSettings(
        string $softOver,
        string $hardOver,
        string $softUnder,
        string $hardUnder,
        bool $requirePin = true,
    ): void {
        CompanyFraudSettings::query()->updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'cash_variance_over_soft' => $softOver,
                'cash_variance_over_hard' => $hardOver,
                'cash_variance_under_soft' => $softUnder,
                'cash_variance_under_hard' => $hardUnder,
                'require_blind_cash_count' => false,
                'require_manager_pin_above_hard' => $requirePin,
                'cash_variance_email_severity' => 'none',
                'alert_enabled' => true,
                'auto_trigger_counting' => false,
                'auto_restrict_access' => false,
                'abandoned_draft_threshold' => 5,
                'time_window_days' => 30,
            ],
        );
    }

    /**
     * Seed a single receipt + payment row inside the shift window.
     */
    private function seedReceiptWithCashPayment(string $amount): Receipt
    {
        $receipt = Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'receipt_number' => 'T001-C001-L01-POS01-2026-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'chain_sequence' => 1,
            'receipt_year' => (int) date('Y'),
            'fiscal_hash' => hash('sha256', 'r-'.uniqid()),
            'previous_hash' => null,
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payments'),
            'posted_at' => now(),
            'cashier_id' => $this->cashier->id,
            'cashier_name' => 'Test Cashier',
            'subtotal' => $amount,
            'tax_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'total' => $amount,
            'currency' => 'EUR',
            'is_voided' => false,
            'is_training' => false,
        ]);

        ReceiptPayment::create([
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'Cash',
            'amount' => $amount,
        ]);

        return $receipt;
    }
}
