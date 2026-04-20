<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use PHPUnit\Framework\TestCase;

final class TimeOffReasonEnumTest extends TestCase
{
    public function test_values(): void
    {
        $this->assertSame(
            ['vacation', 'sick', 'training', 'personal', 'unpaid', 'other'],
            TimeOffReason::values(),
        );
    }
}
