<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\NodeCode;
use App\Modules\Inventory\Domain\ProductPlacement;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class LocationNodeModelTest extends TestCase
{
    public function test_code_grammar(): void
    {
        $this->assertTrue(NodeCode::isValid('A1'));
        $this->assertTrue(NodeCode::isValid('R-2.b'));
        $this->assertTrue(NodeCode::isValid('0'));
        $this->assertTrue(NodeCode::isValid(str_repeat('a', 50)));

        $this->assertFalse(NodeCode::isValid('A/1'));   // slash banned (path separator)
        $this->assertFalse(NodeCode::isValid('A%1'));   // LIKE wildcard banned
        $this->assertFalse(NodeCode::isValid('A_1'));   // LIKE wildcard banned
        $this->assertFalse(NodeCode::isValid('A 1'));   // whitespace banned
        $this->assertFalse(NodeCode::isValid('-A1'));   // must start alphanumeric
        $this->assertFalse(NodeCode::isValid(str_repeat('a', 51))); // too long
        $this->assertFalse(NodeCode::isValid(''));
    }

    public function test_node_type_cast(): void
    {
        $node = new LocationNode(['node_type' => 'rack']);
        $this->assertSame(LocationNodeType::Rack, $node->node_type);
    }

    public function test_table_soft_deletes_and_relations(): void
    {
        $node = new LocationNode;
        $this->assertSame('location_nodes', $node->getTable());
        $this->assertContains(SoftDeletes::class, class_uses_recursive($node));

        $this->assertSame('parent_id', $node->parent()->getForeignKeyName());
        $this->assertSame('parent_id', $node->children()->getForeignKeyName());
        $this->assertSame('node_id', $node->productPlacements()->getForeignKeyName());
        $this->assertInstanceOf(ProductPlacement::class, $node->productPlacements()->getRelated());
    }

    public function test_subtree_scope_uses_exact_or_prefix_match(): void
    {
        $query = LocationNode::query()->subtreeOf('A1');
        $sql = $query->toSql();

        // exact match OR '/'-anchored prefix — avoids the A1 vs A10 collision
        $this->assertStringContainsString('"path" = ?', $sql);
        $this->assertStringContainsString('"path"', $sql);
        $this->assertContains('A1', $query->getBindings());
        $this->assertContains('A1/%', $query->getBindings());
        $this->assertNotContains('A1%', $query->getBindings());
    }
}
