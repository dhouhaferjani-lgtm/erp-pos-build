<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Fiscal\V3;

use App\Modules\POS\Domain\Services\Fiscal\V3\CanonicalJsonEncoder;
use Tests\TestCase;

final class CanonicalJsonEncoderTest extends TestCase
{
    public function test_encodes_empty_object_as_rf_c_8785(): void
    {
        $encoder = new CanonicalJsonEncoder;
        $this->assertSame('{}', $encoder->encode([]));
    }

    public function test_sorts_keys_lexicographically(): void
    {
        $encoder = new CanonicalJsonEncoder;
        $this->assertSame('{"a":1,"b":2}', $encoder->encode(['b' => 2, 'a' => 1]));
    }

    public function test_serializes_null_as_null_token(): void
    {
        $encoder = new CanonicalJsonEncoder;
        $this->assertSame('{"x":null}', $encoder->encode(['x' => null]));
    }

    public function test_decimal_strings_passthrough_without_quoting(): void
    {
        $encoder = new CanonicalJsonEncoder;
        $this->assertSame('{"amount":"12.345"}', $encoder->encode(['amount' => '12.345']));
    }

    public function test_escapes_unicode_per_rf_c_8785(): void
    {
        $encoder = new CanonicalJsonEncoder;
        $this->assertSame('{"name":"café"}', $encoder->encode(['name' => 'café']));
    }

    public function test_arrays_preserve_order(): void
    {
        $encoder = new CanonicalJsonEncoder;
        $this->assertSame('{"x":[1,2,3]}', $encoder->encode(['x' => [1, 2, 3]]));
    }
}
