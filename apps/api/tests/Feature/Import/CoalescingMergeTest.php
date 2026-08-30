<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Import\Services\CoalescingAttributeMerger;
use PHPUnit\Framework\TestCase;

final class CoalescingMergeTest extends TestCase
{
    public function test_blank_source_cells_preserve_existing_product_and_partner_attributes(): void
    {
        $merger = new CoalescingAttributeMerger;
        $existing = [
            'purchase_price' => '12.000',
            'barcode' => '123',
            'unit' => 'kg',
            'description' => 'Existing description',
            'type' => 'service',
            'email' => 'buyer@example.com',
            'phone' => '+21600000000',
            'street_address' => '1 Existing Street',
        ];
        $incoming = array_fill_keys(array_keys($existing), null);

        $this->assertSame($existing, $merger->merge($existing, $incoming, []));
    }

    public function test_non_blank_governing_cells_override_and_can_clear_derived_tax_configuration(): void
    {
        $merger = new CoalescingAttributeMerger;

        $merged = $merger->merge(
            [
                'tax_rate' => '7.00',
                'default_tax_configuration_id' => 'old-config',
                'category_id' => 'old-category',
                'sale_price' => '10.000',
            ],
            [
                'tax_rate' => '19.00',
                'default_tax_configuration_id' => null,
                'category_id' => 'new-category',
                'sale_price' => '12.000',
            ],
            ['tax_rate', 'category_name', 'purchase_price'],
        );

        $this->assertSame('19.00', $merged['tax_rate']);
        $this->assertNull($merged['default_tax_configuration_id']);
        $this->assertSame('new-category', $merged['category_id']);
        $this->assertSame('12.000', $merged['sale_price']);
    }

    public function test_blank_type_on_create_is_not_a_merger_concern(): void
    {
        $merger = new CoalescingAttributeMerger;

        $this->assertSame(
            ['type' => 'part'],
            $merger->merge([], ['type' => 'part'], ['type']),
        );
    }
}
