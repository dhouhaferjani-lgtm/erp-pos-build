<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Application\Queries\FreeSlotsQuery;
use App\Modules\Scheduling\Domain\Appointment;
use App\Modules\Scheduling\Domain\Bay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FreeSlotsQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed a single active bay for the given company, deactivating every other
     * bay spawned as a side effect of the Appointment factory so assertions
     * about per-company free-slot counts remain deterministic.
     */
    private function isolateBay(Bay $bay): void
    {
        Bay::query()
            ->where('company_id', $bay->company_id)
            ->where('id', '!=', $bay->id)
            ->update(['is_active' => false]);
    }

    public function test_returns_free_windows_longer_than_duration(): void
    {
        $bay = Bay::factory()->create([
            'operating_hours' => [
                'mon' => [['start' => '08:00', 'end' => '17:00']],
                'tue' => [],
                'wed' => [],
                'thu' => [],
                'fri' => [],
                'sat' => [],
                'sun' => [],
            ],
        ]);
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 11:00:00'),
        )->create(['company_id' => $bay->company_id]);
        $this->isolateBay($bay);

        /** @var FreeSlotsQuery $query */
        $query = $this->app->make(FreeSlotsQuery::class);

        $slots = $query->find(
            $bay->company_id,
            60,
            new \DateTimeImmutable('2026-05-04 00:00:00'),
            new \DateTimeImmutable('2026-05-05 00:00:00'),
        );

        // Expect 2 slots: [08-10] and [11-17]
        $this->assertCount(2, $slots);
        $this->assertSame('2026-05-04 08:00:00', $slots[0]->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-04 10:00:00', $slots[0]->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-04 11:00:00', $slots[1]->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-04 17:00:00', $slots[1]->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_filters_out_slots_shorter_than_minimum_duration(): void
    {
        $bay = Bay::factory()->create([
            'operating_hours' => [
                'mon' => [['start' => '08:00', 'end' => '17:00']],
                'tue' => [],
                'wed' => [],
                'thu' => [],
                'fri' => [],
                'sat' => [],
                'sun' => [],
            ],
        ]);
        // Book 09:00-10:30 — leaves 08-09 (60 min) free; this should be filtered when threshold is 90.
        Appointment::factory()->onBay(
            $bay->id,
            new \DateTimeImmutable('2026-05-04 09:00:00'),
            new \DateTimeImmutable('2026-05-04 10:30:00'),
        )->create(['company_id' => $bay->company_id]);
        $this->isolateBay($bay);

        /** @var FreeSlotsQuery $query */
        $query = $this->app->make(FreeSlotsQuery::class);

        $slots = $query->find(
            $bay->company_id,
            90,
            new \DateTimeImmutable('2026-05-04 00:00:00'),
            new \DateTimeImmutable('2026-05-05 00:00:00'),
        );

        // Only the afternoon window [10:30-17:00] (390 min) qualifies.
        $this->assertCount(1, $slots);
        $this->assertSame('2026-05-04 10:30:00', $slots[0]->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-04 17:00:00', $slots[0]->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_clips_windows_to_requested_range(): void
    {
        $bay = Bay::factory()->create([
            'operating_hours' => [
                'mon' => [['start' => '08:00', 'end' => '17:00']],
                'tue' => [],
                'wed' => [],
                'thu' => [],
                'fri' => [],
                'sat' => [],
                'sun' => [],
            ],
        ]);
        $this->isolateBay($bay);

        /** @var FreeSlotsQuery $query */
        $query = $this->app->make(FreeSlotsQuery::class);

        $slots = $query->find(
            $bay->company_id,
            60,
            new \DateTimeImmutable('2026-05-04 10:00:00'),
            new \DateTimeImmutable('2026-05-04 14:00:00'),
        );

        $this->assertCount(1, $slots);
        $this->assertSame('2026-05-04 10:00:00', $slots[0]->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-04 14:00:00', $slots[0]->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_rejects_non_positive_duration(): void
    {
        $bay = Bay::factory()->create();
        /** @var FreeSlotsQuery $query */
        $query = $this->app->make(FreeSlotsQuery::class);

        $this->expectException(\InvalidArgumentException::class);
        $query->find(
            $bay->company_id,
            0,
            new \DateTimeImmutable('2026-05-04'),
            new \DateTimeImmutable('2026-05-05'),
        );
    }

    public function test_rejects_inverted_range(): void
    {
        $bay = Bay::factory()->create();
        /** @var FreeSlotsQuery $query */
        $query = $this->app->make(FreeSlotsQuery::class);

        $this->expectException(\InvalidArgumentException::class);
        $query->find(
            $bay->company_id,
            60,
            new \DateTimeImmutable('2026-05-05'),
            new \DateTimeImmutable('2026-05-04'),
        );
    }
}
