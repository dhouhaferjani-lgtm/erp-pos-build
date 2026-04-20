<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use PHPUnit\Framework\TestCase;

final class EmploymentStatusEnumTest extends TestCase
{
    public function test_values(): void
    {
        $this->assertSame(['active', 'on_leave', 'terminated'], EmploymentStatus::values());
    }
}
