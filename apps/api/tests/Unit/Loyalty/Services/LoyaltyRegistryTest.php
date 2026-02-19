<?php

declare(strict_types=1);

namespace Tests\Unit\Loyalty\Services;

use App\Modules\Loyalty\Domain\Services\LoyaltyRegistry;
use PHPUnit\Framework\TestCase;

class LoyaltyRegistryTest extends TestCase
{
    private LoyaltyRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new LoyaltyRegistry;
    }

    public function test_register_entity_type_stores_mapping(): void
    {
        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');

        $this->assertEquals('App\Models\Product', $this->registry->getEntityClass('product'));
    }

    public function test_register_category_type_stores_mapping(): void
    {
        $this->registry->registerCategoryType('product_category', 'App\Models\Category');

        $this->assertEquals('App\Models\Category', $this->registry->getCategoryClass('product_category'));
    }

    public function test_get_entity_class_returns_null_for_unregistered_type(): void
    {
        $result = $this->registry->getEntityClass('nonexistent');

        $this->assertNull($result);
    }

    public function test_get_category_class_returns_null_for_unregistered_type(): void
    {
        $result = $this->registry->getCategoryClass('nonexistent');

        $this->assertNull($result);
    }

    public function test_register_multiple_entity_types(): void
    {
        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');
        $this->registry->registerLoyaltyableType('service', 'App\Models\Service');
        $this->registry->registerLoyaltyableType('package', 'App\Models\Package');

        $this->assertCount(3, $this->registry->getRegisteredEntityTypes());
        $this->assertEquals('App\Models\Product', $this->registry->getEntityClass('product'));
        $this->assertEquals('App\Models\Service', $this->registry->getEntityClass('service'));
        $this->assertEquals('App\Models\Package', $this->registry->getEntityClass('package'));
    }

    public function test_register_multiple_category_types(): void
    {
        $this->registry->registerCategoryType('product_category', 'App\Models\ProductCategory');
        $this->registry->registerCategoryType('service_category', 'App\Models\ServiceCategory');

        $this->assertCount(2, $this->registry->getRegisteredCategoryTypes());
        $this->assertEquals('App\Models\ProductCategory', $this->registry->getCategoryClass('product_category'));
        $this->assertEquals('App\Models\ServiceCategory', $this->registry->getCategoryClass('service_category'));
    }

    public function test_overwriting_entity_type_replaces_existing(): void
    {
        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');
        $this->registry->registerLoyaltyableType('product', 'App\Models\NewProduct');

        $this->assertEquals('App\Models\NewProduct', $this->registry->getEntityClass('product'));
    }

    public function test_overwriting_category_type_replaces_existing(): void
    {
        $this->registry->registerCategoryType('category', 'App\Models\Category');
        $this->registry->registerCategoryType('category', 'App\Models\NewCategory');

        $this->assertEquals('App\Models\NewCategory', $this->registry->getCategoryClass('category'));
    }

    public function test_has_entity_type_returns_true_when_registered(): void
    {
        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');

        $this->assertTrue($this->registry->hasEntityType('product'));
    }

    public function test_has_entity_type_returns_false_when_not_registered(): void
    {
        $this->assertFalse($this->registry->hasEntityType('nonexistent'));
    }

    public function test_has_category_type_returns_true_when_registered(): void
    {
        $this->registry->registerCategoryType('category', 'App\Models\Category');

        $this->assertTrue($this->registry->hasCategoryType('category'));
    }

    public function test_has_category_type_returns_false_when_not_registered(): void
    {
        $this->assertFalse($this->registry->hasCategoryType('nonexistent'));
    }

    public function test_unregister_entity_type_removes_mapping(): void
    {
        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');
        $this->registry->registerLoyaltyableType('service', 'App\Models\Service');

        $this->registry->unregisterEntityType('product');

        $this->assertFalse($this->registry->hasEntityType('product'));
        $this->assertTrue($this->registry->hasEntityType('service'));
    }

    public function test_unregister_category_type_removes_mapping(): void
    {
        $this->registry->registerCategoryType('category1', 'App\Models\Category1');
        $this->registry->registerCategoryType('category2', 'App\Models\Category2');

        $this->registry->unregisterCategoryType('category1');

        $this->assertFalse($this->registry->hasCategoryType('category1'));
        $this->assertTrue($this->registry->hasCategoryType('category2'));
    }

    public function test_clear_removes_all_registrations(): void
    {
        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');
        $this->registry->registerLoyaltyableType('service', 'App\Models\Service');
        $this->registry->registerCategoryType('category', 'App\Models\Category');

        $this->registry->clear();

        $this->assertCount(0, $this->registry->getRegisteredEntityTypes());
        $this->assertCount(0, $this->registry->getRegisteredCategoryTypes());
        $this->assertFalse($this->registry->hasEntityType('product'));
        $this->assertFalse($this->registry->hasCategoryType('category'));
    }

    public function test_entity_type_count_returns_correct_number(): void
    {
        $this->assertEquals(0, $this->registry->entityTypeCount());

        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');
        $this->assertEquals(1, $this->registry->entityTypeCount());

        $this->registry->registerLoyaltyableType('service', 'App\Models\Service');
        $this->assertEquals(2, $this->registry->entityTypeCount());
    }

    public function test_category_type_count_returns_correct_number(): void
    {
        $this->assertEquals(0, $this->registry->categoryTypeCount());

        $this->registry->registerCategoryType('category1', 'App\Models\Category1');
        $this->assertEquals(1, $this->registry->categoryTypeCount());

        $this->registry->registerCategoryType('category2', 'App\Models\Category2');
        $this->assertEquals(2, $this->registry->categoryTypeCount());
    }

    public function test_get_registered_entity_types_returns_all_mappings(): void
    {
        $this->registry->registerLoyaltyableType('product', 'App\Models\Product');
        $this->registry->registerLoyaltyableType('service', 'App\Models\Service');

        $types = $this->registry->getRegisteredEntityTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey('product', $types);
        $this->assertArrayHasKey('service', $types);
        $this->assertEquals('App\Models\Product', $types['product']);
        $this->assertEquals('App\Models\Service', $types['service']);
    }

    public function test_get_registered_category_types_returns_all_mappings(): void
    {
        $this->registry->registerCategoryType('cat1', 'App\Models\Category1');
        $this->registry->registerCategoryType('cat2', 'App\Models\Category2');

        $types = $this->registry->getRegisteredCategoryTypes();

        $this->assertIsArray($types);
        $this->assertArrayHasKey('cat1', $types);
        $this->assertArrayHasKey('cat2', $types);
        $this->assertEquals('App\Models\Category1', $types['cat1']);
        $this->assertEquals('App\Models\Category2', $types['cat2']);
    }

    public function test_registry_maintains_separate_entity_and_category_namespaces(): void
    {
        $this->registry->registerLoyaltyableType('item', 'App\Models\Product');
        $this->registry->registerCategoryType('item', 'App\Models\Category');

        $this->assertEquals('App\Models\Product', $this->registry->getEntityClass('item'));
        $this->assertEquals('App\Models\Category', $this->registry->getCategoryClass('item'));
    }

    public function test_unregister_nonexistent_entity_type_does_not_error(): void
    {
        $this->registry->unregisterEntityType('nonexistent');

        $this->assertFalse($this->registry->hasEntityType('nonexistent'));
    }

    public function test_unregister_nonexistent_category_type_does_not_error(): void
    {
        $this->registry->unregisterCategoryType('nonexistent');

        $this->assertFalse($this->registry->hasCategoryType('nonexistent'));
    }
}
