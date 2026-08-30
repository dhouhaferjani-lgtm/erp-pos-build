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

    public function test_provided_sale_price_excl_tax_updates_sale_price_and_preserves_blank_purchase_price(): void
    {
        $merged = (new CoalescingAttributeMerger)->merge(
            ['sale_price' => '4.165', 'purchase_price' => '2.000'],
            ['sale_price' => '4.462', 'purchase_price' => null],
            ['sale_price_excl_tax'],
        );

        $this->assertSame('4.462', $merged['sale_price']);
        $this->assertSame('2.000', $merged['purchase_price']);
    }

    public function test_provided_sale_price_incl_tax_updates_sale_price_and_preserves_blank_purchase_price(): void
    {
        $merged = (new CoalescingAttributeMerger)->merge(
            ['sale_price' => '4.165', 'purchase_price' => '2.000'],
            ['sale_price' => '4.900', 'purchase_price' => null],
            ['sale_price_incl_tax'],
        );

        $this->assertSame('4.900', $merged['sale_price']);
        $this->assertSame('2.000', $merged['purchase_price']);
    }

    public function test_blank_sale_price_authority_cells_leave_prices_unchanged(): void
    {
        $existing = ['sale_price' => '4.165', 'purchase_price' => '2.000'];

        $this->assertSame(
            $existing,
            (new CoalescingAttributeMerger)->merge(
                $existing,
                ['sale_price' => null, 'purchase_price' => null],
                [],
            ),
        );
    }

    public function test_null_sale_price_from_a_derived_arm_never_overwrites_existing_ttc(): void
    {
        $merged = (new CoalescingAttributeMerger)->merge(
            ['sale_price' => '11.900', 'purchase_price' => '8.000'],
            ['sale_price' => null, 'purchase_price' => '9.000'],
            ['purchase_price'],
        );

        $this->assertSame('11.900', $merged['sale_price']);
        $this->assertSame('9.000', $merged['purchase_price']);
    }
}
