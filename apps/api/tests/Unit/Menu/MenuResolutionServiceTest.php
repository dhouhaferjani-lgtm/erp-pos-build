<?php

declare(strict_types=1);

namespace Tests\Unit\Menu;

use App\Modules\Company\Domain\Company;
use App\Modules\Menu\Application\Services\MenuResolutionService;
use App\Modules\Menu\Domain\Entities\Menu;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MenuResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private MenuResolutionService $service;
    private Tenant $tenant;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MenuResolutionService();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMenu(array $overrides = []): Menu
    {
        return Menu::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Menu',
            'is_default' => false,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function resolveAndAssertMenu(Menu $expected, ?Carbon $at = null): void
    {
        $result = $this->service->resolve($this->company->id, $at);
        $this->assertNotNull($result);
        $this->assertEquals($expected->id, $result->id);
    }

    // ── Default fallback ───────────────────────────────────────────────

    public function test_returns_null_when_no_menus_exist(): void
    {
        $result = $this->service->resolve($this->company->id);

        $this->assertNull($result);
    }

    public function test_returns_default_menu_when_no_non_default_matches(): void
    {
        $default = $this->createMenu(['name' => 'Default', 'is_default' => true]);

        $this->resolveAndAssertMenu($default);
    }

    public function test_ignores_inactive_default_menu(): void
    {
        $this->createMenu(['name' => 'Inactive Default', 'is_default' => true, 'is_active' => false]);

        $result = $this->service->resolve($this->company->id);

        $this->assertNull($result);
    }

    // ── Time range matching ────────────────────────────────────────────

    public function test_matches_menu_within_time_range(): void
    {
        $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $breakfast = $this->createMenu([
            'name' => 'Breakfast',
            'active_from' => '07:00:00',
            'active_until' => '11:00:00',
            'display_order' => 0,
        ]);

        $at = Carbon::parse('2026-02-28 09:00:00');
        $this->resolveAndAssertMenu($breakfast, $at);
    }

    public function test_falls_back_to_default_outside_time_range(): void
    {
        $default = $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $this->createMenu([
            'name' => 'Breakfast',
            'active_from' => '07:00:00',
            'active_until' => '11:00:00',
        ]);

        $at = Carbon::parse('2026-02-28 14:00:00');
        $this->resolveAndAssertMenu($default, $at);
    }

    public function test_menu_with_only_active_from_matches_after_time(): void
    {
        $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $evening = $this->createMenu([
            'name' => 'Evening',
            'active_from' => '18:00:00',
            'active_until' => null,
        ]);

        $at = Carbon::parse('2026-02-28 20:00:00');
        $this->resolveAndAssertMenu($evening, $at);
    }

    // ── Date range matching ────────────────────────────────────────────

    public function test_matches_menu_within_date_range(): void
    {
        $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $seasonal = $this->createMenu([
            'name' => 'Summer Special',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
        ]);

        $at = Carbon::parse('2026-07-15 12:00:00');
        $this->resolveAndAssertMenu($seasonal, $at);
    }

    public function test_does_not_match_menu_before_start_date(): void
    {
        $default = $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $this->createMenu([
            'name' => 'Summer Special',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
        ]);

        $at = Carbon::parse('2026-05-15 12:00:00');
        $this->resolveAndAssertMenu($default, $at);
    }

    public function test_does_not_match_menu_after_end_date(): void
    {
        $default = $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $this->createMenu([
            'name' => 'Summer Special',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
        ]);

        $at = Carbon::parse('2026-09-15 12:00:00');
        $this->resolveAndAssertMenu($default, $at);
    }

    // ── Day-of-week filtering ──────────────────────────────────────────

    public function test_matches_menu_on_available_day(): void
    {
        $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $weekend = $this->createMenu([
            'name' => 'Weekend Brunch',
            'available_days' => [6, 7], // Saturday=6, Sunday=7
        ]);

        $saturday = Carbon::parse('2026-02-28 10:00:00');
        $this->resolveAndAssertMenu($weekend, $saturday);
    }

    public function test_does_not_match_menu_on_unavailable_day(): void
    {
        $default = $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $this->createMenu([
            'name' => 'Weekend Brunch',
            'available_days' => [6, 7],
        ]);

        $wednesday = Carbon::parse('2026-02-25 10:00:00');
        $this->resolveAndAssertMenu($default, $wednesday);
    }

    public function test_menu_with_empty_available_days_matches_any_day(): void
    {
        $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $always = $this->createMenu([
            'name' => 'Always Available',
            'available_days' => [],
        ]);

        $this->resolveAndAssertMenu($always, Carbon::parse('2026-02-25 10:00:00'));
    }

    // ── Combined rules ─────────────────────────────────────────────────

    public function test_all_rules_must_match(): void
    {
        $default = $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $this->createMenu([
            'name' => 'Saturday Breakfast',
            'active_from' => '07:00:00',
            'active_until' => '11:00:00',
            'available_days' => [6],
        ]);

        // Right time, wrong day (Wednesday)
        $this->resolveAndAssertMenu($default, Carbon::parse('2026-02-25 09:00:00'));

        // Right day, wrong time
        $this->resolveAndAssertMenu($default, Carbon::parse('2026-02-28 14:00:00'));
    }

    // ── Priority resolution ────────────────────────────────────────────

    public function test_lower_display_order_wins(): void
    {
        $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $this->createMenu(['name' => 'Low Priority', 'display_order' => 10]);
        $highPriority = $this->createMenu(['name' => 'High Priority', 'display_order' => 1]);

        $this->resolveAndAssertMenu($highPriority);
    }

    public function test_non_default_match_takes_precedence_over_default(): void
    {
        $this->createMenu(['name' => 'Default', 'is_default' => true]);
        $nonDefault = $this->createMenu(['name' => 'Special', 'is_default' => false]);

        $this->resolveAndAssertMenu($nonDefault);
    }

    // ── Company isolation ──────────────────────────────────────────────

    public function test_does_not_resolve_menus_from_other_company(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        Menu::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Company Menu',
            'is_default' => true,
            'is_active' => true,
        ]);

        $result = $this->service->resolve($this->company->id);

        $this->assertNull($result);
    }
}
