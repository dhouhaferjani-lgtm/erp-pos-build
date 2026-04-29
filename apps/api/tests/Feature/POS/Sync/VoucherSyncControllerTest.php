<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Sync;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Voucher\Domain\Enums\RedemptionMode;
use App\Modules\Voucher\Domain\Enums\VoucherSource;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for GET /api/v1/pos/vouchers/sync.
 *
 * Verifies single-terminal scope, cursor-based delta sync, page cap,
 * PascalCase enum serialisation, and auth/permission guards.
 */
final class VoucherSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Terminal $terminalA;

    private Terminal $terminalB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
    }

    public function test_returns_only_vouchers_redeemable_at_this_terminal(): void
    {
        Sanctum::actingAs($this->user);

        // Two vouchers redeemable at terminal A.
        Voucher::factory()->forTerminal($this->terminalA)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);
        Voucher::factory()->forTerminal($this->terminalA)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);

        // One voucher redeemable at terminal B (must NOT appear in A's pull).
        Voucher::factory()->forTerminal($this->terminalB)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);

        $response = $this->getJson(
            "/api/v1/pos/vouchers/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $vouchers = $response->json('data.vouchers');
        $this->assertCount(2, $vouchers);

        foreach ($vouchers as $row) {
            $this->assertSame($this->terminalA->id, $row['redeemable_at_terminal_id']);
        }
    }

    public function test_respects_updated_since_cursor(): void
    {
        Sanctum::actingAs($this->user);

        // Old voucher.
        $old = Voucher::factory()->forTerminal($this->terminalA)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);
        Voucher::query()->whereKey($old->id)->update([
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        // New voucher.
        $new = Voucher::factory()->forTerminal($this->terminalA)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'issued_by_user_id' => $this->user->id,
        ]);
        Voucher::query()->whereKey($new->id)->update([
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);

        // Without cursor: 2 rows.
        $bare = $this->getJson(
            "/api/v1/pos/vouchers/sync?terminal_id={$this->terminalA->id}"
        );
        $bare->assertStatus(200);
        $this->assertCount(2, $bare->json('data.vouchers'));

        // With cursor 2 days ago: only the new one.
        $cursor = urlencode(Carbon::now()->subDays(2)->toIso8601String());
        $delta = $this->getJson(
            "/api/v1/pos/vouchers/sync?terminal_id={$this->terminalA->id}&updated_since={$cursor}"
        );
        $delta->assertStatus(200);
        $rows = $delta->json('data.vouchers');
        $this->assertCount(1, $rows);
        $this->assertSame($new->id, $rows[0]['id']);
    }

    public function test_paginates_at_100_per_page(): void
    {
        Sanctum::actingAs($this->user);

        // Seed 150 vouchers redeemable at terminal A.
        Voucher::factory()
            ->count(150)
            ->forTerminal($this->terminalA)
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'issued_by_user_id' => $this->user->id,
            ]);

        $response = $this->getJson(
            "/api/v1/pos/vouchers/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $vouchers = $response->json('data.vouchers');
        $this->assertLessThanOrEqual(100, count($vouchers));
        $this->assertSame(100, count($vouchers), 'expected exactly the page cap');
    }

    public function test_serializes_enums_in_pascal_case(): void
    {
        Sanctum::actingAs($this->user);

        Voucher::factory()
            ->forTerminal($this->terminalA)
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'issued_by_user_id' => $this->user->id,
                'status' => VoucherStatus::PartiallyRedeemed,
                'redemption_mode' => RedemptionMode::CustomerBound,
                'source' => VoucherSource::ExchangeSurplus,
            ]);

        $response = $this->getJson(
            "/api/v1/pos/vouchers/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(200);
        $row = $response->json('data.vouchers.0');

        // Wire format MUST be PascalCase case-name, NOT snake_case backing value.
        $this->assertSame('PartiallyRedeemed', $row['status']);
        $this->assertSame('CustomerBound', $row['redemption_mode']);
        $this->assertSame('ExchangeSurplus', $row['source']);
        $this->assertSame('MPV', $row['voucher_kind']);
    }

    public function test_returns_401_without_auth(): void
    {
        $response = $this->getJson(
            "/api/v1/pos/vouchers/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(401);
    }

    public function test_returns_403_without_pos_operate_terminal_permission(): void
    {
        $unprivileged = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        // Note: this user has NO pos.operate_terminal permission.

        Sanctum::actingAs($unprivileged);

        $response = $this->getJson(
            "/api/v1/pos/vouchers/sync?terminal_id={$this->terminalA->id}"
        );

        $response->assertStatus(403);
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->terminalA = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS-A',
        ]);

        $this->terminalB = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'POS-B',
        ]);
    }
}
