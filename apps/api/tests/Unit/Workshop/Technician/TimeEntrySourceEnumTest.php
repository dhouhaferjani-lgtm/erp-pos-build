<?php

declare(strict_types=1);

namespace Tests\Unit\Workshop\Technician;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use PHPUnit\Framework\TestCase;

final class TimeEntrySourceEnumTest extends TestCase
{
    public function test_values(): void
    {
        $this->assertSame(['event', 'manual', 'import'], TimeEntrySource::values());
    }
}
