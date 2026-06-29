<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Enums;

use App\Modules\POS\Domain\Enums\ReturnLineDisposition;
use Tests\TestCase;

final class ReturnLineDispositionTest extends TestCase
{
    public function test_cases_and_values(): void
    {
        $this->assertSame('restock', ReturnLineDisposition::Restock->value);
        $this->assertSame('scrap', ReturnLineDisposition::Scrap->value);
        $this->assertSame('not_received', ReturnLineDisposition::NotReceived->value);
        $this->assertSame(['restock', 'scrap', 'not_received'], ReturnLineDisposition::values());
    }
}
