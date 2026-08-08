<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Uom\Domain\Entities\UnitCategory;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class EntryExitNoteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Entry Exit Tenant',
            'slug' => 'entry-exit-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entry Exit Company',
            'legal_name' => 'Entry Exit Company SARL',
            'tax_id' => 'EE-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entry Exit User',
            'email' => 'entry-exit@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'EE-WH',
            'name' => 'Entry Exit Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Entry Exit Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'EE-001',
            'name' => 'Entry Exit Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
        ]);
    }

    public function test_entry_exit_notes_group_movements_by_polymorphic_reference(): void
    {
        $purchaseOrder = $this->document(DocumentType::PurchaseOrder, 'PO-EE-001');
        $inboundA = $this->movement([
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::GoodsReceipt,
            'quantity' => '3.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '3.0000',
            'reference' => 'GRN-EE-001',
            'reference_type' => 'goods_receipt',
            'reference_id' => $purchaseOrder->id,
        ]);
        $inboundB = $this->movement([
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::GoodsReceipt,
            'quantity' => '2.0000',
            'quantity_before' => '3.0000',
            'quantity_after' => '5.0000',
            'reference' => 'GRN-EE-001',
            'reference_type' => 'goods_receipt',
            'reference_id' => $purchaseOrder->id,
        ]);
        $deliveryNote = $this->document(DocumentType::DeliveryNote, 'DN-EE-001');
        $this->movement([
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::Delivery,
            'quantity' => '1.0000',
            'quantity_before' => '5.0000',
            'quantity_after' => '4.0000',
            'reference' => 'DN-EE-001',
            'reference_type' => Document::class,
            'reference_id' => $deliveryNote->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?direction=in&source_type=goods_receipt&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.direction', 'in');
        $response->assertJsonPath('data.0.source_type', 'goods_receipt');
        $response->assertJsonPath('data.0.source_id', $purchaseOrder->id);
        $response->assertJsonPath('data.0.source_label', 'GRN-EE-001');
        $response->assertJsonPath('data.0.location.id', $this->warehouse->id);
        $response->assertJsonPath('data.0.actor.id', $this->user->id);
        $response->assertJsonCount(2, 'data.0.lines');
        $response->assertJsonPath('data.0.lines.0.movement_id', $inboundA->id);
        $response->assertJsonPath('data.0.lines.0.product.name', 'Entry Exit Product');
        $response->assertJsonPath('data.0.lines.1.movement_id', $inboundB->id);
    }

    public function test_entry_exit_note_lines_expose_product_unit_quantity_decimals(): void
    {
        $category = UnitCategory::factory()->create([
            'tenant_id' => null,
            'code' => 'entry-exit-weight',
            'name' => 'Entry Exit Weight',
            'is_system' => true,
            'is_active' => true,
        ]);
        $unit = Unit::factory()->create([
            'tenant_id' => null,
            'category_id' => $category->id,
            'code' => 'entry-exit-kg',
            'name' => 'Entry Exit Kilogram',
            'symbol' => 'kg',
            'decimal_places' => 3,
            'is_system' => true,
            'is_active' => true,
        ]);
        $this->product->update(['unit_id' => $unit->id]);
        $this->movement(['quantity' => '2.5000']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?per_page=10');

        $response->assertOk();
        $response->assertJsonPath('data.0.lines.0.quantity', '2.5000');
        $response->assertJsonPath('data.0.lines.0.quantity_decimals', 3);
    }

    public function test_entry_exit_notes_are_tenant_and_company_scoped(): void
    {
        $this->movement([
            'movement_type' => MovementType::Receipt,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
            'reference' => 'VISIBLE',
            'reference_type' => 'adjustment_batch',
            'reference_id' => (string) Str::uuid(),
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Entry Exit Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'code' => 'OTHER-EE',
            'name' => 'Other Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
        ]);
        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'product_id' => $this->product->id,
            'location_id' => $otherLocation->id,
            'movement_type' => MovementType::Receipt,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
            'reference' => 'HIDDEN',
            'reference_type' => 'adjustment_batch',
            'reference_id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?per_page=10');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.source_label', 'VISIBLE');
    }

    public function test_entry_exit_notes_default_to_last_30_days_and_report_window_meta(): void
    {
        $this->movement([
            'reference' => 'RECENT',
            'reference_type' => 'adjustment_batch',
            'reference_id' => (string) Str::uuid(),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $this->movement([
            'reference' => 'OLD',
            'reference_type' => 'adjustment_batch',
            'reference_id' => (string) Str::uuid(),
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?per_page=10');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('meta.default_date_window_days', 30);
        $response->assertJsonPath('data.0.source_label', 'RECENT');
    }

    public function test_entry_exit_notes_project_transfer_in_and_out_and_delivery_out(): void
    {
        $destination = Location::create([
            'company_id' => $this->company->id,
            'code' => 'EE-DEST',
            'name' => 'Entry Exit Destination',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        // Both legs share ONE transfer id so the report groups them together.
        $transferId = (string) Str::uuid();

        $this->movement([
            'movement_type' => MovementType::TransferOut,
            'quantity' => '2.0000',
            'quantity_before' => '5.0000',
            'quantity_after' => '3.0000',
            'reference' => 'TRF-EE-001',
            'reference_type' => 'stock_transfer',
            'reference_id' => $transferId,
        ]);
        $this->movement([
            'location_id' => $destination->id,
            'movement_type' => MovementType::TransferIn,
            'quantity' => '2.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '2.0000',
            'reference' => 'TRF-EE-001',
            'reference_type' => 'stock_transfer',
            'reference_id' => $transferId,
        ]);
        $deliveryNote = $this->document(DocumentType::DeliveryNote, 'DN-EE-OUT');
        $this->movement([
            'movement_type' => MovementType::Issue,
            'reason' => MovementReason::Delivery,
            'quantity' => '1.0000',
            'quantity_before' => '3.0000',
            'quantity_after' => '2.0000',
            'reference' => 'DN-EE-OUT',
            'reference_type' => Document::class,
            'reference_id' => $deliveryNote->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?per_page=10');

        $response->assertOk();
        $notes = collect($response->json('data'));

        $this->assertTrue($notes->contains(fn (array $note): bool => $note['source_type'] === 'stock_transfer' && $note['direction'] === 'in'));
        $this->assertTrue($notes->contains(fn (array $note): bool => $note['source_type'] === 'stock_transfer' && $note['direction'] === 'out'));
        $this->assertTrue($notes->contains(fn (array $note): bool => $note['source_type'] === 'delivery_note' && $note['direction'] === 'out'));
    }

    public function test_entry_exit_notes_paginate_the_sql_bounded_window(): void
    {
        foreach (range(1, 3) as $index) {
            $this->movement([
                'reference' => "ADJ-{$index}",
                'reference_type' => 'adjustment_batch',
                'reference_id' => (string) Str::uuid(),
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?per_page=2&page=2');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.current_page', 2);
        $response->assertJsonCount(1, 'data');
    }

    public function test_entry_exit_notes_batch_resolve_document_source_types(): void
    {
        $firstDelivery = $this->document(DocumentType::DeliveryNote, 'DN-EE-BATCH-1');
        $secondDelivery = $this->document(DocumentType::DeliveryNote, 'DN-EE-BATCH-2');
        $this->movement([
            'movement_type' => MovementType::Issue,
            'quantity_before' => '5.0000',
            'quantity_after' => '4.0000',
            'reference' => 'DN-EE-BATCH-1',
            'reference_type' => Document::class,
            'reference_id' => $firstDelivery->id,
        ]);
        $this->movement([
            'movement_type' => MovementType::Issue,
            'quantity_before' => '4.0000',
            'quantity_after' => '3.0000',
            'reference' => 'DN-EE-BATCH-2',
            'reference_type' => Document::class,
            'reference_id' => $secondDelivery->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?source_type=delivery_note&per_page=10');

        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);

        $documentSelects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'select')
                && str_contains(strtolower((string) $query['query']), 'from "documents"'))
            ->count();

        $this->assertLessThanOrEqual(1, $documentSelects);
    }

    /**
     * DPA S0: movements posted by the counting finalize now carry document
     * linkage, so they leave the catch-all `manual` bucket and group per
     * counting. `source_type` MUST be the enum's canonical code — the FE renders
     * it through `entryExitNotes.sourceTypes.<code>`, so a raw class name here
     * would reach users untranslated (rule 11).
     */
    public function test_entry_exit_notes_expose_counting_sourced_movements_with_a_translatable_source_type(): void
    {
        $countingId = (string) Str::uuid();

        $this->movement([
            'movement_type' => MovementType::Adjustment,
            'reason' => MovementReason::CountCorrection,
            'quantity' => '2.0000',
            'quantity_before' => '3.0000',
            'quantity_after' => '5.0000',
            'reference' => 'COUNTING:CNT-EE-001',
            'reference_type' => StockMovementReferenceType::InventoryCounting->value,
            'reference_id' => $countingId,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?per_page=10');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.source_type', 'inventory_counting');
        $response->assertJsonPath('data.0.source_id', $countingId);
        $response->assertJsonPath('data.0.source_label', 'COUNTING:CNT-EE-001');

        // The source_type filter must round-trip the same code.
        $filtered = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?source_type=inventory_counting&per_page=10');

        $filtered->assertOk();
        $filtered->assertJsonPath('meta.total', 1);

        // Guard the i18n contract: every source_type the API emits needs a key in
        // BOTH locales, or the FE prints the raw code to the user.
        $sourceType = (string) $response->json('data.0.source_type');
        foreach (['en', 'fr'] as $locale) {
            $path = base_path("../web/src/locales/{$locale}/inventory.json");
            $this->assertFileExists($path);
            /** @var array<string, mixed> $messages */
            $messages = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($messages['entryExitNotes'] ?? null);
            $this->assertIsArray($messages['entryExitNotes']['sourceTypes'] ?? null);
            $this->assertArrayHasKey(
                $sourceType,
                $messages['entryExitNotes']['sourceTypes'],
                "Missing {$locale} translation for entryExitNotes.sourceTypes.{$sourceType}",
            );
        }
    }

    /**
     * DPA V7 / T3: `stock_adjustments` movements group per adjustment document
     * and report the canonical, translatable `stock_adjustment` code.
     *
     * Also pins that the `documents` leftJoin does NOT fire for this reference
     * type — the join is scoped to `[Document::class, 'Document']`, so
     * `source_id` must be the adjustment's own uuid, not a document id.
     */
    public function test_entry_exit_notes_expose_stock_adjustment_sourced_movements(): void
    {
        $adjustmentId = (string) Str::uuid();

        $this->movement([
            'movement_type' => MovementType::Adjustment,
            'reason' => MovementReason::AdjustmentNegative,
            'quantity' => '-2.0000',
            'quantity_before' => '5.0000',
            'quantity_after' => '3.0000',
            'reference' => 'ADJ-2026-0001',
            'reference_type' => StockMovementReferenceType::StockAdjustment->value,
            'reference_id' => $adjustmentId,
        ]);
        $this->movement([
            'movement_type' => MovementType::Adjustment,
            'reason' => MovementReason::AdjustmentPositive,
            'quantity' => '4.0000',
            'quantity_before' => '1.0000',
            'quantity_after' => '5.0000',
            'reference' => 'ADJ-2026-0001',
            'reference_type' => StockMovementReferenceType::StockAdjustment->value,
            'reference_id' => $adjustmentId,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?per_page=10');

        $response->assertOk();
        // Two movements, but mixed direction — the grouping key is
        // (reference_type, reference_id, location, direction), so an in-note and
        // an out-note. Both must carry the canonical code.
        $sourceTypes = collect($response->json('data'))->pluck('source_type')->unique()->values()->all();
        $this->assertSame(['stock_adjustment'], $sourceTypes);
        $this->assertSame(
            [$adjustmentId],
            collect($response->json('data'))->pluck('source_id')->unique()->values()->all(),
            'source_id must be the adjustment uuid — the documents leftJoin must not fire.'
        );

        $filtered = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes?source_type=stock_adjustment&per_page=10');

        $filtered->assertOk();
        $this->assertGreaterThan(0, (int) $filtered->json('meta.total'));
        $this->assertSame(
            ['stock_adjustment'],
            collect($filtered->json('data'))->pluck('source_type')->unique()->values()->all(),
        );
    }

    /**
     * DPA V7 / T3 (D16): S0's single-key i18n assertion becomes TOTAL over the
     * enum. `sourceType()` now returns the canonical code for EVERY case except
     * `Document`, so every one of those codes must have an en+fr key or a raw
     * string reaches the user (rule 11).
     */
    public function test_every_canonical_source_type_has_an_en_and_fr_translation(): void
    {
        $codes = collect(StockMovementReferenceType::cases())
            ->reject(fn (StockMovementReferenceType $case): bool => $case === StockMovementReferenceType::Document)
            ->map(fn (StockMovementReferenceType $case): string => $case->value)
            ->values();

        $this->assertNotEmpty($codes, 'The enum must carry at least one non-Document case.');

        foreach (['en', 'fr'] as $locale) {
            $path = base_path("../web/src/locales/{$locale}/inventory.json");
            $this->assertFileExists($path);
            /** @var array<string, mixed> $messages */
            $messages = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($messages['entryExitNotes'] ?? null);
            $this->assertIsArray($messages['entryExitNotes']['sourceTypes'] ?? null);

            foreach ($codes as $code) {
                $this->assertArrayHasKey(
                    $code,
                    $messages['entryExitNotes']['sourceTypes'],
                    "Missing {$locale} translation for entryExitNotes.sourceTypes.{$code}",
                );
            }
        }
    }

    public function test_entry_exit_notes_require_inventory_view_permission(): void
    {
        $viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entry Exit Denied User',
            'email' => 'entry-exit-denied@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/entry-exit-notes');

        $response->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function movement(array $overrides): StockMovement
    {
        $timestamps = array_intersect_key($overrides, array_flip(['created_at', 'updated_at']));
        $movement = StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->warehouse->id,
            'movement_type' => MovementType::Receipt,
            'reason' => null,
            'quantity' => '1.0000',
            'quantity_before' => '0.0000',
            'quantity_after' => '1.0000',
            'reference' => 'REF',
            'reference_type' => null,
            'reference_id' => null,
            'user_id' => $this->user->id,
            ...array_diff_key($overrides, $timestamps),
        ]);

        if ($timestamps !== []) {
            $movement->forceFill($timestamps)->save();
        }

        return $movement;
    }

    private function document(DocumentType $type, string $number): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => $type,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
        ]);
    }
}
