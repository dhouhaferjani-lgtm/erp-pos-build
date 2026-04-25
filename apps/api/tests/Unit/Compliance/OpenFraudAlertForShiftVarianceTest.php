<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Application\Contracts\NotificationDispatcherInterface;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Modules\Compliance\Domain\FraudAlert;
use App\Modules\Compliance\Infrastructure\Repositories\CompanyFraudSettingsRepository;
use App\Modules\Compliance\Infrastructure\Repositories\FraudAlertRepository;
use App\Modules\Compliance\Listeners\OpenFraudAlertForShiftVariance;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hand-written spy implementing NotificationDispatcherInterface.
 *
 * ComplianceNotificationDispatcher is final and cannot be subclassed by
 * test frameworks. We spy at the interface level instead.
 */
final class NotificationDispatcherSpy implements NotificationDispatcherInterface
{
    /** @var array<int, FraudAlert> */
    public array $dispatched = [];

    public function dispatchToFraudEmails(FraudAlert $alert): void
    {
        $this->dispatched[] = $alert;
    }
}

final class OpenFraudAlertForShiftVarianceTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function makeSpy(): NotificationDispatcherSpy
    {
        return new NotificationDispatcherSpy;
    }

    private function makeEvent(
        string $zReportId,
        string $varianceAmount,
        VarianceSeverity $severity,
        string $cashierId,
        string $tenantId,
        string $companyId,
        ?string $managerOverrideBy = null,
        bool $blindCountUsed = false,
        string $currencyCode = 'EUR',
        string $descriptionCode = 'pos.cash_count.warning',
    ): CashCountRecorded {
        $cmp = bccomp($varianceAmount, '0', 4);

        $direction = match (true) {
            $cmp === 0 => VarianceDirection::Balanced,
            $cmp > 0 => VarianceDirection::Over,
            default => VarianceDirection::Under,
        };

        return new CashCountRecorded(
            zReportId: $zReportId,
            shiftId: Str::uuid()->toString(),
            terminalId: Str::uuid()->toString(),
            tenantId: $tenantId,
            companyId: $companyId,
            cashierId: $cashierId,
            managerOverrideBy: $managerOverrideBy,
            blindCountUsed: $blindCountUsed,
            currencyCode: $currencyCode,
            aggregateVariance: new VarianceAmount($varianceAmount, $currencyCode),
            varianceDirection: $direction,
            severity: $severity,
            tenderBreakdown: [],
            descriptionCode: $descriptionCode,
            descriptionParams: [
                'severity' => $severity->value,
                'aggregate_amount' => $varianceAmount,
                'currency_code' => $currencyCode,
                'cashier_name' => 'Alice',
                'manager_name' => 'Bob',
                'terminal_code' => 'POS-01',
                'z_number' => '42',
            ],
            recordedAt: now()->toIso8601String(),
        );
    }

    private function makeFraudSettings(string $companyId, string $emailSeverity = 'none'): CompanyFraudSettings
    {
        return CompanyFraudSettings::updateOrCreate(
            ['company_id' => $companyId],
            [
                'abandoned_draft_threshold' => 5,
                'time_window_days' => 30,
                'alert_enabled' => true,
                'auto_trigger_counting' => false,
                'auto_restrict_access' => false,
                'cash_variance_email_severity' => $emailSeverity,
            ]
        );
    }

    private function makeListener(NotificationDispatcherSpy $spy): OpenFraudAlertForShiftVariance
    {
        return new OpenFraudAlertForShiftVariance(
            new FraudAlertRepository,
            new CompanyFraudSettingsRepository,
            $spy,
        );
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    /** Test 1: Zero variance → no alert row and no email. */
    public function test_zero_variance_skips_alert_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->makeFraudSettings($company->id);

        $spy = $this->makeSpy();

        $listener = $this->makeListener($spy);

        $event = $this->makeEvent(
            zReportId: Str::uuid()->toString(),
            varianceAmount: '0.0000',
            severity: VarianceSeverity::Info,
            cashierId: $cashier->id,
            tenantId: $tenant->id,
            companyId: $company->id,
        );

        $listener->handle($event);

        $this->assertDatabaseCount('fraud_alerts', 0);
        $this->assertCount(0, $spy->dispatched);
    }

    /** Test 2: INFO severity + email threshold 'none' → alert row created, no email dispatched. */
    public function test_info_severity_with_none_email_threshold_creates_alert_but_no_email(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->makeFraudSettings($company->id, 'none');

        $spy = $this->makeSpy();
        $listener = $this->makeListener($spy);

        $listener->handle($this->makeEvent(
            zReportId: Str::uuid()->toString(),
            varianceAmount: '2.5000',
            severity: VarianceSeverity::Info,
            cashierId: $cashier->id,
            tenantId: $tenant->id,
            companyId: $company->id,
        ));

        $this->assertDatabaseHas('fraud_alerts', [
            'alert_type' => 'SHIFT_CLOSE_VARIANCE',
            'severity' => 'info',
            'status' => 'open',
        ]);

        $this->assertCount(0, $spy->dispatched);
    }

    /** Test 3: CRITICAL severity + threshold 'critical' → alert created and email dispatched once. */
    public function test_critical_severity_at_critical_threshold_creates_alert_and_emails(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->makeFraudSettings($company->id, 'critical');

        $spy = $this->makeSpy();
        $listener = $this->makeListener($spy);

        $listener->handle($this->makeEvent(
            zReportId: Str::uuid()->toString(),
            varianceAmount: '50.0000',
            severity: VarianceSeverity::Critical,
            cashierId: $cashier->id,
            tenantId: $tenant->id,
            companyId: $company->id,
        ));

        $this->assertDatabaseHas('fraud_alerts', [
            'alert_type' => 'SHIFT_CLOSE_VARIANCE',
            'severity' => 'critical',
        ]);

        $this->assertCount(1, $spy->dispatched);
        $this->assertSame('SHIFT_CLOSE_VARIANCE', $spy->dispatched[0]->alert_type);
    }

    /** Test 4: Firing the same event twice yields exactly one alert row (idempotency). */
    public function test_idempotency_same_event_twice_creates_one_alert(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->makeFraudSettings($company->id, 'none');

        $spy = $this->makeSpy();
        $listener = $this->makeListener($spy);

        $event = $this->makeEvent(
            zReportId: Str::uuid()->toString(),
            varianceAmount: '5.0000',
            severity: VarianceSeverity::Warning,
            cashierId: $cashier->id,
            tenantId: $tenant->id,
            companyId: $company->id,
        );

        $listener->handle($event);
        $listener->handle($event);

        $this->assertDatabaseCount('fraud_alerts', 1);
    }

    /** Test 5: description_params carries cashier_name, manager_name, z_number on the persisted row. */
    public function test_description_params_persisted_with_expected_keys(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->makeFraudSettings($company->id, 'none');

        $spy = $this->makeSpy();
        $listener = $this->makeListener($spy);

        $event = new CashCountRecorded(
            zReportId: Str::uuid()->toString(),
            shiftId: Str::uuid()->toString(),
            terminalId: Str::uuid()->toString(),
            tenantId: $tenant->id,
            companyId: $company->id,
            cashierId: $cashier->id,
            managerOverrideBy: $manager->id,
            blindCountUsed: true,
            currencyCode: 'EUR',
            aggregateVariance: new VarianceAmount('8.5000', 'EUR'),
            varianceDirection: VarianceDirection::Over,
            severity: VarianceSeverity::Warning,
            tenderBreakdown: [],
            descriptionCode: 'pos.cash_count.warning',
            descriptionParams: [
                'severity' => 'warning',
                'aggregate_amount' => '8.5000',
                'currency_code' => 'EUR',
                'cashier_name' => 'Alice Dupont',
                'manager_name' => 'Bob Martin',
                'terminal_code' => 'POS-01',
                'z_number' => '99',
            ],
            recordedAt: now()->toIso8601String(),
        );

        $listener->handle($event);

        $alert = FraudAlert::query()
            ->where('alert_type', 'SHIFT_CLOSE_VARIANCE')
            ->firstOrFail();

        $this->assertIsArray($alert->description_params);
        $this->assertSame('Alice Dupont', $alert->description_params['cashier_name']);
        $this->assertSame('Bob Martin', $alert->description_params['manager_name']);
        $this->assertSame('99', $alert->description_params['z_number']);
        $this->assertSame('pos.cash_count.warning', $alert->description_code);
    }

    /** Test 6: description column is populated with a deterministic EN fallback string. */
    public function test_description_column_is_populated_with_fallback_string(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->makeFraudSettings($company->id, 'none');

        $spy = $this->makeSpy();
        $listener = $this->makeListener($spy);

        $listener->handle($this->makeEvent(
            zReportId: Str::uuid()->toString(),
            varianceAmount: '5.5000',
            severity: VarianceSeverity::Warning,
            cashierId: $cashier->id,
            tenantId: $tenant->id,
            companyId: $company->id,
            currencyCode: 'EUR',
        ));

        $alert = FraudAlert::query()
            ->where('alert_type', 'SHIFT_CLOSE_VARIANCE')
            ->firstOrFail();

        $this->assertNotSame('', $alert->description);
        $this->assertStringContainsString('WARNING', $alert->description);
        $this->assertStringContainsString('5.5000', $alert->description);
        $this->assertStringContainsString('EUR', $alert->description);
    }
}
