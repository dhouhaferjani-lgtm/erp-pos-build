<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\CreateRfqData;
use App\Modules\Procurement\Application\PurchaseQuoteRequestService;
use App\Modules\Procurement\Application\UpdateRfqData;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

final class PurchaseQuoteRequestHttpTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    private User $viewer;

    private Product $product;

    /** @var list<Partner> */
    private array $suppliers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'RFQ HTTP Tenant',
            'slug' => 'rfq-http-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'RFQ HTTP Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = $this->user('rfq-admin@example.test', 'admin');
        $this->viewer = $this->user('rfq-viewer@example.test', 'viewer');

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Lait hydratant',
        ]);

        $this->suppliers = [
            $this->supplier('Supplier A'),
            $this->supplier('Supplier B'),
        ];
    }

    public function test_admin_can_create_group_and_fetch_comparison_shape(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', $this->payload());

        $response->assertCreated();
        $groupId = $response->json('data.group_id');
        $this->assertIsString($groupId);

        $groupResponse = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/purchase-quote-requests/groups/{$groupId}");

        $groupResponse->assertOk()
            ->assertJsonPath('data.group_id', $groupId)
            ->assertJsonCount(2, 'data.siblings')
            ->assertJsonStructure([
                'data' => [
                    'group_id',
                    'siblings' => [
                        [
                            'id',
                            'number',
                            'partner' => ['id', 'name'],
                            'status',
                            'validity_date',
                            'lead_time_days',
                            'responded_at',
                            'total',
                            'lines' => [
                                [
                                    'product_id',
                                    'variant_id',
                                    'quantity',
                                    'unit_price',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_rfq_group_uses_company_currency_and_currency_scale(): void
    {
        $this->company->update([
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', $this->payload([
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1.0000',
                    'unit_price' => '1.239',
                    'description' => 'EUR precision line',
                ],
            ]));

        $response->assertCreated();
        $rfqId = $response->json('data.siblings.0.id');

        $rfq = Document::query()->with('lines')->findOrFail($rfqId);

        $this->assertSame('EUR', $rfq->currency);
        $this->assertSame('1.230', $rfq->subtotal);
        $this->assertSame('1.230', $rfq->lines->first()?->line_total);
    }

    public function test_viewer_cannot_create_rfq_group(): void
    {
        $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', $this->payload())
            ->assertForbidden();
    }

    public function test_viewer_permission_matrix_blocks_all_mutation_routes(): void
    {
        $rfq = $this->createRfqInCompany($this->company, $this->suppliers[0], $this->product);
        $groupId = (string) $rfq->payload['rfq']['group_id'];

        $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', $this->payload())
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson("/api/v1/purchase-quote-requests/{$rfq->id}", $this->responsePayload())
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/{$rfq->id}/send")
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/{$rfq->id}/convert-to-po")
            ->assertForbidden();

        $this->actingAs($this->viewer, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/groups/{$groupId}/reopen")
            ->assertForbidden();
    }

    public function test_mutation_routes_do_not_cross_company_boundaries(): void
    {
        [$otherCompany, $otherProduct, $otherSupplier] = $this->otherCompanyFixture();
        $otherRfq = $this->createRfqInCompany($otherCompany, $otherSupplier, $otherProduct);
        $otherRespondedRfq = $this->recordResponse($otherRfq, $otherProduct);
        $otherGroupId = (string) $otherRespondedRfq->payload['rfq']['group_id'];

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson("/api/v1/purchase-quote-requests/{$otherRespondedRfq->id}", $this->responsePayload())
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/{$otherRespondedRfq->id}/send")
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/{$otherRespondedRfq->id}/convert-to-po")
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/groups/{$otherGroupId}/reopen")
            ->assertNotFound();

        $this->assertSame(DocumentStatus::Confirmed, $otherRespondedRfq->refresh()->status);
    }

    public function test_mutation_routes_are_covered_over_http(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', $this->payload());

        $response->assertCreated();
        $firstId = (string) $response->json('data.siblings.0.id');
        $secondId = (string) $response->json('data.siblings.1.id');
        $groupId = (string) $response->json('data.group_id');

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/{$firstId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson("/api/v1/purchase-quote-requests/{$firstId}", $this->responsePayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $convert = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/{$firstId}/convert-to-po");

        $convert->assertOk()
            ->assertJsonPath('data.type', 'purchase_order');

        $poId = $convert->json('data.id');
        Document::query()->whereKey($poId)->update(['status' => DocumentStatus::Cancelled]);

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/purchase-quote-requests/groups/{$groupId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.reopened', 1);

        $this->assertSame(DocumentStatus::Draft, Document::query()->findOrFail($secondId)->status);
    }

    public function test_non_uuid_route_parameters_return_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/purchase-quote-requests/not-a-uuid')
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->putJson('/api/v1/purchase-quote-requests/not-a-uuid', $this->responsePayload())
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests/not-a-uuid/send')
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests/not-a-uuid/convert-to-po')
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/purchase-quote-requests/groups/not-a-uuid')
            ->assertNotFound();

        $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests/groups/not-a-uuid/reopen')
            ->assertNotFound();
    }

    public function test_validation_errors_use_api_error_envelope(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', [
                'partner_ids' => [],
                'lines' => [
                    [
                        'product_id' => 'not-a-uuid',
                        'quantity' => '1.12345',
                        'unit_price' => '8.1234',
                    ],
                ],
            ]);

        $this->assertApiValidationErrors($response, ['partner_ids', 'lines.0.quantity', 'lines.0.unit_price']);
    }

    public function test_product_and_partner_validation_is_company_scoped(): void
    {
        [$otherCompany, $otherProduct] = $this->otherCompanyFixture();

        $otherProductResponse = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', $this->payload([
                [
                    'product_id' => $otherProduct->id,
                    'quantity' => '1.0000',
                    'unit_price' => null,
                    'description' => 'Foreign product',
                ],
            ]));

        $this->assertApiValidationErrors($otherProductResponse, ['lines.0.product_id']);

        $missingProductResponse = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', $this->payload([
                [
                    'product_id' => '00000000-0000-4000-8000-000000000001',
                    'quantity' => '1.0000',
                    'unit_price' => null,
                    'description' => 'Missing product',
                ],
            ]));

        $this->assertApiValidationErrors($missingProductResponse, ['lines.0.product_id']);

        $duplicatePartnerResponse = $this->actingAs($this->admin, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/purchase-quote-requests', [
                ...$this->payload(),
                'partner_ids' => [$this->suppliers[0]->id, $this->suppliers[0]->id],
            ]);

        $this->assertApiValidationErrors($duplicatePartnerResponse, ['partner_ids.1']);
        $this->assertSame($this->tenant->id, $otherCompany->tenant_id);
    }

    /**
     * @param  list<array<string, mixed>>|null  $lines
     * @return array<string, mixed>
     */
    private function payload(?array $lines = null): array
    {
        return [
            'partner_ids' => array_map(fn (Partner $partner): string => $partner->id, $this->suppliers),
            'validity_date' => '2026-07-31',
            'notes' => 'HTTP RFQ test',
            'lines' => $lines ?? [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5.0000',
                    'unit_price' => null,
                    'description' => 'Lait hydratant',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responsePayload(): array
    {
        return [
            'supplier_reference' => 'SUP-REF-1',
            'lead_time_days' => 3,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5.0000',
                    'unit_price' => '8.200',
                    'description' => 'Lait hydratant',
                ],
            ],
        ];
    }

    private function createRfqInCompany(Company $company, Partner $supplier, Product $product): Document
    {
        $documents = $this->app->make(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplier->id],
                lines: [
                    [
                        'product_id' => $product->id,
                        'quantity' => '5.0000',
                        'unit_price' => null,
                        'description' => 'Company-scoped RFQ line',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $company->tenant_id,
            $company->id,
            $company->currency,
        );

        $document = $documents->first();
        $this->assertInstanceOf(Document::class, $document);

        return $document;
    }

    private function recordResponse(Document $rfq, Product $product): Document
    {
        return $this->app->make(PurchaseQuoteRequestService::class)->recordResponse(
            $rfq->id,
            $rfq->tenant_id,
            $rfq->company_id,
            new UpdateRfqData(
                lines: [
                    [
                        'id' => $rfq->lines->first()?->id,
                        'product_id' => $product->id,
                        'quantity' => '5.0000',
                        'unit_price' => '8.200',
                        'description' => 'Recorded response line',
                    ],
                ],
                validityDate: null,
                supplierReference: 'SUP-REF-1',
                leadTimeDays: 3,
            ),
        );
    }

    /**
     * @return array{0: Company, 1: Product, 2: Partner}
     */
    private function otherCompanyFixture(): array
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'RFQ Other Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Other company product',
        ]);

        $supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Other company supplier',
            'type' => PartnerType::Supplier,
        ]);

        return [$company, $product, $supplier];
    }

    private function supplier(string $name): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'type' => PartnerType::Supplier,
        ]);
    }

    private function user(string $email, string $role): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'status' => UserStatus::Active,
        ]);
        $user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => $role,
        ]);

        return $user;
    }
}
