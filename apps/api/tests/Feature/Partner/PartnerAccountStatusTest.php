<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Application\Services\CustomerAccountStatusService;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class PartnerAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_accounts_default_to_active_status_and_version_one(): void
    {
        [$tenant, $company] = $this->tenantAndCompany();

        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->assertSame(CustomerAccountStatus::Active, $partner->account_status);
        $this->assertSame(1, $partner->account_status_version);
        $this->assertNull($partner->account_status_changed_at);
        $this->assertNull($partner->account_status_changed_by);
        $this->assertNull($partner->account_status_reason);
    }

    public function test_transition_requires_actor_and_reason(): void
    {
        [$tenant, $company] = $this->tenantAndCompany();
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(CustomerAccountStatusService::class)->transition(
            tenantId: $tenant->id,
            companyId: $company->id,
            partnerId: $partner->id,
            newStatus: CustomerAccountStatus::Suspended,
            actorUserId: '',
            reason: 'Risk review',
        );
    }

    public function test_closed_accounts_do_not_reopen_without_a_new_explicit_lifecycle(): void
    {
        [$tenant, $company] = $this->tenantAndCompany();
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'account_status' => CustomerAccountStatus::Closed,
            'account_status_version' => 4,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(CustomerAccountStatusService::class)->transition(
            tenantId: $tenant->id,
            companyId: $company->id,
            partnerId: $partner->id,
            newStatus: CustomerAccountStatus::Active,
            actorUserId: $actor->id,
            reason: 'Customer reactivation request',
        );
    }

    public function test_status_mutation_rolls_back_when_fiscal_append_fails(): void
    {
        [$tenant, $company] = $this->tenantAndCompany();
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(CustomerAccountStatusService::class)->transition(
                tenantId: $tenant->id,
                companyId: $company->id,
                partnerId: $partner->id,
                newStatus: CustomerAccountStatus::Suspended,
                actorUserId: $actor->id,
                reason: 'No usable location for fiscal authoring',
            );
        } finally {
            $partner->refresh();

            $this->assertSame(CustomerAccountStatus::Active, $partner->account_status);
            $this->assertSame(1, $partner->account_status_version);
            $this->assertDatabaseMissing('fiscal_events', [
                'event_type' => 'ACCOUNT_STATUS_CHANGED',
                'partner_id' => $partner->id,
            ]);
        }
    }

    /**
     * @return array{0: Tenant, 1: Company}
     */
    private function tenantAndCompany(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $company];
    }
}
