<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class PurchaseBonusQuantityEntryTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    #[Test]
    public function free_quantity_is_prohibited_when_purchase_bonus_gate_is_off(): void
    {
        $this->bootTenant(vertical: Vertical::Retail, countryCode: 'TN');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Bonus gated item',
                    'quantity' => '20',
                    'free_quantity' => '1',
                    'unit_price' => '5.000',
                    'price_entry_mode' => 'unit',
                ],
            ]));

        $this->assertApiValidationErrors($response, ['lines.0.free_quantity']);
    }

    #[Test]
    public function free_quantity_requires_allowed_country_even_when_module_is_enabled(): void
    {
        $this->bootTenant(vertical: Vertical::Parapharmacy, countryCode: 'FR');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Country gated item',
                    'quantity' => '20',
                    'free_quantity' => '1',
                    'unit_price' => '5.000',
                    'price_entry_mode' => 'unit',
                ],
            ]));

        $this->assertApiValidationErrors($response, ['lines.0.free_quantity']);
    }

    #[Test]
    public function purchase_order_persists_free_quantity_and_unit_mode_keeps_existing_line_total_math(): void
    {
        $this->bootTenant(vertical: Vertical::Parapharmacy, countryCode: 'TN');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Doliprane 1000mg',
                    'quantity' => '20',
                    'free_quantity' => '1',
                    'unit_price' => '5.000',
                    'price_entry_mode' => 'unit',
                ],
            ]));

        $response->assertCreated();
        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'quantity' => '20.0000',
            'free_quantity' => '1.0000',
            'unit_price' => '5.000',
            'line_total' => '100.000',
            'price_entry_mode' => 'unit',
            'is_bonus_line' => false,
        ]);
    }

    #[Test]
    public function purchase_order_create_preserves_explicit_null_line_fields(): void
    {
        $this->bootTenant(vertical: Vertical::Parapharmacy, countryCode: 'TN');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Nullable purchase line',
                    'quantity' => '3',
                    'free_quantity' => null,
                    'unit_price' => '9.000',
                    'discount_percent' => null,
                    'discount_amount' => null,
                    'notes' => null,
                ],
            ]));

        $response->assertCreated();
        $documentId = $response->json('data.id');

        $line = DocumentLine::query()
            ->where('document_id', $documentId)
            ->firstOrFail();

        $this->assertNull($line->discount_percent);
        $this->assertNull($line->discount_amount);
        $this->assertNull($line->notes);
    }

    #[Test]
    public function purchase_order_update_preserves_bonus_line_flag(): void
    {
        $this->bootTenant(vertical: Vertical::Parapharmacy, countryCode: 'TN');

        $create = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Initial purchase line',
                    'quantity' => '4',
                    'unit_price' => '7.000',
                    'price_entry_mode' => 'unit',
                ],
            ]));

        $create->assertCreated();
        $documentId = $create->json('data.id');

        $update = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/purchase-orders/{$documentId}", [
                'lines' => [
                    [
                        'description' => 'Updated bonus line',
                        'quantity' => '4',
                        'free_quantity' => '1',
                        'unit_price' => '7.000',
                        'price_entry_mode' => 'unit',
                        'is_bonus_line' => true,
                    ],
                ],
            ]);

        $update->assertOk();

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'description' => 'Updated bonus line',
            'is_bonus_line' => true,
        ]);
    }

    #[Test]
    public function total_mode_stores_entered_total_and_materializes_scale_six_cost_basis(): void
    {
        $this->bootTenant(vertical: Vertical::Parapharmacy, countryCode: 'TN');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Total mode item',
                    'quantity' => '7',
                    'free_quantity' => '0',
                    'unit_price' => '14.285',
                    'line_total' => '100.000',
                    'price_entry_mode' => 'total',
                ],
            ]));

        $response->assertCreated();
        $documentId = $response->json('data.id');

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $documentId,
            'quantity' => '7.0000',
            'unit_price' => '14.285',
            'line_total' => '100.000',
            'landed_unit_cost' => '14.285714',
            'price_entry_mode' => 'total',
        ]);
    }

    #[Test]
    public function total_mode_requires_a_three_decimal_line_total(): void
    {
        $this->bootTenant(vertical: Vertical::Parapharmacy, countryCode: 'TN');

        $missingTotal = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Missing total item',
                    'quantity' => '7',
                    'unit_price' => '14.285',
                    'price_entry_mode' => 'total',
                ],
            ]));

        $this->assertApiValidationErrors($missingTotal, ['lines.0.line_total']);

        $tooPrecise = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/purchase-orders', $this->purchaseOrderPayload([
                [
                    'description' => 'Too precise total item',
                    'quantity' => '7',
                    'unit_price' => '14.285',
                    'line_total' => '100.0001',
                    'price_entry_mode' => 'total',
                ],
            ]));

        $this->assertApiValidationErrors($tooPrecise, ['lines.0.line_total']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function purchaseOrderPayload(array $lines): array
    {
        return [
            'partner_id' => $this->supplier->id,
            'document_date' => now()->format('Y-m-d'),
            'lines' => $lines,
        ];
    }

    private function bootTenant(Vertical $vertical, string $countryCode): void
    {
        $this->tenant = Tenant::create([
            'name' => 'Purchase Bonus Test Tenant '.$vertical->value.' '.$countryCode,
            'slug' => 'purchase-bonus-test-'.str_replace('_', '-', $vertical->value).'-'.strtolower($countryCode),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Purchase Bonus Test Company',
            'legal_name' => 'Purchase Bonus Test Company SARL',
            'tax_id' => 'PBTAX'.$countryCode,
            'country_code' => $countryCode,
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => $countryCode === 'TN' ? 'TND' : 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Purchase Bonus Admin',
            'email' => 'purchase-bonus-'.$vertical->value.'-'.$countryCode.'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Purchase Bonus Supplier',
            'type' => 'supplier',
            'code' => 'PBSUP',
        ]);
    }
}
