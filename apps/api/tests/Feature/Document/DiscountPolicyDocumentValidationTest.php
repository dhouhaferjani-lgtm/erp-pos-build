<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\DiscountFloorMode;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\DiscountPolicyInterface;
use App\Shared\DTOs\DiscountPolicyContext;
use App\Shared\DTOs\DiscountPolicyVerdict;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class DiscountPolicyDocumentValidationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private User $manager;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Discount Policy Tenant',
            'slug' => 'discount-policy-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Discount Policy Co',
            'legal_name' => 'Discount Policy Co LLC',
            'tax_id' => 'TAX-DISCOUNT-POLICY',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
            'default_minimum_margin' => '10.00',
            'discount_floor_mode' => DiscountFloorMode::Advisory,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->cashier = $this->user('discount-policy-cashier@example.com');
        $this->cashier->assignRole('cashier');
        $this->cashier->givePermissionTo('orders.create');
        $this->attachToCompany($this->cashier, 'cashier');

        $this->manager = $this->user('discount-policy-manager@example.com');
        $this->manager->assignRole('manager');
        $this->attachToCompany($this->manager, 'manager');

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Policy Customer',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_advisory_mode_warns_but_does_not_reject_below_floor_invoice(): void
    {
        $this->company->update(['discount_floor_mode' => DiscountFloorMode::Advisory]);
        $product = $this->product();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/invoices', $this->payload($product, unitPrice: '105.000'));

        $response->assertCreated()
            ->assertJsonPath('meta.discount_policy_warnings.0.line', 0)
            ->assertJsonPath('meta.discount_policy_warnings.0.requires_permission', 'pricing.sell_below_minimum_margin');
    }

    public function test_block_mode_rejects_user_without_sell_below_minimum_margin(): void
    {
        $this->company->update(['discount_floor_mode' => DiscountFloorMode::Block]);
        $product = $this->product();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/invoices', $this->payload($product, unitPrice: '105.000'));

        $this->assertApiValidationErrors($response, ['lines.0.unit_price']);
    }

    public function test_block_mode_allows_user_with_existing_minimum_margin_permission(): void
    {
        $this->company->update(['discount_floor_mode' => DiscountFloorMode::Block]);
        $product = $this->product();

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/invoices', $this->payload($product, unitPrice: '105.000'))
            ->assertCreated()
            ->assertJsonPath('meta.discount_policy_warnings.0.requires_permission', 'pricing.sell_below_minimum_margin');
    }

    public function test_warn_requires_permission_rejects_user_without_existing_permission(): void
    {
        $this->company->update(['discount_floor_mode' => DiscountFloorMode::WarnRequiresPermission]);
        $product = $this->product();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/orders', $this->payload($product, unitPrice: '105.000'));

        $this->assertApiValidationErrors($response, ['lines.0.unit_price']);
    }

    public function test_warn_requires_permission_allows_holder_and_returns_warning_meta(): void
    {
        $this->company->update(['discount_floor_mode' => DiscountFloorMode::WarnRequiresPermission]);
        $product = $this->product();

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/orders', $this->payload($product, unitPrice: '105.000'))
            ->assertCreated()
            ->assertJsonPath('meta.discount_policy_warnings.0.requires_permission', 'pricing.sell_below_minimum_margin');
    }

    public function test_quote_route_skips_discount_policy_validation(): void
    {
        $this->company->update(['discount_floor_mode' => DiscountFloorMode::Block]);
        $product = $this->product();

        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/quotes', $this->payload($product, unitPrice: '1.000'))
            ->assertCreated()
            ->assertJsonMissingPath('meta.discount_policy_warnings');
    }

    public function test_document_validator_batches_product_lines(): void
    {
        $spy = new SpyDiscountPolicyService;
        $this->app->instance(DiscountPolicyInterface::class, $spy);
        $first = $this->product(sku: 'POL-BATCH-1');
        $second = $this->product(sku: 'POL-BATCH-2');

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/orders', $this->payloadWithTwoProductLines($first, $second))
            ->assertCreated();

        self::assertSame(1, $spy->resolveManyCalls);
        self::assertCount(2, $spy->lastContexts);
    }

    public function test_document_validator_threads_line_variant_id_into_context(): void
    {
        $spy = new SpyDiscountPolicyService;
        $this->app->instance(DiscountPolicyInterface::class, $spy);
        $product = $this->product();
        $variantId = Str::uuid()->toString();

        $payload = $this->payload($product, unitPrice: '150.000');
        $payload['lines'][0]['variant_id'] = $variantId;

        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();

        self::assertArrayHasKey('line-0', $spy->lastContexts);
        self::assertSame($variantId, $spy->lastContexts['line-0']->variantId);
    }

    public function test_sales_order_persists_the_same_discounted_net_price_checked_by_policy(): void
    {
        $this->company->update(['discount_floor_mode' => DiscountFloorMode::Block]);
        $product = $this->product();

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/orders', [
                ...$this->payload($product, unitPrice: '120.000'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => $product->name,
                        'quantity' => '1.0000',
                        'unit_price' => '120.000',
                        'discount_amount' => '5.000',
                        'tax_rate' => '20.00',
                    ],
                ],
            ]);

        $response->assertCreated();

        $line = DocumentLine::query()
            ->where('document_id', $response->json('data.id'))
            ->firstOrFail();

        self::assertSame('115.000', (string) $line->line_total);
    }

    public function test_sales_order_to_invoice_conversion_carries_discounted_net_line_total_once(): void
    {
        $product = $this->product();
        $product->update(['is_physical' => false]);

        $orderResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/orders', [
                ...$this->payload($product, unitPrice: '120.000'),
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => $product->name,
                        'quantity' => '1.0000',
                        'unit_price' => '120.000',
                        'discount_amount' => '5.000',
                        'tax_rate' => '20.00',
                    ],
                ],
            ]);
        $orderResponse->assertCreated();
        $orderId = $orderResponse->json('data.id');
        // R-2 / LEDGER D-T9-1 — the fixture confirms by hand, so it must mint the
        // number by hand too. A DRAFT is now born unnumbered and the number is
        // allocated by `DocumentStatusService` on the transition out of `Draft`;
        // a raw `update(['status' => Confirmed])` skips that, and a CONFIRMED
        // order with a NULL number is a state the application can no longer
        // produce (every converter refuses a draft source, and
        // `DocumentConverted` types `sourceDocumentNumber` as a non-null string).
        Document::query()->where('id', $orderId)->update([
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-'.date('Y').'-9001',
        ]);

        $invoiceResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/orders/{$orderId}/convert-to-invoice");

        $invoiceResponse->assertCreated();
        $invoiceLine = DocumentLine::query()
            ->where('document_id', $invoiceResponse->json('data.id'))
            ->firstOrFail();

        self::assertSame('115.000', (string) $invoiceLine->line_total);
    }

    private function user(string $email): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
    }

    private function attachToCompany(User $user, string $role): void
    {
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);
    }

    private function product(string $sku = 'POL-001'): Product
    {
        return Product::factory()->for($this->tenant)->for($this->company)->create([
            'name' => $sku,
            'sku' => $sku,
            'cost_price' => '100.000000',
            'sale_price' => '150.000',
            'minimum_margin_override' => '10.00',
            'tax_rate' => '20.00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, string $unitPrice): array
    {
        return [
            'partner_id' => $this->customer->id,
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'currency' => 'EUR',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => '1.0000',
                    'unit_price' => $unitPrice,
                    'tax_rate' => '20.00',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadWithTwoProductLines(Product $first, Product $second): array
    {
        $payload = $this->payload($first, '150.000');
        $payload['lines'][] = [
            'product_id' => $second->id,
            'description' => $second->name,
            'quantity' => '2.0000',
            'unit_price' => '160.000',
            'tax_rate' => '20.00',
        ];

        return $payload;
    }
}

final class SpyDiscountPolicyService implements DiscountPolicyInterface
{
    public int $resolveManyCalls = 0;

    /** @var array<string, DiscountPolicyContext> */
    public array $lastContexts = [];

    public function resolve(DiscountPolicyContext $context): DiscountPolicyVerdict
    {
        return $this->verdict();
    }

    public function resolveMany(array $contexts): array
    {
        $this->resolveManyCalls++;
        $this->lastContexts = $contexts;

        return array_fill_keys(array_keys($contexts), $this->verdict());
    }

    private function verdict(): DiscountPolicyVerdict
    {
        return new DiscountPolicyVerdict(
            allowed: true,
            blocksSale: false,
            severity: 'ok',
            requiresPermission: null,
            maxDiscountPercent: '100.00',
            discountPercent: null,
            floorPriceNet: null,
            floorBasis: 'None',
            floorEnforcement: DiscountFloorMode::Advisory->value,
            mode: DiscountFloorMode::Advisory->value,
            overridable: false,
            requiresReason: false,
            policyVersion: 'test-version',
            policyAsOf: '2026-07-08T00:00:00+00:00',
        );
    }
}
