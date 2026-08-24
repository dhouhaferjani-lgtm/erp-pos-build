<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Domain\OrderLine;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Session B lane Q-9 — triage F1 (SM-1).
 *
 * Two properties, one class, deliberately placed in the LIVE `security-regression`
 * whole-directory lane rather than the PARKED `feature-lane-pos/POS` lane:
 *
 * A. **Rule-12 both-layer module gating.** The web layer has hidden the KDS behind
 *    `ModuleGuard module="Menu"` since the F&B-leak pass, but the backend mirrored
 *    nothing — `pos_orders` and `/pos/kitchen/*` were reachable by any holder of
 *    `pos.operate_terminal` on ANY vertical, parapharmacy included. The gate is
 *    `Menu` (not `Tables`) because `coffee_shop` has Menu but NOT Tables and must
 *    keep the order/kitchen workflow.
 *
 * B. **Terminal-state guard.** `updateLineStatus` never inspected the ORDER status,
 *    so the chain create -> line -> send-to-kitchen -> cancel -> PATCH line=ready
 *    flipped a Cancelled order back to Ready (`ready_at` set alongside a populated
 *    `cancelled_at`, order back in the KDS feed, spurious OrderReady broadcast).
 *
 * The retired `POST /pos/orders/{id}/close` tombstone (410 Gone,
 * NEW_SALE_AUTHORING_RETIRED, fiscal Phase 1 §14.2) is deliberately left OUTSIDE
 * the module gate so its tombstone semantics stay identical on every vertical.
 */
final class PosOrderKitchenModuleAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an authenticated tenant context for the given vertical.
     *
     * @return array{user: User, company: Company, tenant: Tenant}
     */
    private function makeContext(Vertical $vertical, string $slug): array
    {
        $tenant = Tenant::create([
            'name' => "Test {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => $vertical,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => "Test {$slug} Company",
            'legal_name' => "Test {$slug} Company LLC",
            'tax_id' => 'TAX'.strtoupper(str_replace('-', '', $slug)),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => "user@{$slug}.test",
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);

        return ['user' => $user, 'company' => $company, 'tenant' => $tenant];
    }

    // ─── A. Module gating ────────────────────────────────────────────────────

    public function test_a_non_menu_vertical_is_blocked_from_every_kitchen_route_with_403(): void
    {
        $ctx = $this->makeContext(Vertical::Parapharmacy, 'parapharmacy-kds');
        $orderId = Str::uuid()->toString();
        $lineId = Str::uuid()->toString();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/kitchen/orders')
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->patchJson("/api/v1/pos/kitchen/orders/{$orderId}/lines/{$lineId}/status", ['status' => 'ready'])
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/kitchen/orders/{$orderId}/bump")
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$orderId}/served")
            ->assertStatus(403);
    }

    public function test_a_non_menu_vertical_is_blocked_from_every_live_order_route_with_403(): void
    {
        $ctx = $this->makeContext(Vertical::Parapharmacy, 'parapharmacy-orders');
        $orderId = Str::uuid()->toString();
        $lineId = Str::uuid()->toString();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/orders')
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson('/api/v1/pos/orders', [])
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson("/api/v1/pos/orders/{$orderId}")
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$orderId}/lines", [])
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->patchJson("/api/v1/pos/orders/{$orderId}/lines/{$lineId}", [])
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->deleteJson("/api/v1/pos/orders/{$orderId}/lines/{$lineId}")
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$orderId}/send-to-kitchen")
            ->assertStatus(403);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$orderId}/cancel", ['reason' => 'x'])
            ->assertStatus(403);
    }

    /**
     * coffee_shop has Menu but NOT Tables — the whole order/kitchen workflow must
     * stay reachable for it, which is why the gate key is Menu.
     */
    public function test_a_menu_vertical_can_still_reach_the_order_and_kitchen_routes(): void
    {
        $ctx = $this->makeContext(Vertical::CoffeeShop, 'coffee-shop-kds');

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/kitchen/orders')
            ->assertSuccessful();

        $this->actingAs($ctx['user'], 'sanctum')
            ->getJson('/api/v1/pos/orders')
            ->assertSuccessful();
    }

    /**
     * The §14.2 tombstone is NOT behind the module gate: its 410 answer must be
     * identical on a vertical that has no Menu module.
     */
    public function test_the_retired_order_close_tombstone_still_answers_410_without_the_menu_module(): void
    {
        $ctx = $this->makeContext(Vertical::Parapharmacy, 'parapharmacy-tombstone');
        $orderId = Str::uuid()->toString();

        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$orderId}/close");

        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'NEW_SALE_AUTHORING_RETIRED');
    }

    // ─── B. Terminal-state guard ─────────────────────────────────────────────

    /**
     * Triage §2, the LIVE half of the SM-1 exploit chain.
     */
    public function test_a_cancelled_order_cannot_be_flipped_back_to_ready_by_a_line_status_update(): void
    {
        $ctx = $this->makeContext(Vertical::CoffeeShop, 'coffee-shop-cancel');
        [$order, $line] = $this->seedSentToKitchenOrder($ctx['tenant'], $ctx['company'], $ctx['user']);

        // Cancel the order — lines are deliberately NOT touched by cancelOrder.
        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$order->id}/cancel", ['reason' => 'Customer left'])
            ->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);

        // The hole: PATCH the still-`sent` line to `ready`.
        $response = $this->actingAs($ctx['user'], 'sanctum')
            ->patchJson(
                "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
                ['status' => 'ready'],
            );

        $response->assertStatus(422);

        $order->refresh();
        $line->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status, 'A cancelled order must never flip to Ready.');
        $this->assertNull($order->ready_at, 'ready_at must not be stamped on a cancelled order.');
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(OrderLineStatus::Sent, $line->status, 'The line must be left untouched by the refusal.');
        $this->assertNull($line->prepared_at);
    }

    public function test_an_active_order_still_transitions_to_ready_when_its_last_line_is_marked_ready(): void
    {
        $ctx = $this->makeContext(Vertical::CoffeeShop, 'coffee-shop-happy');
        [$order, $line] = $this->seedSentToKitchenOrder($ctx['tenant'], $ctx['company'], $ctx['user']);

        $this->actingAs($ctx['user'], 'sanctum')
            ->patchJson(
                "/api/v1/pos/kitchen/orders/{$order->id}/lines/{$line->id}/status",
                ['status' => 'ready'],
            )
            ->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Ready, $order->status);
        $this->assertNotNull($order->ready_at);
    }

    public function test_bump_and_served_still_refuse_a_cancelled_order(): void
    {
        $ctx = $this->makeContext(Vertical::CoffeeShop, 'coffee-shop-bump');
        [$order] = $this->seedSentToKitchenOrder($ctx['tenant'], $ctx['company'], $ctx['user']);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$order->id}/cancel", ['reason' => 'Customer left'])
            ->assertOk();

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/kitchen/orders/{$order->id}/bump")
            ->assertStatus(422);

        $this->actingAs($ctx['user'], 'sanctum')
            ->postJson("/api/v1/pos/orders/{$order->id}/served")
            ->assertStatus(422);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array{0: Order, 1: OrderLine}
     */
    private function seedSentToKitchenOrder(Tenant $tenant, Company $company, User $user): array
    {
        $location = Location::factory()->create([
            'company_id' => $company->id,
        ]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Test Coffee',
            'sale_price' => '10.0000',
            'tax_rate' => '19.00',
        ]);

        /** @var Order $order */
        $order = Order::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'order_number' => '#001',
            'status' => OrderStatus::SentToKitchen,
            'cashier_id' => $user->id,
            'cashier_name' => $user->name,
            'subtotal' => '8.403',
            'tax_amount' => '1.597',
            'discount_amount' => '0.000',
            'total' => '10.000',
            'currency' => 'EUR',
            'opened_at' => now()->subMinutes(5),
            'sent_at' => now(),
        ]);

        /** @var OrderLine $line */
        $line = OrderLine::create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'product_name' => 'Test Coffee',
            'quantity' => '1.000',
            'unit_price' => '10.000',
            'discount_amount' => '0.000',
            'tax_rate' => '19.00',
            'tax_amount' => '1.597',
            'line_total' => '10.000',
            'status' => OrderLineStatus::Sent,
            'sent_at' => now(),
        ]);

        return [$order, $line];
    }
}
