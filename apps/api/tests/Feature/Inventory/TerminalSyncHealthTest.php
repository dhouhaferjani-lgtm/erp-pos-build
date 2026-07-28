<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Application\Services\TerminalSyncHealthService;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\Exceptions\TerminalSyncAcknowledgementRequiredException;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TerminalSyncHealthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->tenant = Tenant::create([
            'name' => 'Terminal Health Tenant',
            'slug' => 'terminal-health-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Terminal Health Company',
            'legal_name' => 'Terminal Health Company LLC',
            'tax_id' => 'THC-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Terminal Health Reviewer',
            'email' => 'terminal-health-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'TH-01',
            'name' => 'Terminal Health Shop',
            'type' => LocationType::Shop,
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'TH-001',
            'name' => 'Terminal Health Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000',
        ]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical,
            'is_training_mode' => false,
            'hardware_identifier' => 'device-terminal-health',
        ]);
    }

    public function test_pending_receipts_and_stale_or_unknown_terminals_require_acknowledgement(): void
    {
        Carbon::setTestNow('2026-07-28 12:00:00');
        $counting = $this->pendingReviewCounting();
        $service = app(TerminalSyncHealthService::class);

        $unknown = $service->forCounting($counting);
        $this->assertTrue($unknown['requires_acknowledgement']);
        $this->assertSame('unknown', $unknown['terminals'][0]['state']);

        $service->record($this->company->id, $this->terminal->id, 3, now());
        $pending = $service->forCounting($counting);
        $this->assertTrue($pending['requires_acknowledgement']);
        $this->assertSame('pending', $pending['terminals'][0]['state']);
        $this->assertSame(3, $pending['terminals'][0]['pending_receipt_count']);
        $pendingSignature = $pending['acknowledgement_signature'];

        Carbon::setTestNow(now()->addMinute());
        $service->record($this->company->id, $this->terminal->id, 3, now());
        $refreshedPending = $service->forCounting($counting);
        $this->assertSame($pendingSignature, $refreshedPending['acknowledgement_signature']);

        $service->record($this->company->id, $this->terminal->id, 4, now());
        $changedPending = $service->forCounting($counting);
        $this->assertNotSame($pendingSignature, $changedPending['acknowledgement_signature']);

        $service->record($this->company->id, $this->terminal->id, 0, now()->subMinutes(10));
        $freshReportWithSkewedDeviceClock = $service->forCounting($counting);
        $this->assertFalse($freshReportWithSkewedDeviceClock['requires_acknowledgement']);
        $this->assertSame('healthy', $freshReportWithSkewedDeviceClock['terminals'][0]['state']);

        Carbon::setTestNow(now()->addMinutes(10));
        $stale = $service->forCounting($counting);
        $this->assertTrue($stale['requires_acknowledgement']);
        $this->assertSame('stale', $stale['terminals'][0]['state']);

        $service->record($this->company->id, $this->terminal->id, 0, now());
        $healthy = $service->forCounting($counting);
        $this->assertFalse($healthy['requires_acknowledgement']);
        $this->assertSame('healthy', $healthy['terminals'][0]['state']);
    }

    public function test_deactivated_physical_terminal_with_pending_receipts_remains_visible(): void
    {
        $counting = $this->pendingReviewCounting();
        $this->terminal->update(['is_active' => false]);
        app(TerminalSyncHealthService::class)->record(
            $this->company->id,
            $this->terminal->id,
            2,
            now(),
        );

        $health = app(TerminalSyncHealthService::class)->forCounting($counting);

        $this->assertTrue($health['requires_acknowledgement']);
        $this->assertSame('pending', $health['terminals'][0]['state']);
        $this->assertSame(2, $health['terminals'][0]['pending_receipt_count']);
    }

    public function test_finalize_requires_explicit_acknowledgement_when_terminal_health_warns(): void
    {
        Event::fake([InventoryCountingCompleted::class]);
        $counting = $this->pendingReviewCounting();
        $service = app(InventoryCountingService::class);

        try {
            $service->finalize($counting, $this->user);
            $this->fail('Expected terminal sync acknowledgement to be required.');
        } catch (TerminalSyncAcknowledgementRequiredException) {
            $this->assertSame(
                CountingStatus::PendingReview,
                InventoryCounting::query()->findOrFail($counting->id)->status,
            );
        }

        $health = app(TerminalSyncHealthService::class)->forCounting($counting);
        $signature = $health['acknowledgement_signature'];
        $this->assertIsString($signature);

        try {
            $service->finalize($counting->fresh() ?? $counting, $this->user, true, 'stale-signature');
            $this->fail('Expected a stale terminal sync acknowledgement to be rejected.');
        } catch (TerminalSyncAcknowledgementRequiredException) {
            $this->assertSame(
                CountingStatus::PendingReview,
                InventoryCounting::query()->findOrFail($counting->id)->status,
            );
        }

        $service->finalize($counting->fresh() ?? $counting, $this->user, true, $signature);

        $this->assertSame(
            CountingStatus::Finalized,
            InventoryCounting::query()->findOrFail($counting->id)->status,
        );

        $finalizedEvent = InventoryCountingEvent::query()
            ->where('counting_id', $counting->id)
            ->where('event_type', InventoryCountingEvent::COUNTING_FINALIZED)
            ->firstOrFail();
        $this->assertTrue($finalizedEvent->event_data['terminal_sync_health_acknowledged']);
        $this->assertSame($this->user->id, $finalizedEvent->event_data['terminal_sync_health_acknowledged_by']);
        $this->assertIsString($finalizedEvent->event_data['terminal_sync_health_acknowledged_at']);
        $this->assertSame($signature, $finalizedEvent->event_data['terminal_sync_health_signature']);
        $this->assertSame('unknown', $finalizedEvent->event_data['terminal_sync_health']['terminals'][0]['state']);
    }

    public function test_pos_health_endpoint_records_only_a_terminal_in_the_current_company(): void
    {
        Carbon::setTestNow('2026-07-28 12:00:00');

        $payload = [
            'terminal_id' => $this->terminal->id,
            'hardware_identifier' => 'device-terminal-health',
            'pending_receipt_count' => 2,
            'last_sync_at' => now()->toIso8601String(),
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/terminal-sync-health', $payload)
            ->assertForbidden();

        $this->user->givePermissionTo('pos.operate_terminal');

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/terminal-sync-health', [
                ...$payload,
                'hardware_identifier' => 'another-device',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TERMINAL_DEVICE_MISMATCH');

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/terminal-sync-health', $payload)
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        $this->terminal->update(['is_active' => false]);
        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/terminal-sync-health', $payload)
            ->assertOk()
            ->assertJsonPath('data.recorded', true);

        $health = app(TerminalSyncHealthService::class)->forCounting($this->pendingReviewCounting());
        $this->assertSame('pending', $health['terminals'][0]['state']);
        $this->assertSame(2, $health['terminals'][0]['pending_receipt_count']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/terminal-sync-health', [
                'terminal_id' => '11111111-1111-4111-8111-111111111111',
                'hardware_identifier' => 'device-terminal-health',
                'pending_receipt_count' => 0,
                'last_sync_at' => now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TERMINAL_DEVICE_MISMATCH');
    }

    private function pendingReviewCounting(): InventoryCounting
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'execution_mode' => CountingExecutionMode::Parallel,
            'status' => CountingStatus::PendingReview,
            'requires_count_2' => false,
            'requires_count_3' => false,
            'allow_unexpected_items' => false,
        ]);

        InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
            'theoretical_qty' => '5.0000',
            'count_1_qty' => '5.0000',
            'count_1_at' => now(),
            'count_1_at_estimate' => now(),
            'final_qty' => '5.0000',
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
            'is_flagged' => false,
            'is_unexpected_item' => false,
        ]);

        return $counting;
    }
}
