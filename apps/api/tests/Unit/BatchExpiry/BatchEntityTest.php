<?php

declare(strict_types=1);

namespace Tests\Unit\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Enums\ExpiryStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchEntityTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_is_expired_when_expiry_date_is_past(): void
    {
        $batch = new Batch([
            'expiry_date' => Carbon::yesterday(),
        ]);

        $this->assertTrue($batch->isExpired());
    }

    public function test_batch_is_not_expired_when_expiry_date_is_future(): void
    {
        $batch = new Batch([
            'expiry_date' => Carbon::tomorrow(),
        ]);

        $this->assertFalse($batch->isExpired());
    }

    public function test_days_until_expiry_calculates_correctly(): void
    {
        $now = Carbon::parse('2025-01-01 00:00:00');
        Carbon::setTestNow($now);

        $batch = new Batch([
            'expiry_date' => $now->copy()->addDays(10),
        ]);

        $this->assertEquals(10, $batch->daysUntilExpiry());

        Carbon::setTestNow();
    }

    public function test_days_until_expiry_is_negative_for_expired_batch(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->subDays(5),
        ]);

        $this->assertLessThan(0, $batch->daysUntilExpiry());
    }

    public function test_expiry_status_is_ok_when_more_than_90_days_remaining(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->addDays(100),
        ]);

        $this->assertEquals(ExpiryStatus::OK, $batch->expiryStatus());
    }

    public function test_expiry_status_is_approaching_when_90_days_or_less(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->addDays(60),
        ]);

        $this->assertEquals(ExpiryStatus::APPROACHING, $batch->expiryStatus());
    }

    public function test_expiry_status_is_warning_when_30_days_or_less(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->addDays(20),
        ]);

        $this->assertEquals(ExpiryStatus::WARNING, $batch->expiryStatus());
    }

    public function test_expiry_status_is_critical_when_7_days_or_less(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->addDays(5),
        ]);

        $this->assertEquals(ExpiryStatus::CRITICAL, $batch->expiryStatus());
    }

    public function test_expiry_status_is_expired_when_past_expiry_date(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->subDay(),
        ]);

        $this->assertEquals(ExpiryStatus::EXPIRED, $batch->expiryStatus());
    }

    public function test_batch_cannot_be_sold_when_inactive(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->addDays(100),
            'is_active' => false,
            'is_recalled' => false,
        ]);

        $this->assertFalse($batch->canBeSold());
    }

    public function test_batch_cannot_be_sold_when_recalled(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->addDays(100),
            'is_active' => true,
            'is_recalled' => true,
        ]);

        $this->assertFalse($batch->canBeSold());
    }

    public function test_batch_cannot_be_sold_when_expired(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->subDay(),
            'is_active' => true,
            'is_recalled' => false,
        ]);

        $this->assertFalse($batch->canBeSold());
    }

    public function test_batch_can_be_sold_when_active_not_recalled_and_not_expired(): void
    {
        $batch = new Batch([
            'expiry_date' => now()->addDays(100),
            'is_active' => true,
            'is_recalled' => false,
        ]);

        $this->assertTrue($batch->canBeSold());
    }
}
