<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BUG-005 / RCA B1 — the onboarding checklist runs 7 uncaught DB checks across
 * 5 modules. Any single failure — a tenant whose migration lane is behind and
 * is missing a table, a transient PG error — 500s the whole `/settings/setup`
 * page.
 *
 * One broken module must degrade its own step, never blank the page.
 */
final class OnboardingChecklistFaultIsolationTest extends TestCase
{
    use RefreshDatabase;

    private OnboardingChecklistService $checklist;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checklist = new OnboardingChecklistService;
        $this->company = $this->makeCompany();
    }

    public function test_checklist_degrades_a_single_step_when_its_table_is_missing(): void
    {
        // Simulate a tenant whose Treasury migration lane is behind.
        Schema::drop('payment_methods');

        $steps = $this->checklist->getStatus($this->company->id);

        self::assertCount(count(OnboardingStep::cases()), $steps, 'Every step must still be reported');

        $broken = $this->findStep($steps, OnboardingStep::PaymentMethods->value);

        self::assertFalse($broken['completed'], 'A step whose check blew up must report completed=false');
        self::assertTrue($broken['degraded'], 'A step whose check blew up must be flagged degraded');

        // Every other step must still be evaluated normally.
        foreach ($steps as $step) {
            if ($step['step'] === OnboardingStep::PaymentMethods->value) {
                continue;
            }
            self::assertFalse(
                $step['degraded'],
                'Step '.$step['step'].' must not be degraded by an unrelated module failing'
            );
        }
    }

    public function test_healthy_steps_are_not_flagged_degraded(): void
    {
        $steps = $this->checklist->getStatus($this->company->id);

        foreach ($steps as $step) {
            self::assertFalse($step['degraded'], 'Step '.$step['step'].' must not be degraded on a healthy tenant');
        }
    }

    /**
     * @param  list<array{step: string, label: string, completed: bool, required: bool, settings_path: string, degraded: bool}>  $steps
     * @return array{step: string, label: string, completed: bool, required: bool, settings_path: string, degraded: bool}
     */
    private function findStep(array $steps, string $key): array
    {
        foreach ($steps as $step) {
            if ($step['step'] === $key) {
                return $step;
            }
        }

        $this->fail('Step '.$key.' not found in onboarding checklist');
    }

    private function makeCompany(): Company
    {
        $tenant = Tenant::create([
            'name' => 'Checklist Fault Isolation Tenant',
            'slug' => 'checklist-fault-isolation-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Trial,
            'country_code' => 'TN',
            'currency_code' => 'TND',
        ]);

        return Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Checklist Fault Isolation Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'UTC',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,
        ]);
    }
}
