<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use App\Modules\Workshop\Technician\Domain\Enums\SkillLevel;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Tests\TestCase;

final class TechnicianProfileModelTest extends TestCase
{
    public function test_table_and_fillable(): void
    {
        $profile = new TechnicianProfile;
        $this->assertSame('workshop_technician_profiles', $profile->getTable());
        $this->assertContains('skill_level', $profile->getFillable());
        $this->assertContains('specialties', $profile->getFillable());
        $this->assertContains('weekly_schedule', $profile->getFillable());
        $this->assertContains('national_id', $profile->getFillable());
        $this->assertContains('personal_phone', $profile->getFillable());
    }

    public function test_casts_include_enum_casts(): void
    {
        $profile = new TechnicianProfile;
        $casts = $profile->getCasts();
        $this->assertSame(SkillLevel::class, $casts['skill_level']);
        $this->assertSame(EmploymentStatus::class, $casts['employment_status']);
        $this->assertSame('array', $casts['weekly_schedule']);
        // AsEnumCollection cast is a class-string with parameter, check it starts with the class.
        $this->assertStringContainsString('AsEnumCollection', $casts['specialties']);
        $this->assertStringContainsString(SpecialtyCode::class, $casts['specialties']);
    }
}
