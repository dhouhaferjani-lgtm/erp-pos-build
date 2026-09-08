<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use App\Modules\Inventory\Domain\Events\StockMovementRecordedV2;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * QA-BUG-09 / DEV-QA-077 — traceability of a count movement.
 *
 * The manager finalized `CNT-2026-0010` and the resulting stock movement read
 * `COUNT_REPLAY` with no way back to the counting: the free-text label was a
 * compile-time constant, and the read endpoint refused to translate the
 * (correctly persisted) `reference_id` FK into a source-document link because
 * it only resolved `Document` reference types.
 *
 * Two contracts are pinned here:
 *  1. LABEL — a replay count movement carries the BARE counting number
 *     (`CNT-2026-0010`), matching how goods receipts stamp
 *     `$purchaseOrder->document_number`. The `COUNT_REPLAY` constant survives
 *     only as the fallback for a caller that supplies no label (pinned in
 *     StockMovementDocumentLinkageTest).
 *  2. LINKAGE — `GET /api/v1/stock-movements` exposes the raw `reference_id`
 *     and resolves `source_document_id` / `source_document_type` for
 *     `inventory_counting` rows, so the operator surface can link the label to
 *     `/inventory/counting/{id}`.
 *
 * House rule 20: NO CompanyContext is bound in setUp(). The finalize listener
 * is queued and runs with no company context; binding one in setUp would mask
 * that worker reality. The HTTP cases bind it explicitly, as the middleware
 * does for a real request.
 */
