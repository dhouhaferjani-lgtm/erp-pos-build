<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\PosCustomerAlias;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PosPendingCustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);

        Gate::before(static fn (User $user, string $ability): ?bool => $ability === 'pos.operate_terminal' ? true : null);

        Sanctum::actingAs($this->cashier);
    }

    public function test_pending_customer_create_returns_tenant_company_scoped_alias(): void
    {
        $clientUuid = '00000000-0000-4000-8000-000000000101';

        $response = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pos/customers/pending', [
                'client_customer_uuid' => $clientUuid,
                'name' => 'Sarah Ben Ali',
                'phone' => '+216 20 100 200',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.client_customer_uuid', $clientUuid);
        $response->assertJsonPath('data.tenant_id', $this->tenant->id);
        $response->assertJsonPath('data.company_id', $this->company->id);
        $response->assertJsonPath('data.server_partner_id', fn (string $id): bool => $id !== '');

        $partner = Partner::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('company_id', $this->company->id)
            ->where('name', 'Sarah Ben Ali')
            ->firstOrFail();

        $this->assertSame(PartnerType::Customer, $partner->type);
        $this->assertSame($partner->id, $response->json('data.server_partner_id'));
        $this->assertDatabaseHas('pos_customer_aliases', [
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'client_customer_uuid' => $clientUuid,
            'server_partner_id' => $partner->id,
        ]);
    }

    public function test_pending_customer_create_is_idempotent_by_client_customer_uuid(): void
    {
        $clientUuid = '00000000-0000-4000-8000-000000000102';
        $payload = [
            'client_customer_uuid' => $clientUuid,
            'name' => 'Sarah Ben Ali',
            'phone' => '+216 20 100 200',
        ];

        $first = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pos/customers/pending', $payload);
        $second = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pos/customers/pending', array_merge($payload, [
                'name' => 'Changed Locally',
            ]));

        $first->assertCreated();
        $second->assertOk();
        $this->assertSame($first->json('data.server_partner_id'), $second->json('data.server_partner_id'));
        $this->assertSame(1, PosCustomerAlias::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('company_id', $this->company->id)
            ->where('client_customer_uuid', $clientUuid)
            ->count());
    }

    public function test_pending_customer_create_rejects_cross_company_alias_conflict(): void
    {
        $clientUuid = '00000000-0000-4000-8000-000000000103';
        $otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'type' => PartnerType::Customer,
        ]);
        PosCustomerAlias::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'client_customer_uuid' => $clientUuid,
            'server_partner_id' => $partner->id,
        ]);

        $response = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pos/customers/pending', [
                'client_customer_uuid' => $clientUuid,
                'name' => 'Sarah Ben Ali',
                'phone' => '+216 20 100 200',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'POS_CUSTOMER_ALIAS_COMPANY_CONFLICT');
    }

    public function test_treasury_alias_lookup_can_resolve_server_partner_id_after_replay(): void
    {
        $clientUuid = '00000000-0000-4000-8000-000000000104';
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Customer,
        ]);
        PosCustomerAlias::query()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'client_customer_uuid' => $clientUuid,
            'server_partner_id' => $partner->id,
        ]);

        $this->assertSame(
            $partner->id,
            PosCustomerAlias::resolveServerPartnerId($this->tenant->id, $this->company->id, $clientUuid),
        );
        $this->assertNull(
            PosCustomerAlias::resolveServerPartnerId($this->tenant->id, '00000000-0000-4000-8000-000000000999', $clientUuid),
        );
    }

    public function test_pending_customer_create_requires_a_contact_key(): void
    {
        $response = $this
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pos/customers/pending', [
                'client_customer_uuid' => '00000000-0000-4000-8000-000000000105',
                'name' => 'No Contact',
            ]);

        $response->assertStatus(422);
    }
}
