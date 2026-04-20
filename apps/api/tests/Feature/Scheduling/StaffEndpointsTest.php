<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use App\Modules\Scheduling\Domain\Enums\AppointmentStatus;
use App\Modules\Scheduling\Domain\ScheduleConfig;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 16 — staff REST endpoint coverage for the Scheduling module.
 *
 * Permissions under test (exact strings — asserted via permission-denied
 * 403 responses and happy-path 200/201 responses):
 *
 *   - scheduling.bays.view
 *   - scheduling.bays.manage
 *   - scheduling.appointments.view
 *   - scheduling.appointments.create
 *   - scheduling.appointments.update
 *   - scheduling.appointments.cancel
 *   - scheduling.appointments.convert
 *
 * Plus endpoint smoke tests:
 *   - bay CRUD happy path
 *   - appointment list + show + create + update + destroy
 *   - transition endpoints (confirm / reschedule / check-in / cancel)
 *   - calendar endpoints (day / week / month / free-slots)
 *   - GiST overlap yields 409
 */
final class StaffEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);

        foreach ([
            'scheduling.bays.view',
            'scheduling.bays.manage',
            'scheduling.appointments.view',
            'scheduling.appointments.create',
            'scheduling.appointments.update',
            'scheduling.appointments.cancel',
            'scheduling.appointments.convert',
        ] as $perm) {
            Permission::findOrCreate($perm, 'sanctum');
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    public function test_bay_list_requires_view_permission_and_returns_bays_for_company(): void
    {
        Bay::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'location_id' => $this->location->id]);
        Bay::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'location_id' => $this->location->id]);

        $this->actingAsUser([]);
        $this->getJson('/api/v1/scheduling/bays')->assertStatus(403);

        $this->actingAsUser(['scheduling.bays.view']);
        $response = $this->getJson('/api/v1/scheduling/bays');
        $response->assertOk();
        $this->assertCount(2, (array) $response->json('data'));
    }

    public function test_bay_create_requires_manage_permission(): void
    {
        $payload = [
            'location_id' => $this->location->id,
            'code' => 'BAY-001',
            'name' => 'Bay 1',
            'bay_type' => 'general',
            'operating_hours' => ['mon' => [['start' => '08:00', 'end' => '17:00']]],
        ];

        $this->actingAsUser(['scheduling.bays.view']);
        $this->postJson('/api/v1/scheduling/bays', $payload)->assertStatus(403);

        $this->actingAsUser(['scheduling.bays.manage', 'scheduling.bays.view']);
        $response = $this->postJson('/api/v1/scheduling/bays', $payload);
        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'BAY-001');
    }

    public function test_bay_update_and_delete_happy_path(): void
    {
        $bay = Bay::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'code' => 'BAY-ORIGINAL',
        ]);

        $this->actingAsUser(['scheduling.bays.manage', 'scheduling.bays.view']);

        $this->patchJson('/api/v1/scheduling/bays/'.$bay->id, ['code' => 'BAY-UPDATED'])
            ->assertOk()
            ->assertJsonPath('data.code', 'BAY-UPDATED');

        $this->deleteJson('/api/v1/scheduling/bays/'.$bay->id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    public function test_schedule_config_show_and_update(): void
    {
        $this->actingAsUser(['scheduling.bays.manage', 'scheduling.bays.view']);

        $config = ScheduleConfig::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->getJson('/api/v1/scheduling/config/'.$this->location->id)
            ->assertOk()
            ->assertJsonPath('data.id', $config->id);

        $response = $this->patchJson('/api/v1/scheduling/config/'.$this->location->id, [
            'time_slot_minutes' => 30,
            'overbooking_threshold_percent' => 125,
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.time_slot_minutes', 30);
        $response->assertJsonPath('data.overbooking_threshold_percent', 125);
    }

    public function test_appointment_list_show_respects_view_permission(): void
    {
        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->actingAsUser([]);
        $this->getJson('/api/v1/scheduling/appointments')->assertStatus(403);

        $this->actingAsUser(['scheduling.appointments.view']);
        $response = $this->getJson('/api/v1/scheduling/appointments');
        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $response->json('meta.total'));
    }

    public function test_appointment_create_and_update_and_destroy_happy_path(): void
    {
        $this->actingAsUser([
            'scheduling.appointments.view',
            'scheduling.appointments.create',
            'scheduling.appointments.update',
            'scheduling.appointments.cancel',
        ]);

        $start = Carbon::now()->addDays(3)->setTime(10, 0)->toDateTimeImmutable();
        $end = $start->modify('+1 hour');

        $create = $this->postJson('/api/v1/scheduling/appointments', [
            'location_id' => $this->location->id,
            'appointment_type' => 'standard_repair',
            'wait_type' => 'drop_off',
            'scheduled_start' => $start->format(\DateTimeInterface::ATOM),
            'scheduled_end' => $end->format(\DateTimeInterface::ATOM),
            'estimated_duration_minutes' => 60,
            'customer_name' => 'Jane',
            'customer_phone' => '+21611111111',
            'vehicle_plate' => 'TN-1234',
            'planned_services' => [[
                'service_ref_type' => 'service',
                'service_ref_id' => (string) Str::uuid(),
                'display_name' => 'Oil change',
                'estimated_duration_minutes' => 60,
                'estimated_price' => '49.900',
            ]],
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertIsString($id);

        $this->patchJson('/api/v1/scheduling/appointments/'.$id, ['internal_notes' => 'VIP customer'])
            ->assertOk()
            ->assertJsonPath('data.internal_notes', 'VIP customer');

        $this->deleteJson('/api/v1/scheduling/appointments/'.$id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    public function test_overlap_booking_returns_409_conflict_detail(): void
    {
        $this->actingAsUser(['scheduling.appointments.create']);

        $bay = Bay::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $start = Carbon::now()->addDays(2)->setTime(10, 0)->toDateTimeImmutable();
        $end = $start->modify('+1 hour');

        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'bay_id' => $bay->id,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
        ]);

        $response = $this->postJson('/api/v1/scheduling/appointments', [
            'location_id' => $this->location->id,
            'bay_id' => $bay->id,
            'appointment_type' => 'standard_repair',
            'scheduled_start' => $start->modify('+30 minutes')->format(\DateTimeInterface::ATOM),
            'scheduled_end' => $end->modify('+30 minutes')->format(\DateTimeInterface::ATOM),
            'estimated_duration_minutes' => 60,
            'customer_name' => 'Jane',
            'customer_phone' => '+21611111111',
            'vehicle_plate' => 'TN-9999',
            'planned_services' => [[
                'service_ref_type' => 'service',
                'service_ref_id' => (string) Str::uuid(),
                'display_name' => 'Brakes',
                'estimated_duration_minutes' => 60,
            ]],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'appointment_conflict');
        $response->assertJsonPath('conflict.conflict_type', 'overlap');
    }

    public function test_transition_confirm_and_checkin_happy_path(): void
    {
        $this->actingAsUser(['scheduling.appointments.update']);

        $appt = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $confirm = $this->postJson('/api/v1/scheduling/appointments/'.$appt->id.'/confirm');
        $confirm->assertOk();
        $confirm->assertJsonPath('data.status', AppointmentStatus::Confirmed->value);

        $checkin = $this->postJson('/api/v1/scheduling/appointments/'.$appt->id.'/check-in');
        $checkin->assertOk();
        $checkin->assertJsonPath('data.status', AppointmentStatus::CheckedIn->value);
    }

    public function test_transition_reschedule_returns_new_window(): void
    {
        $this->actingAsUser(['scheduling.appointments.update']);

        $bay = Bay::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $appt = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'bay_id' => $bay->id,
        ]);

        $newStart = Carbon::now()->addDays(7)->setTime(14, 0)->toDateTimeImmutable();
        $newEnd = $newStart->modify('+1 hour');

        $response = $this->postJson('/api/v1/scheduling/appointments/'.$appt->id.'/reschedule', [
            'new_bay_id' => $bay->id,
            'new_scheduled_start' => $newStart->format(\DateTimeInterface::ATOM),
            'new_scheduled_end' => $newEnd->format(\DateTimeInterface::ATOM),
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.bay_id', $bay->id);
    }

    public function test_transition_cancel_requires_cancel_permission(): void
    {
        $appt = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        // Without cancel permission → 403.
        $this->actingAsUser(['scheduling.appointments.view']);
        $this->postJson('/api/v1/scheduling/appointments/'.$appt->id.'/cancel', ['reason_code' => 'customer_request'])
            ->assertStatus(403);

        // With cancel permission → 200.
        $this->actingAsUser(['scheduling.appointments.cancel']);
        $this->postJson('/api/v1/scheduling/appointments/'.$appt->id.'/cancel', ['reason_code' => 'customer_request'])
            ->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::Cancelled->value);
    }

    public function test_convert_endpoint_requires_convert_permission(): void
    {
        $appt = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        // Without convert permission → 403.
        $this->actingAsUser(['scheduling.appointments.view']);
        $this->postJson('/api/v1/scheduling/appointments/'.$appt->id.'/convert')
            ->assertStatus(403);

        // With convert permission but status=Scheduled (not convertible) → 422.
        $this->actingAsUser(['scheduling.appointments.convert']);
        $this->postJson('/api/v1/scheduling/appointments/'.$appt->id.'/convert')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'appointment_not_convertible');
    }

    public function test_calendar_day_endpoint_returns_shape(): void
    {
        $this->actingAsUser(['scheduling.appointments.view']);

        Bay::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $response = $this->getJson('/api/v1/scheduling/calendar/day?date='.Carbon::now()->addDay()->toDateString());
        $response->assertOk();
        $data = (array) $response->json('data');
        $this->assertArrayHasKey('date', $data);
        $this->assertArrayHasKey('availability', $data);
        $this->assertArrayHasKey('booked', $data);
    }

    public function test_calendar_month_and_week_endpoints_return_data(): void
    {
        $this->actingAsUser(['scheduling.appointments.view']);

        $this->getJson('/api/v1/scheduling/calendar/month?year=2026&month=5')->assertOk();
        $this->getJson('/api/v1/scheduling/calendar/week?week_start='.Carbon::now()->addDays(7)->toDateString())->assertOk();
    }

    public function test_calendar_free_slots_endpoint_returns_list(): void
    {
        $this->actingAsUser(['scheduling.appointments.view']);

        Bay::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $from = Carbon::now()->addDays(1)->toDateString();
        $to = Carbon::now()->addDays(2)->toDateString();
        $response = $this->getJson('/api/v1/scheduling/calendar/free-slots?duration=60&from='.$from.'&to='.$to);
        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsUser(array $permissions): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Manager,
            'status' => MembershipStatus::Active,
            'is_primary' => true,
        ]);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        app(CompanyContext::class)->setCompanyId($this->company->id);
        Sanctum::actingAs($user);

        return $user;
    }
}
