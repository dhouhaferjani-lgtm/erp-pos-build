<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Feature tests for the anti-abuse discount/tolerance boundary on document
 * create + update endpoints (spec §7). The rule fires only on document types
 * where a payment is becoming due (Invoice, SalesOrder); Quote and CreditNote
 * remain exempt — Quote because it is still negotiation-stage (the conversion
 * auto-strip in Task 14 catches sub-tolerance discounts at conversion time),
 * CreditNote because money flows outward (no skimming vector).
 */
final class DiscountToleranceValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        // FR country + CountryPaymentSettings with €0.50 absolute / 0.5% pct
        // (skip if a previous test already seeded it via RefreshDatabase reset).
        Country::firstOrCreate(
            ['code' => 'FR'],
            ['name' => 'France', 'currency_code' => 'EUR', 'currency_symbol' => '€'],
        );

        CountryPaymentSettings::firstOrCreate(
            ['country_code' => 'FR'],
            [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => '0.0050',
                'max_payment_tolerance_amount' => '0.500',
            ],
        );

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'type' => PartnerType::Customer,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Consulting Service',
            'sku' => 'SVC-001',
            'type' => ProductType::Service,
            'sale_price' => '100.00',
            'tax_rate' => '20.00',
        ]);
    }

    public function test_invoice_rejects_sub_tolerance_line_discount(): void
    {
        // €0.20 line discount on €100 line: margin = max(€0.50, 100 * 0.5%) = €0.50.
        // 0.20 <= 0.50 → boundary rejects.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->payloadWithLineDiscount('0.20'));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    public function test_invoice_rejects_at_threshold_strict_inequality(): void
    {
        // Discount EQUAL to margin still rejects — strict `>` semantics.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->payloadWithLineDiscount('0.50'));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    public function test_invoice_accepts_above_tolerance_line_discount(): void
    {
        // €5.00 discount > €0.50 margin — well above, accepted.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->payloadWithLineDiscount('5.00'));

        $response->assertCreated();
    }

    public function test_sales_order_subject_to_rule(): void
    {
        // Sales orders are payment-due documents per spec §7 — rule fires.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', $this->payloadWithLineDiscount('0.20'));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    public function test_quote_not_subject_to_rule(): void
    {
        // Quote is negotiation-only; conversion-time auto-strip (Task 14) handles
        // sub-tolerance line discounts when the quote graduates to an order.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/quotes', $this->payloadWithLineDiscount('0.20'));

        $response->assertCreated();
    }

    public function test_zero_discount_passes_validation_on_invoice(): void
    {
        // Zero discount means "no discount applied" — rule short-circuits.
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->payloadWithLineDiscount('0'));

        $response->assertCreated();
    }

    public function test_invoice_update_rejects_sub_tolerance_discount(): void
    {
        // Create invoice in draft, then attempt to update with sub-tolerance discount.
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/invoices', $this->payloadWithLineDiscount('5.00'));
        $created->assertCreated();
        $invoiceId = $created->json('data.id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/invoices/{$invoiceId}", $this->payloadWithLineDiscount('0.10'));

        $this->assertApiValidationErrors($response, ['lines.0.discount_amount']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadWithLineDiscount(string $discountAmount): array
    {
        return [
            'partner_id' => $this->customer->id,
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'EUR',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'description' => 'Consulting Services',
                    'quantity' => '1.00',
                    'unit_price' => '100.00',
                    'discount_amount' => $discountAmount,
                    'tax_rate' => '20.00',
                ],
            ],
        ];
    }
}
