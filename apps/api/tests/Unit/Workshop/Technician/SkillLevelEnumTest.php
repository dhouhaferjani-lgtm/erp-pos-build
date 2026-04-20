<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\Enums\SkillLevel;
use PHPUnit\Framework\TestCase;

final class SkillLevelEnumTest extends TestCase
{
    public function test_all_cases_defined(): void
    {
        $expected = ['apprentice', 'junior', 'general', 'senior', 'master', 'specialist'];
        $this->assertSame($expected, SkillLevel::values());
    }

    public function test_from_string(): void
    {
        $this->assertSame(SkillLevel::Apprentice, SkillLevel::from('apprentice'));
        $this->assertSame(SkillLevel::Specialist, SkillLevel::from('specialist'));
    }

    public function test_try_from_unknown_returns_null(): void
    {
        /** @var non-empty-string $unknown */
        $unknown = 'expert';
        $result = SkillLevel::tryFrom($unknown);
        $this->assertNull($result);
    }
}
