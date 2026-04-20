<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use PHPUnit\Framework\TestCase;

final class SpecialtyCodeEnumTest extends TestCase
{
    public function test_contains_automotive_core_specialties(): void
    {
        $values = SpecialtyCode::values();
        $this->assertContains('engine_mechanical', $values);
        $this->assertContains('transmission', $values);
        $this->assertContains('electrical', $values);
        $this->assertContains('brakes', $values);
        $this->assertContains('bodywork', $values);
        $this->assertContains('hybrid_ev', $values);
        $this->assertContains('pre_control', $values);
        $this->assertContains('general_service', $values);
    }

    public function test_has_16_cases(): void
    {
        $this->assertCount(16, SpecialtyCode::cases());
    }
}
