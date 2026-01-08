<?php

declare(strict_types=1);

namespace Tests\Unit\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Enums\ExpiryStatus;
use Tests\TestCase;

class ExpiryStatusTest extends TestCase
{
    public function test_expiry_status_has_correct_colors(): void
    {
        $this->assertEquals('green', ExpiryStatus::OK->color());
        $this->assertEquals('yellow', ExpiryStatus::APPROACHING->color());
        $this->assertEquals('orange', ExpiryStatus::WARNING->color());
        $this->assertEquals('red', ExpiryStatus::CRITICAL->color());
        $this->assertEquals('gray', ExpiryStatus::EXPIRED->color());
    }

    public function test_expired_status_cannot_be_sold(): void
    {
        $this->assertFalse(ExpiryStatus::EXPIRED->canSell());
    }

    public function test_non_expired_statuses_can_be_sold(): void
    {
        $this->assertTrue(ExpiryStatus::OK->canSell());
        $this->assertTrue(ExpiryStatus::APPROACHING->canSell());
        $this->assertTrue(ExpiryStatus::WARNING->canSell());
        $this->assertTrue(ExpiryStatus::CRITICAL->canSell());
    }

    public function test_expiry_status_has_correct_labels(): void
    {
        $this->assertEquals('OK', ExpiryStatus::OK->label());
        $this->assertEquals('Approaching Expiry', ExpiryStatus::APPROACHING->label());
        $this->assertEquals('Warning', ExpiryStatus::WARNING->label());
        $this->assertEquals('Critical', ExpiryStatus::CRITICAL->label());
        $this->assertEquals('Expired', ExpiryStatus::EXPIRED->label());
    }

    public function test_expiry_status_has_correct_thresholds(): void
    {
        $this->assertEquals(90, ExpiryStatus::APPROACHING->daysThreshold());
        $this->assertEquals(30, ExpiryStatus::WARNING->daysThreshold());
        $this->assertEquals(7, ExpiryStatus::CRITICAL->daysThreshold());
        $this->assertNull(ExpiryStatus::OK->daysThreshold());
        $this->assertNull(ExpiryStatus::EXPIRED->daysThreshold());
    }
}
