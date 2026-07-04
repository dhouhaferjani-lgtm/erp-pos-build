<?php
declare(strict_types=1);
namespace Tests\Unit\Product;
use App\Modules\Product\Presentation\Requests\Concerns\ValidatesMarginBand;
use Tests\TestCase;

class ValidatesMarginBandTest extends TestCase
{
    use ValidatesMarginBand;

    public function test_detects_inversion(): void
    {
        $this->assertTrue($this->marginBandInverts('30.00', '20.00'));   // min>max
        $this->assertFalse($this->marginBandInverts('10.00', '20.00'));
        $this->assertFalse($this->marginBandInverts(null, '20.00'));     // partial -> no inversion
    }
}
