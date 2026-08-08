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

        $this->assertDatabaseCount('pos_z_report_counts', 1);

        $row = ZReportCount::where('z_report_id', $zReportId)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->cashMethod->id, $row->payment_method_id);
        $this->assertSame('EUR', $row->currency_code);
        $this->assertSame('100.0000', $row->expected_amount);
        $this->assertSame('100.0000', $row->actual_amount);
        $this->assertSame('0.0000', $row->variance_amount);
        $this->assertSame('balanced', $row->variance_direction);
        $this->assertSame(2, $row->transaction_count);
    }

    public function test_legacy_denomination_only_cash_counts_payload_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = array_merge($this->buildV1Payload(), [
            'cash_counts' => [
                [
                    'payment_method_id' => $this->cashMethod->id,
                    'counted_quantity' => 5,
                    'counted_amount' => '50.00',
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        foreach ([
            'cash_counts.0.expected_amount',
            'cash_counts.0.actual_amount',
            'cash_counts.0.variance_amount',
            'cash_counts.0.variance_direction',
            'cash_counts.0.currency_code',
            'cash_counts.0.transaction_count',
        ] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    public function test_inconsistent_cash_count_variance_payload_is_rejected_before_persistence(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        $payload['cash_counts'][0]['variance_amount'] = '1.000';
        $payload['cash_counts'][0]['variance_direction'] = 'over';

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('cash_counts.0.variance_amount', $errors);
        $this->assertDatabaseCount('pos_z_report_counts', 0);
    }

    public function test_scientific_notation_cash_count_amounts_are_rejected_before_persistence(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        $payload['cash_counts'][0]['expected_amount'] = '1e3';
        $payload['cash_counts'][0]['actual_amount'] = '1000.0000';
        $payload['cash_counts'][0]['variance_amount'] = '0.0000';

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('cash_counts.0.expected_amount', $errors);
        $this->assertDatabaseCount('pos_z_report_counts', 0);
    }

    public function test_negative_expected_or_actual_cash_count_amounts_are_rejected_before_persistence(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        $payload['cash_counts'][0]['expected_amount'] = '-1.0000';
        $payload['cash_counts'][0]['actual_amount'] = '-1.0000';
        $payload['cash_counts'][0]['variance_amount'] = '0.0000';
        $payload['cash_counts'][0]['variance_direction'] = 'balanced';

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('cash_counts.0.expected_amount', $errors);
        $this->assertArrayHasKey('cash_counts.0.actual_amount', $errors);
        $this->assertDatabaseCount('pos_z_report_counts', 0);
    }

    public function test_duplicate_cash_count_payment_methods_are_rejected_before_persistence(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        $payload['cash_counts'][] = [
            'payment_method_id' => $this->cashMethod->id,
            'currency_code' => 'EUR',
            'expected_amount' => '50.0000',
            'actual_amount' => '50.0000',
            'variance_amount' => '0.0000',
            'variance_direction' => 'balanced',
            'transaction_count' => 1,
        ];

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('cash_counts.1.payment_method_id', $errors);
        $this->assertDatabaseCount('pos_z_report_counts', 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2b. receipt_snapshots / tolerance_summary scale validation (FISCAL ingress)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_over_precise_receipt_snapshot_total_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildV1Payload();
        $payload['receipt_snapshots'] = [
            [
                'subtotal' => '10.000',
                'tax_amount' => '2.000',
                'total' => '12.0001', // money ceiling is 3 decimal places
                'discount_amount' => '0.000',
            ],
        ];

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors') ?? $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('receipt_snapshots.0.total', $errors);
        $this->assertDatabaseCount('pos_z_reports', 0);
    }

    public function test_over_precise_tolerance_summary_total_is_rejected(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        $payload['tolerance_summary']['totalAmount'] = '3.5001'; // money ceiling 3dp

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors') ?? $response->json('errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('tolerance_summary.totalAmount', $errors);
        $this->assertDatabaseCount('pos_z_reports', 0);
    }

    public function test_well_formed_device_receipt_snapshots_still_sync(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildV1Payload();
        // Numeric (not string) device values, within the money scale ceiling.
        $payload['receipt_snapshots'] = [
            [
                'subtotal' => 10.0,
                'tax_amount' => 2.0,
                'total' => 12.0,
                'discount_amount' => 0.0,
            ],
        ];

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'synced');

        // INGRESS-ONLY: receipt_snapshots stored verbatim (device authority,
        // no canonicalisation of the fiscal payload).
        $zReportId = $response->json('data.id');
        $zReport = ZReport::find($zReportId);
        $this->assertNotNull($zReport);

        $snapshots = $zReport->receipt_snapshots ?? [];
        $this->assertEqualsWithDelta(12.0, (float) $snapshots[0]['total'], 0.0001);
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
        $this->assertSame('100.0000', (string) $shift->actual_cash);
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

    /**
     * A missing `shift_fields.variance_amount` no longer defaults to a hard zero
     * at the company currency scale — it is DERIVED from
     * `SUM(cash_counts[].variance_amount)` at scale 4 (DPA lane G3, gate finding
     * C1/I2).
     *
     * The shipping device's `LocalZReportShiftFields` never carries
     * `variance_amount`, so the old default silently made every real device
     * shortfall look balanced: `pos_shifts.variance` stayed NULL and
     * `OpenFraudAlertForShiftVariance` short-circuited on `isZero()`. Scale 4 is
     * the live path's own aggregate scale (`CashCountValidationService`) and the
     * scale of the `pos_shifts.variance` column, so the two paths now agree.
     *
     * This fixture's `cash_counts` sum to zero, so the VALUE is unchanged and
     * still balanced — only the scale of the derived string moved from the
     * company money scale to scale 4. See
     * ShiftCashVarianceOfflineDevicePayloadTest for the non-zero case.
     */
    public function test_cash_count_recorded_derives_a_missing_variance_from_the_tender_breakdown(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        unset($payload['shift_fields']['variance_amount']);

        $this->postJson('/api/v1/pos/reports/z/sync', $payload)->assertCreated();

        Event::assertDispatched(CashCountRecorded::class, function (CashCountRecorded $event): bool {
            $this->assertSame('EUR', $event->currencyCode);
            $this->assertSame('0.0000', $event->aggregateVariance->amount);
            $this->assertTrue($event->aggregateVariance->isZero());
            $this->assertSame('balanced', $event->varianceDirection->value);
            $this->assertSame('0.0000', $event->descriptionParams['aggregate_amount'] ?? null);

            return true;
        });
    }

    /**
     * The non-zero counterpart: a device payload with a real per-tender variance
     * and no `shift_fields.variance_amount` must surface that variance on the
     * event AND on `pos_shifts.variance` (gate finding C1).
     */
    public function test_a_missing_variance_amount_is_derived_from_a_non_zero_tender_breakdown(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = $this->buildSchema2Payload();
        unset($payload['shift_fields']['variance_amount']);
        $payload['cash_counts'][0]['actual_amount'] = '95.000';
        $payload['cash_counts'][0]['variance_amount'] = '-5.000';
        $payload['cash_counts'][0]['variance_direction'] = 'under';

        $this->postJson('/api/v1/pos/reports/z/sync', $payload)->assertCreated();

        $shift = $this->shift->fresh();
        $this->assertNotNull($shift);
        $this->assertNotNull($shift->variance);
        $this->assertSame(0, bccomp((string) $shift->variance, '-5.0000', 4));

        Event::assertDispatched(CashCountRecorded::class, function (CashCountRecorded $event): bool {
            $this->assertSame(0, bccomp($event->aggregateVariance->amount, '-5.0000', 4));
            $this->assertFalse($event->aggregateVariance->isZero());
            $this->assertSame('under', $event->varianceDirection->value);

            return true;
        });
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
    // Ingress precision — over-precise opening_cash is rejected (Phase 4.1).
    //
    // Binds to the REAL inline validator in
    // ZReportSyncController::sync() (app/Modules/POS/Presentation/Controllers/
    // ZReportSyncController.php:96) via a true HTTP request, so it fails if the
    // production scale-4 ceiling is changed to the wrong value.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_sync_rejects_over_precise_opening_cash(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = array_merge($this->buildV1Payload(), [
            'opening_cash' => '100.12345', // 5 decimals — over the scale-4 ceiling
        ]);

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(422);
        $errors = $response->json('error.errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('opening_cash', $errors);
    }

    public function test_sync_accepts_4_decimal_opening_cash(): void
    {
        Sanctum::actingAs($this->cashier);
        Event::fake();

        $payload = array_merge($this->buildV1Payload(), [
            'opening_cash' => '100.1234', // 4 decimals — within scale-4 ceiling
        ]);

        $response = $this->postJson('/api/v1/pos/reports/z/sync', $payload);

        $response->assertStatus(201);
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
                    'currency_code' => 'EUR',
                    'expected_amount' => '100.000',
                    'actual_amount' => '100.000',
                    'variance_amount' => '0.000',
                    'variance_direction' => 'balanced',
                    'transaction_count' => 2,
                ],
            ],
            'shift_fields' => [
                'variance_reason' => 'Short count from till reset',
                'variance_severity' => 'medium',
                'actual_cash' => '100.0000',
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
