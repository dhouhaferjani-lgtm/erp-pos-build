<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Domain\ZReportCount;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v1/pos/reports/z/sync with schema-v2 payload.
 *
 * Covers:
 *  1. Schema-v2 sync (cash_counts + shift_fields + manager_user_id + tolerance_summary) → 201
 *  2. cash_counts are persisted to pos_z_report_counts (aggregated by payment_method_id)
 *  3. shift_fields are applied to pos_shifts (actual_cash, variance, severity, notes, manager)
 *  4. tolerance_summary is stored inside report_data JSONB
 *  5. CashCountRecorded event is dispatched
 *  6. v1-only payload (no schema-v2 fields) still returns 201 without counts or event
 *  7. Duplicate sync (same z_number + same hash) returns 200 'duplicate'
 */
final class ZReportSyncControllerSchema2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    private User $manager;

    private Terminal $terminal;

    private Shift $shift;

    private PaymentMethod $cashMethod;

    private const PERMISSION = 'pos.operate_terminal';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestData();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Schema-v2 full payload → 201
    // ─────────────────────────────────────────────────────────────────────────

    public function test_schema2_sync_returns_201(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $this->buildSchema2Payload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'synced');
        $response->assertJsonStructure(['data' => ['status', 'id']]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. cash_counts persisted to pos_z_report_counts
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cash_counts_are_persisted_to_z_report_counts(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);
        $response->assertStatus(201);

        $zReportId = $response->json('data.id');

        // Two denomination entries for the same payment method → aggregated to one row.
        $this->assertDatabaseCount('pos_z_report_counts', 1);

        $row = ZReportCount::where('z_report_id', $zReportId)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->cashMethod->id, $row->payment_method_id);

        // Sum of both denomination counted_amounts: 50.00 + 20.00 = 70.00
        $this->assertSame('70.0000', $row->actual_amount);
        // transaction_count = sum of counted_quantities: 5 + 4 = 9
        $this->assertSame(9, $row->transaction_count);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. shift_fields applied to pos_shifts
    // ─────────────────────────────────────────────────────────────────────────

    public function test_shift_fields_are_applied_to_pos_shifts(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $this->buildSchema2Payload());
        $response->assertStatus(201);

        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);

        // actual_cash from shift_fields
        $this->assertSame('70.0000', (string) $shift->actual_cash);
        // variance_amount from shift_fields
        $this->assertSame('-5.0000', (string) $shift->variance);
        // variance_severity normalised from 'medium' → 'warning'
        $this->assertSame('warning', $shift->variance_severity);
        // variance_reason appended to notes
        $this->assertStringContainsString('Variance reason: Short count from till reset', (string) $shift->notes);
        // manager_override_by set from manager_user_id
        $this->assertSame($this->manager->id, $shift->manager_override_by);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. tolerance_summary stored in report_data JSONB
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tolerance_summary_stored_in_report_data(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $this->buildSchema2Payload());
        $response->assertStatus(201);

        $zReportId = $response->json('data.id');
        $zReport = ZReport::find($zReportId);
        $this->assertNotNull($zReport);

        $toleranceSummary = $zReport->report_data['tolerance_summary'] ?? null;
        $this->assertNotNull($toleranceSummary, 'tolerance_summary must be present in report_data');
        $this->assertSame(2, $toleranceSummary['writeoffCount']);
        $this->assertSame('3.500', $toleranceSummary['totalAmount']);
        $this->assertSame('EUR', $toleranceSummary['currencyCode']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. CashCountRecorded event dispatched
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cash_count_recorded_event_is_dispatched(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);
        $response->assertStatus(201);

        $zReportId = $response->json('data.id');

        Event::assertDispatched(
            CashCountRecorded::class,
            function (CashCountRecorded $e) use ($zReportId): bool {
                return $e->zReportId === $zReportId
                    && $e->shiftId === $this->shift->id
                    && $e->terminalId === $this->terminal->id
                    && $e->managerOverrideBy === $this->manager->id
                    && count($e->tenderBreakdown) === 1;
            }
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. v1-only payload → 201, no counts, no event
    // ─────────────────────────────────────────────────────────────────────────

    public function test_v1_only_payload_succeeds_without_counts_or_event(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $this->buildV1Payload());

        $response->assertStatus(201);
        $this->assertDatabaseCount('pos_z_report_counts', 0);
        Event::assertNotDispatched(CashCountRecorded::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Duplicate sync returns 200 'duplicate'
    // ─────────────────────────────────────────────────────────────────────────

    public function test_duplicate_sync_returns_200_duplicate(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildV1Payload();

        $this->postJson('/api/v1/pos/reports/z/sync', $payload)->assertStatus(201);
        $second = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $second->assertStatus(200);
        $second->assertJsonPath('data.status', 'duplicate');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a valid schema-v2 sync payload.
     *
     * @return array<string, mixed>
     */
    private function buildSchema2Payload(): array
    {
        return array_merge($this->buildV1Payload(), [
            'cash_counts' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'denomination_id' => null,
                    'counted_quantity' => 5,
                    'counted_amount' => '50.00',
                    'denomination_name' => '10',
                    'denomination_value' => '10.00',
                ],
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'denomination_id' => null,
                    'counted_quantity' => 4,
                    'counted_amount' => '20.00',
                    'denomination_name' => '5',
                    'denomination_value' => '5.00',
                ],
            ],
            'shift_fields' => [
                'variance_reason' => 'Short count from till reset',
                'variance_severity' => 'medium',
                'actual_cash' => '70.0000',
                'variance_amount' => '-5.0000',
            ],
            'manager_user_id' => $this->manager->id,
            'tolerance_summary' => [
                'writeoffCount' => 2,
                'totalAmount' => '3.500',
                'currencyCode' => 'EUR',
            ],
        ]);
    }

    /**
     * Build a valid schema-v1 sync payload (no schema-v2 fields).
     *
     * @return array<string, mixed>
     */
    private function buildV1Payload(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'z_number' => 1,
            'formatted_z_number' => 'Z0001',
            'generated_at' => now()->toIso8601String(),
            'fiscal_hash' => str_repeat('a', 64),
            'previous_hash' => 'GENESIS',
            'hash_sequence' => 1,
            'report_data' => ['sales_count' => 10, 'gross_sales' => '100.00'],
            'opening_cash' => '0.00',
            'expected_cash' => '75.00',
            'receipt_snapshots' => [],
            'grand_totals' => [],
        ];
    }

    private function setupTestData(): void
    {
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->manager = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate(self::PERMISSION, 'sanctum');
        $this->cashier->givePermissionTo(self::PERMISSION);

        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '75.0000',
        ]);
    }
}
