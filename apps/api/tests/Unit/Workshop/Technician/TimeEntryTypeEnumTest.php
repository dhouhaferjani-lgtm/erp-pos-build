<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use PHPUnit\Framework\TestCase;

final class TimeEntryTypeEnumTest extends TestCase
{
    public function test_values(): void
    {
        $this->assertSame(
            ['work_order', 'break', 'non_billable', 'manual_adjust'],
            TimeEntryType::values(),
        );
    }
}
