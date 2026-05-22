<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Application\Services\CustomerAccountStatusService;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountStatusChangedConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_status_transitions_advance_partner_version_and_terminal_chain_serially(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        Location::factory()->create(['company_id' => $company->id]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);

        $service = app(CustomerAccountStatusService::class);

        $service->transition(
            tenantId: $tenant->id,
            companyId: $company->id,
            partnerId: $partner->id,
            newStatus: CustomerAccountStatus::Suspended,
            actorUserId: $actor->id,
            reason: 'Credit control hold',
        );

        $changed = $service->transition(
            tenantId: $tenant->id,
            companyId: $company->id,
            partnerId: $partner->id,
            newStatus: CustomerAccountStatus::Disputed,
            actorUserId: $actor->id,
            reason: 'Customer disputes receivable',
        );

        $this->assertSame(CustomerAccountStatus::Disputed, $changed->account_status);
        $this->assertSame(3, $changed->account_status_version);

        $events = FiscalEvent::query()
            ->where('event_type', FiscalEventType::ACCOUNT_STATUS_CHANGED)
            ->where('partner_id', $partner->id)
            ->orderBy('sequence_number')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(1, $events[0]->sequence_number);
        $this->assertSame(2, $events[1]->sequence_number);
        $this->assertSame($events[0]->current_hash, $events[1]->previous_hash);
        $this->assertSame(2, $events[0]->payload['status_version']);
        $this->assertSame(3, $events[1]->payload['status_version']);
    }
}
