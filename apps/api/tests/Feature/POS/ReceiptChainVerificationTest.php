<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReceiptChainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.view_reports', 'sanctum');
        $this->user->givePermissionTo('pos.view_reports');

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_verify_receipt_chain_returns_valid_for_empty_chain(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.chain_length', 0)
            ->assertJsonPath('data.first_receipt', null)
            ->assertJsonPath('data.last_receipt', null)
            ->assertJsonPath('data.broken_at_sequence', null)
            ->assertJsonStructure([
                'data' => [
                    'is_valid',
                    'chain_length',
                    'first_receipt',
                    'last_receipt',
                    'broken_at_sequence',
                    'verified_at',
                ],
            ]);
    }

    public function test_verify_receipt_chain_returns_chain_stats(): void
    {
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'receipt_number' => 'REC-001',
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'test'),
            'is_voided' => false,
            'cashier_id' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.chain_length', 1)
            ->assertJsonPath('data.first_receipt', 'REC-001')
            ->assertJsonPath('data.last_receipt', 'REC-001');

        $this->assertNotNull($response->json('data.verified_at'));
    }

    public function test_verify_receipt_chain_requires_authorization(): void
    {
        $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        Sanctum::actingAs($userWithoutPermission);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_verify_receipt_chain_enforces_company_ownership(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $otherTerminal->id,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_verify_receipt_chain_requires_valid_terminal_id(): void
    {
        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => 'not-a-uuid',
        ]);

        $response->assertUnprocessable();
    }

    public function test_verify_receipt_chain_detects_broken_chain(): void
    {
        // The first receipt has a fabricated fiscal_hash that won't match
        // what ReceiptHashService::calculateHash() produces, so the chain
        // is detected as broken at sequence 1.
        Receipt::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'chain_sequence' => 1,
            'receipt_number' => 'REC-001',
            'previous_hash' => null,
            'fiscal_hash' => hash('sha256', 'fabricated-hash'),
            'is_voided' => false,
            'cashier_id' => $this->user->id,
        ]);

        $response = $this->postJson('/api/v1/pos/reports/receipts/verify-chain', [
            'terminal_id' => $this->terminal->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.is_valid', false)
            ->assertJsonPath('data.chain_length', 1)
            ->assertJsonPath('data.broken_at_sequence', 1);
    }
}
