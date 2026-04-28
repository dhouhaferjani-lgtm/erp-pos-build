<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use App\Modules\Document\Domain\DocumentLine;
use PHPUnit\Framework\TestCase;

final class DocumentLineModelTest extends TestCase
{
    public function test_designation_default_snapshot_is_fillable(): void
    {
        $line = new DocumentLine;

        $this->assertContains('designation_default_snapshot', $line->getFillable());
    }

    public function test_designation_default_snapshot_round_trips_through_fill(): void
    {
        $line = new DocumentLine;

        $line->fill(['designation_default_snapshot' => 'Original Product Name']);

        $this->assertSame('Original Product Name', $line->designation_default_snapshot);
    }

    public function test_designation_default_snapshot_accepts_null(): void
    {
        $line = new DocumentLine;

        $line->fill(['designation_default_snapshot' => null]);

        $this->assertNull($line->designation_default_snapshot);
    }
}
