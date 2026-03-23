<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use App\Modules\Taxation\Domain\Entities\VatPeriodBreakdown;
use App\Modules\Taxation\Domain\Enums\VatDirection;
use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VatPeriodEntityTest extends TestCase
{
    use RefreshDatabase;

    public function test_vat_period_casts_enums_correctly(): void
    {
        $period = VatPeriod::factory()->create();

        $this->assertInstanceOf(VatPeriodStatus::class, $period->status);
        $this->assertInstanceOf(VatPeriodType::class, $period->period_type);
        $this->assertSame(VatPeriodStatus::Open, $period->status);
        $this->assertSame(VatPeriodType::Monthly, $period->period_type);
    }

    public function test_vat_period_has_breakdowns_relationship(): void
    {
        $period = VatPeriod::factory()->create();

        VatPeriodBreakdown::create([
            'vat_period_id' => $period->id,
            'direction' => VatDirection::Output,
            'tax_rate' => '19.00',
            'base_amount' => '1000.000',
            'vat_amount' => '190.000',
            'document_count' => 5,
            'is_recoverable' => true,
        ]);

        $period->refresh();

        $this->assertCount(1, $period->breakdowns);
        $this->assertInstanceOf(VatPeriodBreakdown::class, $period->breakdowns->first());
        $this->assertSame(VatDirection::Output, $period->breakdowns->first()->direction);
    }

    public function test_vat_period_breakdown_is_immutable(): void
    {
        // Verify the UPDATED_AT constant is explicitly set to null (immutable pattern)
        $reflection = new \ReflectionClass(VatPeriodBreakdown::class);
        $constant = $reflection->getReflectionConstant('UPDATED_AT');
        $this->assertNotFalse($constant);
        $this->assertNull($constant->getValue());
    }

    public function test_vat_period_status_labels(): void
    {
        $this->assertSame('Open', VatPeriodStatus::Open->label());
        $this->assertSame('Closed', VatPeriodStatus::Closed->label());
        $this->assertSame('Filed', VatPeriodStatus::Filed->label());
    }

    public function test_vat_period_type_labels(): void
    {
        $this->assertSame('Monthly', VatPeriodType::Monthly->label());
        $this->assertSame('Quarterly', VatPeriodType::Quarterly->label());
        $this->assertSame('Annual', VatPeriodType::Annual->label());
    }

    public function test_vat_period_is_open_closed_filed(): void
    {
        $open = VatPeriod::factory()->open()->create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
        ]);
        $closed = VatPeriod::factory()->closed()->create([
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
        ]);
        $filed = VatPeriod::factory()->filed()->create([
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        $this->assertTrue($open->isOpen());
        $this->assertFalse($open->isClosed());
        $this->assertFalse($open->isFiled());

        $this->assertFalse($closed->isOpen());
        $this->assertTrue($closed->isClosed());
        $this->assertFalse($closed->isFiled());

        $this->assertFalse($filed->isOpen());
        $this->assertFalse($filed->isClosed());
        $this->assertTrue($filed->isFiled());
    }

    public function test_vat_period_scope_for_company(): void
    {
        $period = VatPeriod::factory()->create();

        $found = VatPeriod::forCompany($period->company_id)->first();
        $this->assertNotNull($found);
        $this->assertSame($period->id, $found->id);

        $notFound = VatPeriod::forCompany('00000000-0000-0000-0000-000000000000')->first();
        $this->assertNull($notFound);
    }

    public function test_vat_period_scope_for_year(): void
    {
        $period = VatPeriod::factory()->create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
        ]);

        $found = VatPeriod::forYear(2026)->first();
        $this->assertNotNull($found);

        $notFound = VatPeriod::forYear(2025)->first();
        $this->assertNull($notFound);
    }

    public function test_vat_period_breakdown_belongs_to_period(): void
    {
        $period = VatPeriod::factory()->create();

        $breakdown = VatPeriodBreakdown::create([
            'vat_period_id' => $period->id,
            'direction' => VatDirection::Input,
            'tax_rate' => '7.00',
            'base_amount' => '500.000',
            'vat_amount' => '35.000',
            'document_count' => 3,
            'is_recoverable' => true,
        ]);

        $this->assertInstanceOf(VatPeriod::class, $breakdown->period);
        $this->assertSame($period->id, $breakdown->period->id);
    }
}
