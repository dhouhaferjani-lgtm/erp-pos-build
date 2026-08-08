<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA V7 / T8 — the `stock_adjustments` HTTP surface.
 *
 * Covers the route/permission matrix, the two-leg `post_immediately` check, the
 * validation matrix (signed 4-dp, `not_in:0`, the derived reason↔sign rule,
 * prohibited `occurred_at`/`location_id`), cross-company 404, location scoping,
 * and that every typed refusal reaches the client as
 * `{error:{code,message,details}}` with `quantity_decimals` on the
 * quantity-bearing ones.
 */
final class StockAdjustmentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $manager;

    private Location $warehouse;

    private Location $annex;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Endpoint Tenant',
            'slug' => 'endpoint-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Endpoint Co',
            'legal_name' => 'Endpoint Co LLC',
            'tax_id' => 'TAX-EP',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = $this->user('manager@example.com', [
            'inventory.view',
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.post',
            'inventory.adjustments.cancel',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'EP-01',
            'name' => 'Endpoint Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->annex = Location::create([
            'company_id' => $this->company->id,
            'code' => 'EP-02',
            'name' => 'Endpoint Annex',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'EP-001',
            'name' => 'Endpoint Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => false,
        ]);

        $this->seedStock('20.0000');
    }

    // ------------------------------------------------- the happy paths

    public function test_a_draft_is_created_and_can_then_be_posted(): void
    {
        $create = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'note' => 'stocktake',
            'lines' => [$this->line('adjustment_positive', '2.0000', '20.0000')],
        ]);

        $create->assertStatus(201);
        $create->assertJsonPath('data.status', 'draft');
        $create->assertJsonPath('data.adjustment_number', null);
        $create->assertJsonPath('data.lines.0.delta_quantity', '2.0000');
        $this->assertSame(0, StockMovement::count());

        $id = (string) $create->json('data.id');

        $post = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/post");
        $post->assertOk();
        $post->assertJsonPath('data.status', 'posted');
        $this->assertMatchesRegularExpression('/^ADJ-\d{4}-\d{4}$/', (string) $post->json('data.adjustment_number'));
        $this->assertSame('22.0000', (string) $this->level()->quantity);
    }

    public function test_post_immediately_creates_and_posts_in_one_request(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'post_immediately' => true,
            'lines' => [$this->line('adjustment_negative', '-3.0000', '20.0000')],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'posted');
        $this->assertSame('17.0000', (string) $this->level()->quantity);
    }

    public function test_an_idempotent_replay_returns_200_with_the_existing_document(): void
    {
        $payload = [
            'location_id' => $this->warehouse->id,
            'idempotency_key' => 'EP-IDEM-1',
            'lines' => [$this->line('adjustment_positive', '2.0000', '20.0000')],
        ];

        $first = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', $payload);
        $first->assertStatus(201);

        $second = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', $payload);
        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, StockAdjustment::count());
    }

    public function test_the_index_and_show_endpoints_return_the_document(): void
    {
        $id = $this->createDraft();

        $index = $this->actingAs($this->manager)->getJson('/api/v1/stock-adjustments');
        $index->assertOk();
        $index->assertJsonPath('meta.total', 1);
        // The list omits lines.
        $this->assertSame([], $index->json('data.0.lines'));

        $show = $this->actingAs($this->manager)->getJson("/api/v1/stock-adjustments/{$id}");
        $show->assertOk();
        $show->assertJsonCount(1, 'data.lines');
    }

    public function test_cancel_and_correct_round_trip(): void
    {
        $id = $this->createDraft();
        $cancel = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/cancel", [
            'reason' => 'miscounted',
        ]);
        $cancel->assertOk();
        $cancel->assertJsonPath('data.status', 'cancelled');

        $posted = $this->createDraft();
        $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$posted}/post")->assertOk();

        $correct = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$posted}/correct");
        $correct->assertStatus(201);
        $correct->assertJsonPath('data.status', 'draft');
        $correct->assertJsonPath('data.corrects_adjustment_id', $posted);
        $correct->assertJsonPath('data.lines.0.reason_code', 'adjustment_negative');
    }

    // --------------------------------------------------------- the PATCH row

    public function test_patch_replaces_the_line_set_and_prohibits_location_and_occurred_at(): void
    {
        $id = $this->createDraft();

        $patch = $this->actingAs($this->manager)->patchJson("/api/v1/stock-adjustments/{$id}", [
            'note' => 're-anchored',
            'lines' => [$this->line('adjustment_positive', '7.0000', '25.0000')],
        ]);

        $patch->assertOk();
        $patch->assertJsonPath('data.note', 're-anchored');
        $patch->assertJsonCount(1, 'data.lines');
        $patch->assertJsonPath('data.lines.0.delta_quantity', '7.0000');
        // observed_before is WRITABLE — that is precisely what re-anchoring means.
        $patch->assertJsonPath('data.lines.0.observed_before', '25.0000');

        $this->assertValidationError(
            $this->actingAs($this->manager)->patchJson("/api/v1/stock-adjustments/{$id}", [
                'location_id' => $this->annex->id,
            ]),
            'location_id',
        );

        $this->assertValidationError(
            $this->actingAs($this->manager)->patchJson("/api/v1/stock-adjustments/{$id}", [
                'occurred_at' => now()->toIso8601String(),
            ]),
            'occurred_at',
        );
    }

    public function test_patching_a_posted_document_returns_invalid_adjustment_state(): void
    {
        $id = $this->createDraft();
        $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/post")->assertOk();

        $patch = $this->actingAs($this->manager)->patchJson("/api/v1/stock-adjustments/{$id}", [
            'lines' => [$this->line('adjustment_positive', '9.0000', '22.0000')],
        ]);

        $patch->assertStatus(422);
        $patch->assertJsonPath('error.code', 'INVALID_ADJUSTMENT_STATE');
        $patch->assertJsonPath('error.details.current_status', 'posted');
        $patch->assertJsonPath('error.details.attempted', 'update');
        $patch->assertJsonPath('error.details.allowed', []);
    }

    // ---------------------------------------------------- the validation matrix

    public function test_the_signed_four_decimal_contract(): void
    {
        // 4 dp accepted on BOTH signs — the leading `-?` is the half a copy-paste
        // from the unsigned quantity regexes would silently drop.
        $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [$this->line('adjustment_negative', '-1.2345', '20.0000')],
        ])->assertStatus(201);

        // 5 dp refused.
        $this->assertValidationError(
            $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                'location_id' => $this->warehouse->id,
                'lines' => [$this->line('adjustment_positive', '1.23456', '20.0000')],
            ]),
            'lines.0.delta_quantity',
        );

        // observed_before must accept a NEGATIVE value: stock_levels.quantity has
        // no non-negative CHECK and POS paths drive it below zero.
        $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [$this->line('adjustment_positive', '1.0000', '-4.0000')],
        ])->assertStatus(201);
    }

    public function test_a_zero_delta_is_refused(): void
    {
        $this->assertValidationError(
            $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                'location_id' => $this->warehouse->id,
                'lines' => [$this->line('adjustment_positive', '0', '20.0000')],
            ]),
            'lines.0.delta_quantity',
        );
    }

    public function test_every_reason_accepts_its_own_sign_and_refuses_the_other(): void
    {
        foreach ([
            'adjustment_positive' => ['1.0000', '-1.0000'],
            'adjustment_negative' => ['-1.0000', '1.0000'],
            'damage' => ['-1.0000', '1.0000'],
            'write_off' => ['-1.0000', '1.0000'],
        ] as $reason => [$ok, $bad]) {
            $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                'location_id' => $this->warehouse->id,
                'lines' => [$this->line($reason, $ok, '20.0000')],
            ])->assertStatus(201, "{$reason} must accept {$ok}");

            $this->assertValidationError(
                $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                    'location_id' => $this->warehouse->id,
                    'lines' => [$this->line($reason, $bad, '20.0000')],
                ]),
                'lines.0.delta_quantity',
            );
        }
    }

    public function test_the_retired_opening_balance_reason_is_refused(): void
    {
        $this->assertValidationError(
            $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                'location_id' => $this->warehouse->id,
                'lines' => [$this->line('opening_balance', '1.0000', '20.0000')],
            ]),
            'lines.0.reason_code',
        );

        // Expiry and consumption are excluded too (D7a / D7b).
        foreach (['expiry', 'consumption', 'count_correction'] as $excluded) {
            $this->assertValidationError(
                $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                    'location_id' => $this->warehouse->id,
                    'lines' => [$this->line($excluded, '-1.0000', '20.0000')],
                ]),
                'lines.0.reason_code',
            );
        }
    }

    public function test_occurred_at_is_prohibited_on_create(): void
    {
        $this->assertValidationError(
            $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                'location_id' => $this->warehouse->id,
                'occurred_at' => now()->subMonth()->toIso8601String(),
                'lines' => [$this->line('adjustment_positive', '1.0000', '20.0000')],
            ]),
            'occurred_at',
        );
    }

    public function test_duplicate_lines_are_refused_before_reaching_the_database(): void
    {
        $this->assertValidationError(
            $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
                'location_id' => $this->warehouse->id,
                'lines' => [
                    $this->line('adjustment_positive', '1.0000', '20.0000'),
                    $this->line('adjustment_positive', '2.0000', '20.0000'),
                ],
            ]),
            'lines.1.product_id',
        );
    }

    // ------------------------------------------------------------ permissions

    public function test_the_route_permission_matrix(): void
    {
        $id = $this->createDraft();
        $viewer = $this->user('viewer@example.com', ['inventory.view', 'inventory.adjustments.view']);

        $this->actingAs($viewer)->getJson('/api/v1/stock-adjustments')->assertOk();
        $this->actingAs($viewer)->getJson("/api/v1/stock-adjustments/{$id}")->assertOk();

        $this->actingAs($viewer)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [$this->line('adjustment_positive', '1.0000', '20.0000')],
        ])->assertForbidden();

        $this->actingAs($viewer)->patchJson("/api/v1/stock-adjustments/{$id}", ['note' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/stock-adjustments/{$id}/post")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/stock-adjustments/{$id}/cancel")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/stock-adjustments/{$id}/correct")->assertForbidden();

        $noView = $this->user('no-view@example.com', ['inventory.view']);
        $this->actingAs($noView)->getJson('/api/v1/stock-adjustments')->assertForbidden();
    }

    public function test_post_immediately_and_the_overrides_require_the_post_permission(): void
    {
        $author = $this->user('author@example.com', [
            'inventory.view', 'inventory.adjustments.view', 'inventory.adjustments.create',
        ]);

        // create alone gets a DRAFT...
        $this->actingAs($author)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [$this->line('adjustment_positive', '1.0000', '20.0000')],
        ])->assertStatus(201);

        // ...but not a POST.
        foreach ([
            ['post_immediately' => true],
            ['acknowledge_stale' => true],
            ['ignore_reservations' => true],
        ] as $flag) {
            $response = $this->actingAs($author)->postJson('/api/v1/stock-adjustments', [
                'location_id' => $this->warehouse->id,
                'lines' => [$this->line('adjustment_positive', '1.0000', '20.0000')],
                ...$flag,
            ]);

            $response->assertForbidden();
            $response->assertJsonPath('error.code', 'POST_PERMISSION_REQUIRED');
        }
    }

    // ------------------------------------------------------------- visibility

    public function test_a_document_from_another_company_is_a_404_not_a_403(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Endpoint Co',
            'legal_name' => 'Other Endpoint Co LLC',
            'tax_id' => 'TAX-EP-2',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'code' => 'EP-OT',
            'name' => 'Other Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);
        $foreign = StockAdjustment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'status' => StockAdjustmentStatus::Draft,
            'location_id' => $otherLocation->id,
            'occurred_at' => now(),
            'created_by_user_id' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->getJson("/api/v1/stock-adjustments/{$foreign->id}")
            ->assertNotFound();

        $this->actingAs($this->manager)->getJson('/api/v1/stock-adjustments')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_a_malformed_id_is_a_404(): void
    {
        $this->actingAs($this->manager)
            ->getJson('/api/v1/stock-adjustments/not-a-uuid')
            ->assertNotFound();
    }

    public function test_a_location_the_caller_cannot_act_on_is_refused_with_403(): void
    {
        $restricted = $this->user('restricted@example.com', [
            'inventory.view', 'inventory.adjustments.view', 'inventory.adjustments.create', 'inventory.adjustments.post',
        ], allowedLocationIds: [$this->annex->id]);

        $response = $this->actingAs($restricted)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [$this->line('adjustment_positive', '1.0000', '20.0000')],
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'LOCATION_ACCESS_DENIED');
        $response->assertJsonPath('error.details.location_id', $this->warehouse->id);
    }

    // --------------------------------------------------------- typed refusals

    public function test_a_staleness_refusal_carries_the_line_key_and_the_unit_precision(): void
    {
        $id = $this->createDraft();
        $this->level()->update(['quantity' => '25.0000']);

        $response = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/post");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'STOCK_MOVED_SINCE_AUTHORING');
        $response->assertJsonPath('error.details.lines.0.product_id', $this->product->id);
        $response->assertJsonPath('error.details.lines.0.observed_before', '20.0000');
        $response->assertJsonPath('error.details.lines.0.quantity_before', '25.0000');
        $response->assertJsonPath('error.details.lines.0.batch_uuid', null);
        $this->assertIsInt($response->json('error.details.lines.0.quantity_decimals'));

        // Acknowledging it clears the refusal.
        $this->actingAs($this->manager)
            ->postJson("/api/v1/stock-adjustments/{$id}/post", ['acknowledge_stale' => true])
            ->assertOk();
    }

    public function test_an_immediate_post_refusal_persists_nothing(): void
    {
        $this->level()->update(['quantity' => '25.0000']);

        $response = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'post_immediately' => true,
            'lines' => [$this->line('adjustment_positive', '2.0000', '20.0000')],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'STOCK_MOVED_SINCE_AUTHORING');
        // line_id is NULL because the draft was rolled back with the transaction
        // — the frontend keys recovery on (product_id, variant_id, batch_uuid).
        $response->assertJsonPath('error.details.lines.0.line_id', null);
        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_an_availability_refusal_is_marked_overridable(): void
    {
        $this->level()->update(['quantity' => '5.0000', 'reserved' => '3.0000']);

        $response = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'post_immediately' => true,
            'lines' => [$this->line('adjustment_negative', '-4.0000', '5.0000')],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ADJUSTMENT_EXCEEDS_AVAILABLE');
        $response->assertJsonPath('error.details.available', '2.0000');
        $response->assertJsonPath('error.details.reserved', '3.0000');
        $response->assertJsonPath('error.details.overridable', true);
        $this->assertIsInt($response->json('error.details.quantity_decimals'));

        $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'post_immediately' => true,
            'ignore_reservations' => true,
            'lines' => [$this->line('adjustment_negative', '-4.0000', '5.0000')],
        ])->assertStatus(201);
    }

    public function test_the_batch_refusals_reach_the_client_with_their_codes(): void
    {
        $tracked = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'EP-LOT',
            'name' => 'Endpoint Lot Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => true,
        ]);
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $tracked->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $useWriteOff = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $tracked->id,
                'reason_code' => 'damage',
                'delta_quantity' => '-1.0000',
                'observed_before' => '10.0000',
            ]],
        ]);
        $useWriteOff->assertStatus(422);
        $useWriteOff->assertJsonPath('error.code', 'USE_BATCH_WRITE_OFF');
        $useWriteOff->assertJsonPath('error.details.reason_code', 'damage');

        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $tracked->id,
            'batch_number' => 'EP-LOT-A',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);
        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->warehouse->id,
            'quantity' => '10.0000',
            'reserved_quantity' => '0.0000',
        ]);

        $missingLot = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $tracked->id,
                'reason_code' => 'adjustment_negative',
                'delta_quantity' => '-1.0000',
                'observed_before' => '10.0000',
            ]],
        ]);
        $missingLot->assertStatus(422);
        $missingLot->assertJsonPath('error.code', 'BATCH_REQUIRED_FOR_LINE');
        $missingLot->assertJsonPath('error.details.product_id', $tracked->id);

        $wrongLot = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [[
                'product_id' => $tracked->id,
                'batch_uuid' => (string) Str::uuid(),
                'reason_code' => 'adjustment_negative',
                'delta_quantity' => '-1.0000',
                'observed_before' => '10.0000',
            ]],
        ]);
        $wrongLot->assertStatus(422);
        $wrongLot->assertJsonPath('error.code', 'BATCH_NOT_APPLICABLE');
    }

    public function test_correction_refusals_reach_the_client_with_their_codes(): void
    {
        $id = $this->createDraft();
        $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/post")->assertOk();

        $contra = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/correct");
        $contra->assertStatus(201);
        $contraId = (string) $contra->json('data.id');

        $again = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/correct");
        $again->assertStatus(422);
        $again->assertJsonPath('error.code', 'ADJUSTMENT_ALREADY_CORRECTED');
        $again->assertJsonPath('error.details.correction_id', $contraId);

        $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$contraId}/post")->assertOk();

        $chained = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$contraId}/correct");
        $chained->assertStatus(422);
        $chained->assertJsonPath('error.code', 'CANNOT_CORRECT_A_CORRECTION');
        $chained->assertJsonPath('error.details.corrects_adjustment_id', $id);
    }

    public function test_a_second_post_returns_invalid_adjustment_state(): void
    {
        $id = $this->createDraft();
        $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/post")->assertOk();

        $second = $this->actingAs($this->manager)->postJson("/api/v1/stock-adjustments/{$id}/post");
        $second->assertStatus(422);
        $second->assertJsonPath('error.code', 'INVALID_ADJUSTMENT_STATE');
        $second->assertJsonPath('error.details.attempted', 'post');
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param  list<string>  $permissions
     * @param  list<string>|null  $allowedLocationIds
     */
    private function user(string $email, array $permissions, ?array $allowedLocationIds = null): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->givePermissionTo($permissions);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
            // NULL = unrestricted; a list scopes the membership to those
            // locations (the StockTransferLocationScopeTest convention).
            'allowed_location_ids' => $allowedLocationIds,
            'status' => 'active',
        ]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function line(string $reason, string $delta, string $observedBefore): array
    {
        return [
            'product_id' => $this->product->id,
            'reason_code' => $reason,
            'delta_quantity' => $delta,
            'observed_before' => $observedBefore,
        ];
    }

    private function createDraft(): string
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/stock-adjustments', [
            'location_id' => $this->warehouse->id,
            'lines' => [$this->line('adjustment_positive', '2.0000', '20.0000')],
        ]);
        $response->assertStatus(201);

        return (string) $response->json('data.id');
    }

    private function seedStock(string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function level(): StockLevel
    {
        return StockLevel::query()
            ->where('product_id', $this->product->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail();
    }

    private function assertValidationError(TestResponse $response, string $key): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey(
            $key,
            (array) $response->json('error.errors'),
            "Expected a validation error on {$key}; got ".json_encode($response->json('error.errors')),
        );
    }
}