final class CountingMovementReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const COUNTING_NUMBER = 'CNT-2026-0010';

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'QA09 Tenant',
            'slug' => 'qa09-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = $this->makeCompany('QA09 Alpha');
        $this->user = $this->makeUser($this->company, 'alpha');
        $this->location = $this->makeLocation($this->company, 'A-MAIN');
        $this->product = $this->makeProduct($this->company, 'ALPHA-P1');
    }

    // ---------------------------------------------------------------- fixtures

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'QA09-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function makeUser(Company $company, string $slug): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'QA09 '.$slug,
            'email' => $slug.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $user->givePermissionTo('inventory.view');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        return $user;
    }

    private function makeLocation(Company $company, string $code, bool $onboarding = false): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'code' => $code.'-'.uniqid(),
            'name' => $code,
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => $onboarding,
        ]);
    }

    private function makeProduct(Company $company, string $sku): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'sku' => substr($sku, 0, 6).'-'.uniqid(),
            'name' => $sku,
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '8.500000',
        ]);
    }

    /** @param numeric-string $quantity */
    private function setOnHand(Company $company, Product $product, Location $location, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function makeCounting(Company $company, Location $location, string $number): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $location->id],
            'counting_number' => $number,
            'status' => CountingStatus::Finalized,
            'ambiguity_window_minutes' => 15,
            'created_by_user_id' => $this->user->id,
        ]);
    }

    /**
     * @param  numeric-string  $finalQty
     * @param  numeric-string  $theoretical
     */
    private function makeItem(
        InventoryCounting $counting,
        Product $product,
        Location $location,
        string $finalQty,
        string $theoretical,
        CarbonImmutable $asOf,
    ): InventoryCountingItem {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $product->id,
            'location_id' => $location->id,
            'theoretical_qty' => $theoretical,
            'count_1_qty' => $finalQty,
            'count_2_qty' => $finalQty,
            'final_qty' => $finalQty,
            'final_qty_as_of' => $asOf,
            'resolution_method' => ItemResolutionMethod::AutoCountersAgree,
        ]);
    }

    private function fire(InventoryCounting $counting, Company $company, Location $location, User $user): void
    {
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $company->id,
            locationId: $location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: $counting->items()->count(),
            totalVariance: '0.0000',
            completedBy: $user->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    private function movementFor(Product $product): StockMovement
    {
        $movement = StockMovement::query()->where('product_id', $product->id)->first();
        self::assertNotNull($movement, 'Expected a count movement for product '.$product->id);

        return $movement;
    }

    // ------------------------------------------------------------------- cases

    /**
     * (1) The replay count-correction branch — the exact row the manager saw.
     */
    public function test_a_replay_count_correction_movement_carries_the_counting_number(): void
    {
        $asOf = CarbonImmutable::now()->subHours(2);
        $this->setOnHand($this->company, $this->product, $this->location, '70.0000');

        $counting = $this->makeCounting($this->company, $this->location, self::COUNTING_NUMBER);
        $this->makeItem($counting, $this->product, $this->location, '68.0000', '70.0000', $asOf);

        $this->fire($counting, $this->company, $this->location, $this->user);

        $movement = $this->movementFor($this->product);

        self::assertSame(MovementReason::CountCorrection->value, $movement->reason?->value);
        self::assertSame(self::COUNTING_NUMBER, $movement->reference);
        self::assertSame(StockMovementReferenceType::InventoryCounting->value, $movement->reference_type);
        self::assertSame($counting->id, $movement->reference_id);
    }

    /**
     * (2) The onboarding first-count OPENING branch (postCountOpening) — the
     * other writer of the constant.
     */
    public function test_an_onboarding_first_count_opening_movement_carries_the_counting_number(): void
    {
        $asOf = CarbonImmutable::now()->subHours(2);
        $onboardingLocation = $this->makeLocation($this->company, 'A-ONB', onboarding: true);
        $product = $this->makeProduct($this->company, 'ALPHA-ONB');

        $counting = $this->makeCounting($this->company, $onboardingLocation, 'CNT-2026-0011');
        $this->makeItem($counting, $product, $onboardingLocation, '12.0000', '0.0000', $asOf);

        $this->fire($counting, $this->company, $onboardingLocation, $this->user);

        $movement = $this->movementFor($product);

        self::assertSame(MovementType::Opening->value, $movement->movement_type->value);
        self::assertSame(MovementReason::OpeningBalance->value, $movement->reason?->value);
        self::assertSame('CNT-2026-0011', $movement->reference);
        self::assertSame(StockMovementReferenceType::InventoryCounting->value, $movement->reference_type);
        self::assertSame($counting->id, $movement->reference_id);
    }

    /**
     * (3) The read endpoint exposes the raw FK AND resolves the counting as the
     * movement's source document.
     */
    public function test_the_movement_list_exposes_reference_id_and_the_counting_source_document(): void
    {
        $asOf = CarbonImmutable::now()->subHours(2);
        $this->setOnHand($this->company, $this->product, $this->location, '70.0000');

        $counting = $this->makeCounting($this->company, $this->location, self::COUNTING_NUMBER);
        $this->makeItem($counting, $this->product, $this->location, '68.0000', '70.0000', $asOf);

        $this->fire($counting, $this->company, $this->location, $this->user);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/stock-movements?product_id='.$this->product->id.'&location_id='.$this->location->id
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.reference', self::COUNTING_NUMBER);
        $response->assertJsonPath('data.0.reference_type', StockMovementReferenceType::InventoryCounting->value);
        $response->assertJsonPath('data.0.reference_id', $counting->id);
        $response->assertJsonPath('data.0.source_document_id', $counting->id);
        $response->assertJsonPath('data.0.source_document_type', 'inventory_counting');
    }

    /**
     * (4) SECOND COMPANY, negative: company B's operator sees B's movements
     * only, and nothing of company A's counting leaks into the payload — not
     * the id, not the number.
     */
    public function test_a_second_company_never_sees_the_first_companys_counting_linkage(): void
    {
        $asOf = CarbonImmutable::now()->subHours(2);

        // Company A finalizes CNT-2026-0010.
        $this->setOnHand($this->company, $this->product, $this->location, '70.0000');
        $countingA = $this->makeCounting($this->company, $this->location, self::COUNTING_NUMBER);
        $this->makeItem($countingA, $this->product, $this->location, '68.0000', '70.0000', $asOf);
        $this->fire($countingA, $this->company, $this->location, $this->user);

        // Company B — same tenant, its own operator, its own counting.
        $companyB = $this->makeCompany('QA09 Beta');
        $userB = $this->makeUser($companyB, 'beta');
        $locationB = $this->makeLocation($companyB, 'B-MAIN');
        $productB = $this->makeProduct($companyB, 'BETA-P1');
        $this->setOnHand($companyB, $productB, $locationB, '30.0000');
        $countingB = $this->makeCounting($companyB, $locationB, 'CNT-2026-B001');
        $this->makeItem($countingB, $productB, $locationB, '28.0000', '30.0000', $asOf);
        $this->fire($countingB, $companyB, $locationB, $userB);

        app(CompanyContext::class)->setCompanyId($companyB->id);

        $response = $this->actingAs($userB)->getJson('/api/v1/stock-movements');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.reference', 'CNT-2026-B001');
        $response->assertJsonPath('data.0.source_document_id', $countingB->id);

        $payload = $response->getContent();
        self::assertIsString($payload);
        self::assertStringNotContainsString($countingA->id, $payload);
        self::assertStringNotContainsString(self::COUNTING_NUMBER, $payload);
        self::assertStringNotContainsString($this->product->id, $payload);
    }

    /**
     * (5) SECOND LOCATION inside one company: two countings, two movements, two
     * DISTINCT references — the label is per-counting, not per-company.
     */
    public function test_two_countings_at_two_locations_produce_distinct_references(): void
    {
        $asOf = CarbonImmutable::now()->subHours(2);

        $secondLocation = $this->makeLocation($this->company, 'A-ANNEX');
        $secondProduct = $this->makeProduct($this->company, 'ALPHA-P2');

        $this->setOnHand($this->company, $this->product, $this->location, '70.0000');
        $this->setOnHand($this->company, $secondProduct, $secondLocation, '40.0000');

        $countingMain = $this->makeCounting($this->company, $this->location, self::COUNTING_NUMBER);
        $this->makeItem($countingMain, $this->product, $this->location, '68.0000', '70.0000', $asOf);

        $countingAnnex = $this->makeCounting($this->company, $secondLocation, 'CNT-2026-0012');
        $this->makeItem($countingAnnex, $secondProduct, $secondLocation, '41.0000', '40.0000', $asOf);

        $this->fire($countingMain, $this->company, $this->location, $this->user);
        $this->fire($countingAnnex, $this->company, $secondLocation, $this->user);

        $main = $this->movementFor($this->product);
        $annex = $this->movementFor($secondProduct);

        self::assertSame(self::COUNTING_NUMBER, $main->reference);
        self::assertSame($countingMain->id, $main->reference_id);
        self::assertSame('CNT-2026-0012', $annex->reference);
        self::assertSame($countingAnnex->id, $annex->reference_id);
        self::assertNotSame($main->reference, $annex->reference);
    }

    /**
     * (6) Re-dispatching the finalize listener (the ShouldQueue retry) adds no
     * movement and does not rewrite the reference. The partial unique index on
     * (reference_id, product_id, location_id) WHERE reference_type =
     * 'inventory_counting' is unaffected because no second row is attempted.
     */
    public function test_re_running_the_listener_adds_no_movement_and_keeps_the_reference(): void
    {
        $asOf = CarbonImmutable::now()->subHours(2);
        $this->setOnHand($this->company, $this->product, $this->location, '70.0000');

        $counting = $this->makeCounting($this->company, $this->location, self::COUNTING_NUMBER);
        $this->makeItem($counting, $this->product, $this->location, '68.0000', '70.0000', $asOf);

        $this->fire($counting, $this->company, $this->location, $this->user);
        $this->fire($counting->fresh(['items']) ?? $counting, $this->company, $this->location, $this->user);

        $movements = StockMovement::query()->where('product_id', $this->product->id)->get();

        self::assertCount(1, $movements);
        self::assertSame(self::COUNTING_NUMBER, $movements->first()?->reference);
        self::assertSame('68.0000', (string) StockLevel::query()
            ->where('product_id', $this->product->id)
            ->value('quantity'));
    }

    /**
     * (7) Both audit events carry the counting number, not the constant. House
     * rule 8: the event CLASSES are untouched — only the value they carry.
     */
    public function test_both_stock_movement_events_carry_the_counting_number(): void
    {
        Event::fake([StockMovementRecorded::class, StockMovementRecordedV2::class]);

        $asOf = CarbonImmutable::now()->subHours(2);
        $this->setOnHand($this->company, $this->product, $this->location, '70.0000');

        $counting = $this->makeCounting($this->company, $this->location, self::COUNTING_NUMBER);
        $this->makeItem($counting, $this->product, $this->location, '68.0000', '70.0000', $asOf);

        $this->fire($counting, $this->company, $this->location, $this->user);

        Event::assertDispatched(
            StockMovementRecorded::class,
            static fn (StockMovementRecorded $event): bool => $event->reference === self::COUNTING_NUMBER
                && $event->referenceType === StockMovementReferenceType::InventoryCounting->value
                && $event->referenceId === $counting->id,
        );
        Event::assertDispatched(
            StockMovementRecordedV2::class,
            static fn (StockMovementRecordedV2 $event): bool => $event->reference === self::COUNTING_NUMBER
                && $event->referenceType === StockMovementReferenceType::InventoryCounting->value
                && $event->referenceId === $counting->id,
        );
    }
}
