<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\ProductPlacement;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class ProductPlacementModelTest extends TestCase
{
    public function test_table_and_soft_deletes(): void
    {
        $placement = new ProductPlacement;
        $this->assertSame('product_placements', $placement->getTable());
        $this->assertContains(SoftDeletes::class, class_uses_recursive($placement));
    }

    public function test_node_relation_uses_node_id_fk(): void
    {
        $placement = new ProductPlacement;
        $relation = $placement->node();
        $this->assertSame('node_id', $relation->getForeignKeyName());
        $this->assertInstanceOf(LocationNode::class, $relation->getRelated());
    }

    public function test_in_node_scope_filters_on_node_id(): void
    {
        $query = ProductPlacement::query()->inNode('11111111-1111-1111-1111-111111111111');
        $this->assertStringContainsString('"node_id" = ?', $query->toSql());
        $this->assertContains('11111111-1111-1111-1111-111111111111', $query->getBindings());
    }
}
