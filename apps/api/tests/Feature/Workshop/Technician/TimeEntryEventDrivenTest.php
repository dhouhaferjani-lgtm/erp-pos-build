<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Application\Listeners\ReopenTimeEntryOnWorkOrderResumed;
use App\Modules\Workshop\Technician\Application\Services\TimeEntryService;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\Events\TechnicianTimeEntryClosed;
use App\Modules\Workshop\Technician\Domain\Events\TechnicianTimeEntryStarted;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use App\Modules\Workshop\Technician\Infrastructure\Listeners\CloseTimeEntryOnWorkOrderCompleted;
use App\Modules\Workshop\Technician\Infrastructure\Listeners\CloseTimeEntryOnWorkOrderPaused;
use App\Modules\Workshop\Technician\Infrastructure\Listeners\CreateTimeEntryOnWorkOrderStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Workshop\Technician\Fixtures\StubWorkOrderCompleted;
use Tests\Feature\Workshop\Technician\Fixtures\StubWorkOrderPaused;
use Tests\Feature\Workshop\Technician\Fixtures\StubWorkOrderResumed;
use Tests\Feature\Workshop\Technician\Fixtures\StubWorkOrderStarted;
use Tests\TestCase;

final class TimeEntryEventDrivenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function makeProfile(): TechnicianProfile
    {
        return TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_work_order_started_opens_time_entry(): void
    {
        Event::fake([TechnicianTimeEntryStarted::class]);
        $profile = $this->makeProfile();
        $woId = (string) Str::uuid();
        $startedAt = new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('UTC'));

        $event = new StubWorkOrderStarted($woId, $profile->id, $startedAt);
        $this->app->make(CreateTimeEntryOnWorkOrderStarted::class)->handle($event);

        $this->assertDatabaseHas('workshop_technician_time_entries', [
            'technician_profile_id' => $profile->id,
            'work_order_id' => $woId,
            'ended_at' => null,
            'entry_type' => TimeEntryType::WorkOrder->value,
            'source' => TimeEntrySource::Event->value,
        ]);
        Event::assertDispatched(TechnicianTimeEntryStarted::class);
    }

    public function test_second_open_entry_rejected_by_partial_unique(): void
    {
        $profile = $this->makeProfile();
        $service = $this->app->make(TimeEntryService::class);

        $service->start($profile->id, (string) Str::uuid(), new \DateTimeImmutable('2026-04-20T09:00:00', new \DateTimeZone('UTC')));

        $this->expectException(\Illuminate\Database\QueryException::class);
        $service->start($profile->id, (string) Str::uuid(), new \DateTimeImmutable('2026-04-20T11:00:00', new \DateTimeZone('UTC')));
    }

    public function test_work_order_paused_closes_open_entry(): void
    {
        Event::fake([TechnicianTimeEntryClosed::class]);
        $profile = $this->makeProfile();
        $woId = (string) Str::uuid();
        $startedAt = new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('UTC'));
        $pausedAt = new \DateTimeImmutable('2026-04-20T11:00:00', new \DateTimeZone('UTC'));

        $this->app->make(TimeEntryService::class)->start($profile->id, $woId, $startedAt);

        $event = new StubWorkOrderPaused($woId, 'parts_waiting', $pausedAt);
        $this->app->make(CloseTimeEntryOnWorkOrderPaused::class)->handle($event);

        $entry = TechnicianTimeEntry::query()
            ->where('work_order_id', $woId)
            ->firstOrFail();
        $this->assertNotNull($entry->ended_at);
        $this->assertSame(60, $entry->duration_minutes);
        Event::assertDispatched(TechnicianTimeEntryClosed::class);
    }

    public function test_work_order_completed_closes_and_emits_closed_event(): void
    {
        Event::fake([TechnicianTimeEntryClosed::class]);
        $profile = $this->makeProfile();
        $woId = (string) Str::uuid();
        $startedAt = new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('UTC'));
        $completedAt = new \DateTimeImmutable('2026-04-20T12:30:00', new \DateTimeZone('UTC'));

        $this->app->make(TimeEntryService::class)->start($profile->id, $woId, $startedAt);

        $event = new StubWorkOrderCompleted($woId, 54321, $completedAt);
        $this->app->make(CloseTimeEntryOnWorkOrderCompleted::class)->handle($event);

        $entry = TechnicianTimeEntry::query()->where('work_order_id', $woId)->firstOrFail();
        $this->assertNotNull($entry->ended_at);
        $this->assertSame(150, $entry->duration_minutes);
        Event::assertDispatched(TechnicianTimeEntryClosed::class);
    }

    public function test_work_order_resumed_reopens_most_recently_closed_entry(): void
    {
        $profile = $this->makeProfile();
        $woId = (string) Str::uuid();
        // Older closed entry (yesterday) — should NOT be reopened.
        TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'work_order_id' => $woId,
            'started_at' => new \DateTimeImmutable('2026-04-19T08:00:00', new \DateTimeZone('UTC')),
            'ended_at' => new \DateTimeImmutable('2026-04-19T09:00:00', new \DateTimeZone('UTC')),
            'duration_minutes' => 60,
            'entry_type' => TimeEntryType::WorkOrder->value,
            'source' => TimeEntrySource::Event->value,
        ]);
        // The "most recently closed" entry — should be reopened.
        $mostRecent = TechnicianTimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'technician_profile_id' => $profile->id,
            'work_order_id' => $woId,
            'started_at' => new \DateTimeImmutable('2026-04-20T10:00:00', new \DateTimeZone('UTC')),
            'ended_at' => new \DateTimeImmutable('2026-04-20T11:00:00', new \DateTimeZone('UTC')),
            'duration_minutes' => 60,
            'entry_type' => TimeEntryType::WorkOrder->value,
            'source' => TimeEntrySource::Event->value,
        ]);

        $event = new StubWorkOrderResumed($woId, new \DateTimeImmutable('2026-04-20T11:30:00', new \DateTimeZone('UTC')));
        $this->app->make(ReopenTimeEntryOnWorkOrderResumed::class)->handle($event);

        $mostRecent->refresh();
        $this->assertNull($mostRecent->ended_at, 'Most recently closed entry should have been reopened.');
        $this->assertNull($mostRecent->duration_minutes);

        // Ensure we did NOT insert a new row — the count for this work_order must stay at 2.
        $this->assertSame(
            2,
            TechnicianTimeEntry::query()->where('work_order_id', $woId)->count(),
            'Reopen must not create a new row.',
        );
    }
}
