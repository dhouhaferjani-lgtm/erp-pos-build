<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CanonicalCoaSerializerGoldenTest extends TestCase
{
    private const GOLDEN = "{\"code\":\"70\",\"name\":\"Produits d’exploitation\",\"type\":\"revenue\",\"parent_code\":null,\"system_purpose\":null,\"is_system\":true,\"sort_order\":10}\n{\"code\":\"706\",\"name\":\"Ventes de cafés\",\"type\":\"revenue\",\"parent_code\":\"70\",\"system_purpose\":\"product_revenue\",\"is_system\":true,\"sort_order\":20}";

    private const GOLDEN_SHA256 = 'b93206e4fe2e416c36152f70c6684fd4c95a9865cbf5f1e46b4b13a9e100eb36';

    public function test_canonical_bytes_and_hash_match_the_independent_golden(): void
    {
        // Production break caught: field order, row order, parent resolution, NFC, or excluded fields drift.
        $rows = [
            [
                'id' => 22,
                'code' => '706',
                'name' => "Ventes de cafe\u{301}s",
                'type' => 'revenue',
                'parent_id' => 11,
                'system_purpose' => 'product_revenue',
                'is_system' => true,
                'is_active' => false,
                'sort_order' => 20,
            ],
            [
                'id' => 11,
                'code' => '70',
                'name' => 'Produits d’exploitation',
                'type' => 'revenue',
                'parent_id' => null,
                'system_purpose' => null,
                'is_system' => true,
                'is_active' => true,
                'sort_order' => 10,
            ],
        ];

        $serializer = new CanonicalCoaSerializer;

        self::assertSame(self::GOLDEN, $serializer->serialize($rows));
        self::assertSame(self::GOLDEN_SHA256, $serializer->hash($rows));
        self::assertStringNotContainsString('is_active', $serializer->serialize($rows));
        self::assertFalse(str_ends_with($serializer->serialize($rows), "\n"));
    }

    public function test_template_parent_code_representation_uses_the_same_projection(): void
    {
        // Production break caught: template rows require a second serializer or emit a different parent representation.
        $serializer = new CanonicalCoaSerializer;

        self::assertSame(
            '{"code":"706","name":"Ventes","type":"revenue","parent_code":"70","system_purpose":null,"is_system":false,"sort_order":1}',
            $serializer->serialize([[
                'code' => '706',
                'name' => 'Ventes',
                'type' => 'revenue',
                'parent_code' => '70',
                'system_purpose' => null,
                'is_system' => false,
                'sort_order' => 1,
            ]]),
        );
    }

    public function test_duplicate_sort_orders_are_rejected(): void
    {
        // Production break caught: canonical row ordering becomes ambiguous.
        $this->expectException(InvalidArgumentException::class);

        (new CanonicalCoaSerializer)->serialize([
            ['code' => '1', 'name' => 'One', 'type' => 'asset', 'parent_code' => null, 'system_purpose' => null, 'is_system' => false, 'sort_order' => 1],
            ['code' => '2', 'name' => 'Two', 'type' => 'asset', 'parent_code' => null, 'system_purpose' => null, 'is_system' => false, 'sort_order' => 1],
        ]);
    }

    public function test_legacy_definition_insertion_order_is_adapted_to_one_based_sort_order(): void
    {
        $legacyRows = [
            ['code' => '20', 'name' => 'Second by code', 'type' => 'asset', 'parent_code' => null, 'system_purpose' => null, 'is_system' => false],
            ['code' => '10', 'name' => 'First by code', 'type' => 'asset', 'parent_code' => null, 'system_purpose' => null, 'is_system' => true],
        ];

        $adapted = (new CanonicalCoaSerializer)->withLegacyInsertionOrder($legacyRows);

        self::assertSame(1, $adapted[0]['sort_order']);
        self::assertSame(2, $adapted[1]['sort_order']);
        self::assertSame(
            "{\"code\":\"20\",\"name\":\"Second by code\",\"type\":\"asset\",\"parent_code\":null,\"system_purpose\":null,\"is_system\":false,\"sort_order\":1}\n"
            .'{"code":"10","name":"First by code","type":"asset","parent_code":null,"system_purpose":null,"is_system":true,"sort_order":2}',
            (new CanonicalCoaSerializer)->serialize($adapted),
        );
    }

    public function test_template_activation_state_cannot_change_canonical_content(): void
    {
        $row = [
            'code' => '10',
            'name' => 'Cash',
            'type' => 'asset',
            'parent_code' => null,
            'system_purpose' => 'cash',
            'is_system' => true,
            'sort_order' => 1,
        ];
        $serializer = new CanonicalCoaSerializer;

        self::assertSame(
            $serializer->serialize([['is_active' => false, ...$row]]),
            $serializer->serialize([['is_active' => true, ...$row]]),
        );
        self::assertStringNotContainsString('is_active', $serializer->serialize([['is_active' => false, ...$row]]));
    }
}
