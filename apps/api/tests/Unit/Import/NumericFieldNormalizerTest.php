<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Modules\Import\Services\NumericFieldNormalizer;
use PHPUnit\Framework\TestCase;

class NumericFieldNormalizerTest extends TestCase
{
    private NumericFieldNormalizer $normalizer;

    /** @var array<string, array<int, string>> */
    private array $rules = [
        'name' => ['required', 'string'],
        'description' => ['nullable', 'string'],
        'sale_price' => ['nullable', 'numeric', 'min:0'],
        'purchase_price' => ['nullable', 'numeric', 'min:0'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new NumericFieldNormalizer;
    }

    public function test_converts_decimal_comma_on_numeric_fields(): void
    {
        $result = $this->normalizer->normalize(
            ['name' => 'Produit', 'sale_price' => '10,00'],
            $this->rules
        );

        $this->assertSame('10.00', $result['sale_price']);
    }

    public function test_converts_european_thousands_dot_with_decimal_comma(): void
    {
        $result = $this->normalizer->normalize(
            ['sale_price' => '1.234,56'],
            $this->rules
        );

        $this->assertSame('1234.56', $result['sale_price']);
    }

    public function test_converts_us_thousands_comma_with_decimal_dot(): void
    {
        $result = $this->normalizer->normalize(
            ['sale_price' => '1,234.56'],
            $this->rules
        );

        $this->assertSame('1234.56', $result['sale_price']);
    }

    public function test_leaves_plain_decimal_dot_values_unchanged(): void
    {
        $result = $this->normalizer->normalize(
            ['sale_price' => '10.00'],
            $this->rules
        );

        $this->assertSame('10.00', $result['sale_price']);
    }

    public function test_leaves_three_decimal_dot_values_unchanged(): void
    {
        $result = $this->normalizer->normalize(
            ['sale_price' => '7.140'],
            $this->rules
        );

        $this->assertSame('7.140', $result['sale_price']);
    }

    public function test_leaves_percent_scale_dot_decimals_for_precision_validation(): void
    {
        $result = $this->normalizer->normalize(
            ['margin' => '12.555'],
            ['margin' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/']]
        );

        $this->assertSame('12.555', $result['margin']);
    }

    public function test_does_not_touch_non_numeric_fields(): void
    {
        $result = $this->normalizer->normalize(
            ['name' => '1,5 Litre', 'description' => 'contient 1,5g'],
            $this->rules
        );

        $this->assertSame('1,5 Litre', $result['name']);
        $this->assertSame('contient 1,5g', $result['description']);
    }

    public function test_leaves_unparseable_values_unchanged_for_validation_to_reject(): void
    {
        $result = $this->normalizer->normalize(
            ['sale_price' => 'abc', 'purchase_price' => '10,00,00'],
            $this->rules
        );

        $this->assertSame('abc', $result['sale_price']);
        $this->assertSame('10,00,00', $result['purchase_price']);
    }

    public function test_handles_negative_values(): void
    {
        $result = $this->normalizer->normalize(
            ['sale_price' => '-5,25'],
            $this->rules
        );

        $this->assertSame('-5.25', $result['sale_price']);
    }

    public function test_leaves_non_string_values_unchanged(): void
    {
        $result = $this->normalizer->normalize(
            ['sale_price' => 15.5],
            $this->rules
        );

        $this->assertSame(15.5, $result['sale_price']);
    }
}
