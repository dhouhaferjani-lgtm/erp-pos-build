# Location Placement Hierarchy — Phase 1 (Backend Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evolve the flat `location_zones` model into a flexible, arbitrary-depth **location node hierarchy** (labels only) with a safe migration, soft-delete tombstones, a materialized `path`, atomic subtree moves, partial-unique placement writes, and a delta-sync endpoint — the backend foundation the web UI, CSV import, and mobile app build on.

**Architecture:** One self-referencing `location_nodes` table (`parent_id`, `node_type`, `path`, `depth`, `deleted_at`) evolved from `location_zones`; `product_placements` (evolved from `product_zone_assignments`, `zone_id`→`node_id`, `+deleted_at`) keeps `UNIQUE(product_id, location_id) WHERE deleted_at IS NULL`. Nodes carry NO stock. Hexagonal module `App\Modules\Inventory` (Domain / Application / Presentation). Reuses existing `inventory.view`/`inventory.adjust` permissions.

**Tech Stack:** Laravel 12, PHP 8.2 strict types, PostgreSQL 16 (tenant DB, db-per-tenant), Spatie LaravelData DTOs (`#[TypeScript]`), PHPUnit + `RefreshDatabase` + `RolesAndPermissionsSeeder`.

## Global Constraints

- **PHP strict types**, no `mixed` (DTOs), **PHPStan level 8** zero errors on new code, `./vendor/bin/pint` clean.
- **Constructor injection only** (`private readonly`); never `app()`.
- **Enums for all type columns** (`node_type` → `LocationNodeType`).
- **Migrations go in `apps/api/database/migrations/tenant/`** (per-tenant DB). Run via `php artisan tenants:migrate`.
- **Soft-delete only** — no hard deletes in v1; deletes tombstone (`deleted_at`) in a transaction, never via FK cascade.
- **Node `code` grammar:** `^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$` — bans `/ % _` and whitespace (path separator + LIKE wildcards).
- **Partial unique:** `product_placements(product_id, location_id) WHERE deleted_at IS NULL`; `location_nodes(location_id, code) WHERE deleted_at IS NULL`.
- **Canonical subtree query everywhere:** `WHERE (path = :p OR path LIKE :p || '/%' ESCAPE '\')`.
- **Delta contract:** server-issued `sync_high_watermark` + `(updated_at, id)` tuple cursor (Task 13). Timestamps ISO-8601 on the wire.
- **After any DTO change:** run `php artisan typescript:transform` (types are generated, never hand-edited).
- **Tests:** `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`; run tests **by path** (never the full suite without permission — it can crash the machine).
- **`CountingScopeType` value stays `'zone'`** — do not change the enum value; Phase 1 only renames the tables/refs it points at, behavior identical.
- **Prerequisite (hard):** this branch is based on `origin/dev`. The `location_zones`/`product_zone_assignments` tables come from `feat/live-inventory-counting`, which **must be merged to dev first**. Before executing, rebase this branch onto the post-merge dev so the tables exist. If they don't exist yet, STOP — do not proceed.

**Verify command (run after each task):** `cd apps/api && ./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --memory-limit=1G` then the task's PHPUnit path.

---

## File Structure

**Migration (create):**
- `apps/api/database/migrations/tenant/2026_07_07_100001_rename_zones_to_location_nodes.php`

**Domain (rename + modify):**
- `apps/api/app/Modules/Inventory/Domain/Enums/LocationNodeType.php` *(create)*
- `LocationZone.php` → `LocationNode.php` *(git mv + rewrite)*
- `ProductZoneAssignment.php` → `ProductPlacement.php` *(git mv + rewrite)*

**Application (rename + modify):**
- `Services/ZoneService.php` → `Services/LocationNodeService.php` *(git mv + rewrite)*
- `DTOs/ZoneDto.php` → `DTOs/LocationNodeDto.php`; `DTOs/ZoneProductAssignmentDto.php` → `DTOs/ProductPlacementDto.php`

**Presentation (rename + create + modify):**
- `Controllers/ZoneController.php` → `Controllers/LocationNodeController.php` *(rewrite)*
- `Controllers/ProductPlacementController.php` *(create)*
- Requests: `CreateNodeRequest`, `UpdateNodeRequest`, `MoveNodeRequest` (from Create/UpdateZoneRequest); `AssignProductsRequest` (from BulkAssignZoneRequest), `BulkMovePlacementsRequest`, `SetProductPlacementRequest` *(create)*
- `Presentation/routes.php` *(modify the Zones section)*
- Shared: `app/Modules/Inventory/Domain/NodeCode.php` *(create — grammar regex constant + validation helper)*

**Counting reference rename (modify — keep behavior identical):**
- `Presentation/Requests/CreateCountingRequest.php`, `Application/Services/InventoryCountingService.php`, `Application/Services/CountingBlockService.php`

**Tests (create):** under `apps/api/tests/Feature/Inventory/` and `apps/api/tests/Unit/Inventory/`.

---

## Task 1: Migration — rename, columns, backfill, indexes

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_07_100001_rename_zones_to_location_nodes.php`
- Test: `apps/api/tests/Feature/Inventory/LocationNodesMigrationTest.php`

**Interfaces — Produces:** tables `location_nodes` (cols: `id, tenant_id, location_id, parent_id NULL, node_type, code, name, path, depth, sort_order, is_active, deleted_at, timestamps`) and `product_placements` (cols: `id, tenant_id, product_id, location_id, node_id, deleted_at, timestamps`), with partial unique indexes and a `text_pattern_ops` path index.

- [ ] **Step 1: Write the failing test**

```php
<?php // apps/api/tests/Feature/Inventory/LocationNodesMigrationTest.php
declare(strict_types=1);
namespace Tests\Feature\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LocationNodesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_renamed_with_new_columns(): void
    {
        $this->assertTrue(Schema::hasTable('location_nodes'));
        $this->assertFalse(Schema::hasTable('location_zones'));
        foreach (['parent_id','node_type','path','depth','deleted_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('location_nodes', $col), "missing $col");
        }
        $this->assertTrue(Schema::hasTable('product_placements'));
        $this->assertTrue(Schema::hasColumn('product_placements', 'node_id'));
        $this->assertTrue(Schema::hasColumn('product_placements', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('product_placements', 'zone_id'));
    }

    public function test_partial_unique_allows_reassign_after_tombstone(): void
    {
        $loc = $this->seedLocation();            // helper below returns [tenantId, locationId]
        [$tenantId, $locationId] = $loc;
        $nodeA = $this->seedNode($tenantId, $locationId, 'A1');
        $productId = (string) \Illuminate\Support\Str::uuid();
        DB::table('products')->insert($this->productRow($productId, $tenantId)); // minimal FK-satisfying row
        // one live placement
        DB::table('product_placements')->insert($this->placementRow($tenantId, $productId, $locationId, $nodeA));
        // tombstone it
        DB::table('product_placements')->where('product_id',$productId)->update(['deleted_at'=>now()]);
        // a second live placement for same (product,location) must now be allowed
        DB::table('product_placements')->insert($this->placementRow($tenantId, $productId, $locationId, $nodeA));
        $this->assertSame(1, DB::table('product_placements')
            ->where('product_id',$productId)->whereNull('deleted_at')->count());
        // a THIRD live row must violate the partial unique
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('product_placements')->insert($this->placementRow($tenantId, $productId, $locationId, $nodeA));
    }

    // seedLocation(), seedNode(), productRow(), placementRow() — implement minimal FK-valid rows
    // using existing factories where available (LocationFactory) and raw inserts otherwise.
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd apps/api && php artisan test tests/Feature/Inventory/LocationNodesMigrationTest.php`
Expected: FAIL — `location_nodes` does not exist.

- [ ] **Step 3: Write the migration**

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evolve flat location_zones → arbitrary-depth location_nodes (labels only).
 * Additive + backfill, zero data loss. See
 * docs/superpowers/specs/2026-07-07-location-placement-hierarchy-design.md §9.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- location_zones -> location_nodes ---
        Schema::rename('location_zones', 'location_nodes');
        Schema::table('location_nodes', function (\Illuminate\Database\Schema\Blueprint $t): void {
            $t->uuid('parent_id')->nullable()->after('location_id');
            $t->string('node_type', 32)->default('zone')->after('parent_id');
            $t->string('path', 512)->nullable()->after('code');
            $t->smallInteger('depth')->default(0)->after('path');
            $t->softDeletesTz();
            $t->foreign('parent_id')->references('id')->on('location_nodes')->nullOnDelete();
            $t->index('parent_id', 'location_nodes_parent_idx');
        });
        // backfill existing rows as top-level nodes
        DB::statement("UPDATE location_nodes SET node_type='zone', depth=0, path=code WHERE path IS NULL");
        DB::statement('ALTER TABLE location_nodes ALTER COLUMN path SET NOT NULL');
        // swap unique(code) -> partial unique(code) WHERE not deleted
        DB::statement('ALTER TABLE location_nodes DROP CONSTRAINT IF EXISTS location_zones_location_code_unique');
        DB::statement('DROP INDEX IF EXISTS location_zones_location_code_unique');
        DB::statement('CREATE UNIQUE INDEX location_nodes_location_code_live_unique ON location_nodes (location_id, code) WHERE deleted_at IS NULL');
        // path prefix index (text_pattern_ops so LIKE 'x/%' uses it)
        DB::statement('CREATE INDEX location_nodes_location_path_idx ON location_nodes (location_id, path text_pattern_ops)');

        // --- product_zone_assignments -> product_placements ---
        Schema::rename('product_zone_assignments', 'product_placements');
        Schema::table('product_placements', function (\Illuminate\Database\Schema\Blueprint $t): void {
            $t->renameColumn('zone_id', 'node_id');
            $t->softDeletesTz();
        });
        DB::statement('ALTER TABLE product_placements DROP CONSTRAINT IF EXISTS product_zone_assignments_product_location_unique');
        DB::statement('DROP INDEX IF EXISTS product_zone_assignments_product_location_unique');
        DB::statement('CREATE UNIQUE INDEX product_placements_product_location_live_unique ON product_placements (product_id, location_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX product_placements_location_node_live_idx ON product_placements (location_id, node_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX product_placements_delta_idx ON product_placements (location_id, updated_at, id)');
    }

    public function down(): void
    {
        Schema::table('product_placements', function (\Illuminate\Database\Schema\Blueprint $t): void {
            $t->dropSoftDeletesTz();
            $t->renameColumn('node_id', 'zone_id');
        });
        DB::statement('DROP INDEX IF EXISTS product_placements_product_location_live_unique');
        DB::statement('DROP INDEX IF EXISTS product_placements_location_node_live_idx');
        DB::statement('DROP INDEX IF EXISTS product_placements_delta_idx');
        Schema::rename('product_placements', 'product_zone_assignments');

        Schema::table('location_nodes', function (\Illuminate\Database\Schema\Blueprint $t): void {
            $t->dropForeign('location_nodes_parent_idx');
            $t->dropSoftDeletesTz();
            $t->dropColumn(['parent_id','node_type','path','depth']);
        });
        DB::statement('DROP INDEX IF EXISTS location_nodes_location_code_live_unique');
        DB::statement('DROP INDEX IF EXISTS location_nodes_location_path_idx');
        Schema::rename('location_nodes', 'location_zones');
    }
};
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd apps/api && php artisan test tests/Feature/Inventory/LocationNodesMigrationTest.php`
Expected: PASS (both tests). If `renameColumn` fails on the FK, drop/re-add the `node_id` FK explicitly (Postgres keeps the FK across rename, so verify `product_placements_node_id_foreign` still targets `location_nodes`).

- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(inventory): migration renaming zones→location_nodes with hierarchy columns + partial-unique indexes"`

---

## Task 2: `LocationNodeType` enum

**Files:** Create `apps/api/app/Modules/Inventory/Domain/Enums/LocationNodeType.php`; Test `apps/api/tests/Unit/Inventory/LocationNodeTypeTest.php`

**Interfaces — Produces:** `LocationNodeType` backed enum (`zone|aisle|rack|shelf|bin|section`) with `label(): string`.

- [ ] **Step 1: Failing test**

```php
<?php declare(strict_types=1);
namespace Tests\Unit\Inventory;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use Tests\TestCase;
final class LocationNodeTypeTest extends TestCase
{
    public function test_values_and_labels(): void
    {
        $this->assertSame('zone', LocationNodeType::Zone->value);
        $this->assertSame(6, count(LocationNodeType::cases()));
        $this->assertNotSame('', LocationNodeType::Bin->label());
    }
}
```

- [ ] **Step 2: Run — FAIL** (`php artisan test tests/Unit/Inventory/LocationNodeTypeTest.php`)
- [ ] **Step 3: Implement**

```php
<?php declare(strict_types=1);
namespace App\Modules\Inventory\Domain\Enums;
enum LocationNodeType: string
{
    case Zone = 'zone';
    case Aisle = 'aisle';
    case Rack = 'rack';
    case Shelf = 'shelf';
    case Bin = 'bin';
    case Section = 'section';

    public function label(): string
    {
        return match ($this) {
            self::Zone => 'Zone',
            self::Aisle => 'Aisle',
            self::Rack => 'Rack',
            self::Shelf => 'Shelf',
            self::Bin => 'Bin',
            self::Section => 'Section',
        };
    }
}
```

- [ ] **Step 4: Run — PASS**
- [ ] **Step 5: Commit** — `git commit -am "feat(inventory): LocationNodeType enum"`

---

## Task 3: `LocationNode` model + `NodeCode` grammar

**Files:** `git mv` `Domain/LocationZone.php` → `Domain/LocationNode.php` (rewrite); create `Domain/NodeCode.php`; Test `tests/Unit/Inventory/LocationNodeModelTest.php`

**Interfaces — Produces:**
- `LocationNode` model (`SoftDeletes`, `$table='location_nodes'`, fillable +`parent_id,node_type,path,depth`, casts `node_type`→`LocationNodeType`, `depth`→int; relations `parent()`, `children()`, `location()`, `productPlacements()` (FK `node_id`); scopes `forTenant`, `atLocation`, `subtreeOf(string $path)`).
- `NodeCode::PATTERN` (string regex) + `NodeCode::isValid(string): bool`.

- [ ] **Step 1: Failing test**

```php
<?php declare(strict_types=1);
namespace Tests\Unit\Inventory;
use App\Modules\Inventory\Domain\LocationNode;
use App\Modules\Inventory\Domain\NodeCode;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use Tests\TestCase;
final class LocationNodeModelTest extends TestCase
{
    public function test_code_grammar(): void
    {
        $this->assertTrue(NodeCode::isValid('A1'));
        $this->assertTrue(NodeCode::isValid('R-2.b'));
        $this->assertFalse(NodeCode::isValid('A/1'));   // slash banned
        $this->assertFalse(NodeCode::isValid('A%1'));   // like-wildcard banned
        $this->assertFalse(NodeCode::isValid('A_1'));   // underscore banned
        $this->assertFalse(NodeCode::isValid('A 1'));   // space banned
        $this->assertFalse(NodeCode::isValid(''));
    }
    public function test_node_type_cast(): void
    {
        $node = new LocationNode(['node_type' => 'rack']);
        $this->assertSame(LocationNodeType::Rack, $node->node_type);
    }
}
```

- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement `NodeCode`**

```php
<?php declare(strict_types=1);
namespace App\Modules\Inventory\Domain;
final class NodeCode
{
    /** Path-safe + LIKE-safe: bans '/', '%', '_', whitespace. */
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.\-]{0,49}$/';
    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }
}
```

- [ ] **Step 4: Implement `LocationNode`** (rewrite the renamed file): `use SoftDeletes;` `use HasUuids;` `$table='location_nodes'`; fillable = `['tenant_id','location_id','parent_id','node_type','name','code','path','depth','sort_order','is_active']`; `casts()` returns `['node_type'=>LocationNodeType::class,'sort_order'=>'integer','depth'=>'integer','is_active'=>'boolean']`; keep `tenant()/location()`; add `parent()` (`belongsTo(self::class,'parent_id')`), `children()` (`hasMany(self::class,'parent_id')`), rename `productAssignments()`→`productPlacements()` (`hasMany(ProductPlacement::class,'node_id')`); keep `scopeForTenant/scopeAtLocation`; add:

```php
/** @param Builder<static> $q @return Builder<static> */
public function scopeSubtreeOf(Builder $q, string $path): Builder
{
    return $q->where(function (Builder $w) use ($path): void {
        $w->where('path', $path)->orWhere('path', 'like', $path.'/%');
    });
}
```

- [ ] **Step 5: Run — PASS**; **Commit** — `git commit -am "feat(inventory): LocationNode model + NodeCode grammar"`

---

## Task 4: `ProductPlacement` model

**Files:** `git mv` `Domain/ProductZoneAssignment.php` → `Domain/ProductPlacement.php`; Test `tests/Unit/Inventory/ProductPlacementModelTest.php`

**Interfaces — Produces:** `ProductPlacement` (`SoftDeletes`, `HasUuids`, `$table='product_placements'`, fillable `['tenant_id','product_id','location_id','node_id']`; relations `tenant()`, `product()`, `location()`, `node()` (`belongsTo(LocationNode::class,'node_id')`); scopes `forTenant`, `inNode(string $nodeId)` on `node_id`).

- [ ] **Step 1: Failing test** — assert `(new ProductPlacement)->getTable() === 'product_placements'` and `node()` relation FK is `node_id`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** — mirror the old model with `use SoftDeletes;`, `node_id`, `node()` relation, `scopeInNode`.
- [ ] **Step 4: Run — PASS**; **Commit** — `git commit -am "feat(inventory): ProductPlacement model"`

---

## Task 5: `LocationNodeService` — create + path recompute

**Files:** `git mv` `Application/Services/ZoneService.php` → `Application/Services/LocationNodeService.php` (rewrite); Test `tests/Feature/Inventory/LocationNodeServiceCreateTest.php`

**Interfaces — Produces:**
- `createNode(string $tenantId, string $locationId, ?string $parentId, LocationNodeType $type, string $name, string $code, int $sortOrder = 0, bool $isActive = true): LocationNode` — computes `path` (`parent.path.'/'.code` or `code` at root) and `depth`; validates parent is same location.
- `updateNode(LocationNode $node, array $attributes): LocationNode` — if `code` changes, recompute `path`/`depth` for node **and subtree** (delegates to `recomputeSubtreePath`).
- `recomputeSubtreePath(LocationNode $node): void` — single prefix-replacement UPDATE.

**Consumes:** `LocationNode`, `LocationNodeType`.

- [ ] **Step 1: Failing test**

```php
public function test_create_computes_path_and_depth(): void
{
    [$tenantId, $locationId] = $this->seedLocation();
    $svc = app(\App\Modules\Inventory\Application\Services\LocationNodeService::class);
    $a = $svc->createNode($tenantId,$locationId,null,LocationNodeType::Aisle,'Aisle 1','A1');
    $r = $svc->createNode($tenantId,$locationId,$a->id,LocationNodeType::Rack,'Rack 2','R2');
    $b = $svc->createNode($tenantId,$locationId,$r->id,LocationNodeType::Bin,'Bin 7','B7');
    $this->assertSame('A1', $a->path);      $this->assertSame(0, $a->depth);
    $this->assertSame('A1/R2', $r->path);   $this->assertSame(1, $r->depth);
    $this->assertSame('A1/R2/B7', $b->path);$this->assertSame(2, $b->depth);
}
public function test_code_change_recomputes_subtree(): void { /* rename A1->AX; assert R2/B7 paths become AX/R2, AX/R2/B7 */ }
```

- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** (key methods; wrap mutations in `DB::transaction`):

```php
public function createNode(string $tenantId, string $locationId, ?string $parentId,
    LocationNodeType $type, string $name, string $code, int $sortOrder = 0, bool $isActive = true): LocationNode
{
    return DB::transaction(function () use ($tenantId,$locationId,$parentId,$type,$name,$code,$sortOrder,$isActive): LocationNode {
        $parent = null;
        if ($parentId !== null) {
            /** @var LocationNode $parent */
            $parent = LocationNode::query()->where('location_id',$locationId)->lockForUpdate()->findOrFail($parentId);
        }
        $path  = $parent ? $parent->path.'/'.$code : $code;
        $depth = $parent ? $parent->depth + 1 : 0;
        return LocationNode::create([
            'tenant_id'=>$tenantId,'location_id'=>$locationId,'parent_id'=>$parentId,
            'node_type'=>$type->value,'name'=>$name,'code'=>$code,'path'=>$path,'depth'=>$depth,
            'sort_order'=>$sortOrder,'is_active'=>$isActive,
        ]);
    });
}

public function recomputeSubtreePath(LocationNode $node): void
{
    $parent = $node->parent_id ? LocationNode::query()->find($node->parent_id) : null;
    $newPath = $parent ? $parent->path.'/'.$node->code : $node->code;
    $oldPath = $node->getOriginal('path');
    if ($oldPath === $newPath) { return; }
    // node itself
    $node->forceFill(['path'=>$newPath,'depth'=>$parent ? $parent->depth+1 : 0])->save();
    // whole subtree, single UPDATE: replace old prefix with new prefix, recompute depth from '/' count
    DB::update(
        "UPDATE location_nodes
            SET path = ? || substr(path, ?),
                depth = length(? || substr(path, ?)) - length(replace(? || substr(path, ?), '/', ''))
          WHERE location_id = ? AND path LIKE ?",
        [$newPath, strlen($oldPath)+1, $newPath, strlen($oldPath)+1, $newPath, strlen($oldPath)+1,
         $node->location_id, $oldPath.'/%']
    );
}
```

`updateNode`: `fill` attributes; if `isDirty('code')` call `recomputeSubtreePath` after save; return `$node->refresh()`.

- [ ] **Step 4: Run — PASS**
- [ ] **Step 5: Commit** — `git commit -am "feat(inventory): LocationNodeService create + subtree path recompute"`

---

## Task 6: `LocationNodeService::moveNode` — atomic reparent + cycle guard

**Files:** modify `LocationNodeService.php`; Test `tests/Feature/Inventory/LocationNodeMoveTest.php`

**Interfaces — Produces:** `moveNode(LocationNode $node, ?string $newParentId): LocationNode` — locks node + new parent, rejects (a) cross-location parent and (b) new parent inside the moving subtree (cycle), then updates `parent_id` and recomputes subtree path/depth.

- [ ] **Step 1: Failing test** — build `A1/R2/B7`; `moveNode(R2, null)` ⇒ `R2` path `R2`, `B7` path `R2/B7`. And `moveNode(A1, B7->id)` throws `InvalidArgumentException` (cycle). And moving to a parent in another location throws.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement**

```php
public function moveNode(LocationNode $node, ?string $newParentId): LocationNode
{
    return DB::transaction(function () use ($node, $newParentId): LocationNode {
        /** @var LocationNode $node */
        $node = LocationNode::query()->lockForUpdate()->findOrFail($node->id);
        $newParent = null;
        if ($newParentId !== null) {
            /** @var LocationNode $newParent */
            $newParent = LocationNode::query()->lockForUpdate()->findOrFail($newParentId);
            if ($newParent->location_id !== $node->location_id) {
                throw new InvalidArgumentException('Parent must be in the same location.');
            }
            // cycle: new parent is the node itself or inside its subtree
            if ($newParent->id === $node->id
                || $newParent->path === $node->path
                || str_starts_with($newParent->path, $node->path.'/')) {
                throw new InvalidArgumentException('Cannot move a node under its own descendant.');
            }
        }
        $node->parent_id = $newParentId;
        $node->save();
        $this->recomputeSubtreePath($node);
        return $node->refresh();
    });
}
```

- [ ] **Step 4: Run — PASS**; **Commit** — `git commit -am "feat(inventory): atomic node move with cycle guard"`

---

## Task 7: Soft-delete subtree + restore

**Files:** modify `LocationNodeService.php`; Test `tests/Feature/Inventory/LocationNodeDeleteTest.php`

**Interfaces — Produces:** `softDeleteSubtree(LocationNode $node, bool $force = false): void` (refuses if live placements exist under the subtree unless `$force`, then tombstones nodes **and** their placements in one transaction — NOT FK cascade); `restoreNode(LocationNode $node): void` (fails if code now collides with a live sibling).

- [ ] **Step 1: Failing test** — subtree `A1/R2/B7`, place a product in `B7`; `softDeleteSubtree(A1)` without force throws; with `force:true` ⇒ all three nodes + the placement have `deleted_at`; a `location_nodes` live query excludes them; `restoreNode(A1)` un-tombstones A1 (children stay deleted — restore is per-node).
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** — inside `DB::transaction`: collect subtree ids via `subtreeOf($node->path)`; count live placements `whereIn('node_id',$ids)->whereNull('deleted_at')`; if >0 and !force throw `InvalidArgumentException`; else `LocationNode::whereIn('id',$ids)->update(['deleted_at'=>now()])` and `ProductPlacement::whereIn('node_id',$ids)->whereNull('deleted_at')->update(['deleted_at'=>now()])`. `restoreNode`: guard live `(location_id,code)` uniqueness, then `$node->restore()`.
- [ ] **Step 4: Run — PASS**; **Commit** — `git commit -am "feat(inventory): transactional soft-delete subtree + restore"`

---

## Task 8: Placement writes — assign / unassign / bulk-move / list

**Files:** modify `LocationNodeService.php` (or a sibling `PlacementService.php` — keep in `LocationNodeService` to avoid a second service; boundary is one aggregate); Test `tests/Feature/Inventory/PlacementWriteTest.php`

**Interfaces — Produces:**
- `assignProduct(string $tenantId, string $productId, string $locationId, string $nodeId): ProductPlacement` — validates node∈location; **locks by (product,location)**; updates the live row if present else inserts (respecting partial unique).
- `unassignProduct(string $productId, string $locationId): void` — tombstones the live row.
- `bulkMove(string $tenantId, array $productIds, string $targetNodeId): void`.
- `listNodeProducts(string $nodeId, ?string $search, int $perPage): LengthAwarePaginator`.

- [ ] **Step 1: Failing tests** — (a) assign then reassign to another node ⇒ still exactly ONE live row, `node_id` updated; (b) unassign ⇒ live count 0, one tombstone; (c) assign again after unassign ⇒ live count 1 (new or restored row), no unique violation; (d) assign to a node in another location throws.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement**

```php
public function assignProduct(string $tenantId, string $productId, string $locationId, string $nodeId): ProductPlacement
{
    return DB::transaction(function () use ($tenantId,$productId,$locationId,$nodeId): ProductPlacement {
        /** @var LocationNode $node */
        $node = LocationNode::query()->findOrFail($nodeId);
        if ($node->location_id !== $locationId) {
            throw new InvalidArgumentException("Node {$nodeId} is not in location {$locationId}.");
        }
        /** @var ?ProductPlacement $live */
        $live = ProductPlacement::query()
            ->where('product_id',$productId)->where('location_id',$locationId)
            ->whereNull('deleted_at')->lockForUpdate()->first();
        if ($live !== null) { $live->update(['node_id'=>$nodeId]); return $live->refresh(); }
        return ProductPlacement::create([
            'tenant_id'=>$tenantId,'product_id'=>$productId,'location_id'=>$locationId,'node_id'=>$nodeId,
        ]);
    });
}

public function unassignProduct(string $productId, string $locationId): void
{
    ProductPlacement::query()->where('product_id',$productId)->where('location_id',$locationId)
        ->whereNull('deleted_at')->update(['deleted_at'=>now()]);
}
```

`bulkMove`: resolve target node once (validate location), loop `assignProduct`. `listNodeProducts`: `ProductPlacement::inNode($nodeId)->whereNull('deleted_at')->with('product')->when($search, fn($q)=>$q->whereHas('product', fn($p)=>$p->where('name','ilike',"%$search%")->orWhere('sku','ilike',"%$search%")))->paginate($perPage)`.

- [ ] **Step 4: Run — PASS**; **Commit** — `git commit -am "feat(inventory): placement assign/unassign/bulk-move with partial-unique-safe writes"`

---

## Task 9: DTOs + request rules

**Files:** rename `ZoneDto`→`LocationNodeDto`, `ZoneProductAssignmentDto`→`ProductPlacementDto`; create requests `CreateNodeRequest`, `UpdateNodeRequest`, `MoveNodeRequest`, `AssignProductsRequest`, `BulkMovePlacementsRequest`, `SetProductPlacementRequest`; Test `tests/Feature/Inventory/NodeRequestValidationTest.php`

**Interfaces — Produces:**
- `LocationNodeDto` fields: `id, location_id, parent_id (?string), node_type (string), name, code, path, depth (int), sort_order (int), is_active (bool), created_at, updated_at` + `fromModel`.
- `ProductPlacementDto`: `id, product_id, product_name, product_sku, location_id, node_id, created_at, updated_at` + `fromModel`.
- `CreateNodeRequest` rules: `location_id` (uuid + `ScopedExists::company('locations',$companyId)`), `parent_id` nullable uuid `ScopedExists` on `location_nodes` same location, `node_type` `Rule::enum(LocationNodeType::class)`, `name` required max:255, `code` required max:50 + `regex:NodeCode::PATTERN` + partial-unique `Rule::unique('location_nodes','code')->where(location_id)->whereNull('deleted_at')`, `sort_order` sometimes int min:0, `is_active` sometimes bool.

- [ ] **Step 1: Failing test** — POST create with `code:'A/1'` ⇒ 422 (regex); with duplicate live code ⇒ 422; valid ⇒ 201 and DTO has `path`.
- [ ] **Step 2: Run — FAIL** (endpoints not wired yet → expect after Task 10; write the request-unit assertions first using `$request->rules()` validation via `Validator::make`).
- [ ] **Step 3: Implement DTOs + requests** (mirror `CreateZoneRequest`; add `regex` + `whereNull('deleted_at')` to the unique rule; `Rule::enum` for node_type).
- [ ] **Step 4: Run — PASS**; regen types: `php artisan typescript:transform`; **Commit** — `git commit -am "feat(inventory): node/placement DTOs + validated requests (code grammar + partial-unique)"`

---

## Task 10: `LocationNodeController` + routes (tree CRUD, move, delete, restore)

**Files:** rewrite `Controllers/LocationNodeController.php`; modify `Presentation/routes.php`; Test `tests/Feature/Inventory/LocationNodeApiTest.php`

**Interfaces — Produces endpoints** (all `->whereUuid` on id params, `can:` middleware):
`GET /inventory/locations/{location}/nodes` (view), `POST /inventory/nodes` (adjust), `PATCH /inventory/nodes/{node}` (adjust), `POST /inventory/nodes/{node}/move` (adjust), `DELETE /inventory/nodes/{node}` (adjust, `?force`), `POST /inventory/nodes/{node}/restore` (adjust). Reuse the `resolveNodeForCompany` pattern (tenant + `whereHas('location', company)`).

- [ ] **Step 1: Failing feature test** — authenticated seeded user (`RolesAndPermissionsSeeder`, owner) creates a location, creates node tree via API, lists tree (asserts `path`/`parent_id` present), moves a node, soft-deletes with force, restores. Assert 403 without `inventory.adjust`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** controller actions (constructor-inject `CompanyContext` + `LocationNodeService`), returning `LocationNodeDto`; add routes mirroring the existing Zones block but renamed; keep the old zone route NAMES only if the FE still references them — otherwise remove (the FE is Phase 2, so it's safe to replace here since counting uses the service, not these HTTP routes).
- [ ] **Step 4: Run — PASS**; **Commit** — `git commit -am "feat(inventory): LocationNode HTTP API (tree CRUD, move, delete, restore)"`

---

## Task 11: `ProductPlacementController` + routes

**Files:** create `Controllers/ProductPlacementController.php`; modify `routes.php`; Test `tests/Feature/Inventory/PlacementApiTest.php`

**Interfaces — Produces:** `GET /inventory/nodes/{node}/products` (view, paginated `{data,meta}` + `search`), `POST /inventory/nodes/{node}/assign-products` (adjust), `DELETE /inventory/nodes/{node}/products/{product}` (adjust), `POST /inventory/placements/bulk-move` (adjust), `GET /inventory/products/{product}/placements` (view), `PUT /inventory/products/{product}/placements` (adjust).

- [ ] **Step 1: Failing feature test** — assign 3 products to a node, list paginated + search, unassign one, bulk-move two to another node, get a product's placements. Assert cross-location assign → 422 `PLACEMENT_LOCATION_MISMATCH`.
- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement** controller + routes; paginated list returns `{data:[...],meta:{...}}` (FE uses `api.get` for these, per repo API-response convention).
- [ ] **Step 4: Run — PASS**; **Commit** — `git commit -am "feat(inventory): product placement HTTP API"`

---

## Task 12: Delta-sync endpoint (tuple cursor + high-water-mark)

**Files:** modify `ProductPlacementController.php` (add `delta`); `routes.php`; Test `tests/Feature/Inventory/PlacementDeltaTest.php`

**Interfaces — Produces:** `GET /inventory/placements?location_id=&cursor=&limit=` (view) → `{ data:[{...,deleted_at}], next_cursor:{updated_at,id}|null, sync_high_watermark:iso8601 }`. Query per §4.4 of the spec.

- [ ] **Step 1: Failing test — boundary + tombstones**

```php
public function test_delta_catches_boundary_row_and_tombstones(): void
{
    // seed placements with controlled updated_at; page with limit=2.
    // 1) first page returns sync_high_watermark and next_cursor; loop pages until next_cursor null.
    // 2) a row updated_at == prior sync_high_watermark is NOT lost: on the NEXT run
    //    (cursor = last tuple), it appears because filter is (updated_at,id) > cursor.
    // 3) an unassigned (tombstoned) row appears in delta with deleted_at set.
    // Assert no duplicates across pages, stable (updated_at,id) order.
}
```

- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implement**

```php
public function delta(Request $request): JsonResponse
{
    $company = $this->companyContext->requireCompany();
    $locationId = (string) $request->query('location_id','');
    abort_unless(Str::isUuid($locationId), 422);
    $limit = min(1000, max(1, (int) $request->query('limit', 500)));
    $cursor = $request->query('cursor');           // "iso8601|uuid" or null
    $hwm = $request->query('sync_high_watermark')  // carried across pages of a run
        ?: now()->toIso8601String();

    $q = ProductPlacement::query()
        ->withTrashed()
        ->where('tenant_id', $company->tenant_id)
        ->where('location_id', $locationId)
        ->where('updated_at', '<=', $hwm)
        ->orderBy('updated_at')->orderBy('id');

    if (is_string($cursor) && str_contains($cursor, '|')) {
        [$cTs,$cId] = explode('|', $cursor, 2);
        $q->where(fn ($w) => $w->where('updated_at','>',$cTs)
            ->orWhere(fn ($x) => $x->where('updated_at',$cTs)->where('id','>',$cId)));
    }

    $rows = $q->limit($limit + 1)->get();
    $hasMore = $rows->count() > $limit;
    $page = $rows->take($limit);
    $last = $page->last();
    $next = $hasMore && $last
        ? ['updated_at'=>$last->updated_at?->toIso8601String(),'id'=>$last->id]
        : null;

    return response()->json([
        'data' => $page->map(fn (ProductPlacement $p) => ProductPlacementDto::fromModel($p))->values(),
        'next_cursor' => $next,
        'sync_high_watermark' => $hwm,
    ]);
}
```

- [ ] **Step 4: Run — PASS**; **Commit** — `git commit -am "feat(inventory): placement delta endpoint (tuple cursor + high-water-mark + tombstones)"`

---

## Task 13: Counting reference rename (behavior identical)

**Files:** modify `Presentation/Requests/CreateCountingRequest.php`, `Application/Services/InventoryCountingService.php`, `Application/Services/CountingBlockService.php`; run existing counting tests.

**Interfaces — Consumes:** renamed models/tables. **Produces:** counting still passes with flat single-node behavior (subtree/variant expansion is Phase 5).

- [ ] **Step 1: Establish red** — run the existing counting suite to confirm it currently references old symbols and now breaks after the rename:
  Run: `cd apps/api && php artisan test tests/Feature/Inventory --filter=Counting`
  Expected: FAIL (class `LocationZone`/`ZoneService` / table `location_zones` not found).
- [ ] **Step 2: Update references** — replace `LocationZone`→`LocationNode`, `ProductZoneAssignment`→`ProductPlacement`, `ZoneService`→`LocationNodeService`, `'location_zones'`→`'location_nodes'`, `'zone_id'`→`'node_id'` in the three files; where a placement query previously read all zone assignments, add `->whereNull('deleted_at')`. Do NOT change scope semantics (still one node, no subtree expansion yet). Keep `CountingScopeType::Zone = 'zone'` untouched.
- [ ] **Step 3: Run — PASS** (`php artisan test tests/Feature/Inventory --filter=Counting`). Fix any missed reference until green.
- [ ] **Step 4: Full inventory-module check** — `php artisan test tests/Feature/Inventory tests/Unit/Inventory` (module-scoped, NOT the whole suite).
- [ ] **Step 5: Commit** — `git commit -am "refactor(inventory): migrate counting references to LocationNode/ProductPlacement (behavior identical)"`

---

## Task 14: Preflight + type regen gate

- [ ] **Step 1:** `cd apps/api && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=1G` → zero errors on new code.
- [ ] **Step 2:** `php artisan typescript:transform` → confirm `LocationNodeDto`/`ProductPlacementDto` appear in `packages/shared/types/generated.d.ts` and `ZoneDto`/`ZoneProductAssignmentDto` are gone.
- [ ] **Step 3:** `cd apps/web && pnpm typecheck` → passes (no dangling `ZoneDto` import; Phase 2 will build the UI, but existing counting-wizard import of `listZones` may need a temporary shim or is deferred to Phase 2 — note in the commit).
- [ ] **Step 4: Commit** — `git commit -am "chore(inventory): regen types + preflight for location nodes phase 1"`

---

## Self-Review notes (author)
- **Spec coverage:** §3 (model) → T1–T4; §3.2 write algorithm → T8; §4.1 tree API → T10; §4.2 path atomicity → T5/T6; §4.3 placement API → T11; §4.4 delta → T12; §4.5 counting refs → T13; §9 migration → T1; indexes §3.3 → T1. Web UI (§5), CSV (§6), counting subtree/variant (§7/§4.5 enhancement), mobile finalize (§8) are **later phases** — not in this plan.
- **Deferred to Phase 5 explicitly:** subtree-aware seeding + variant expansion + node-tree picker. Task 13 only does the mechanical rename so nothing breaks.
- **Type consistency:** service method names used across tasks — `createNode`, `updateNode`, `moveNode`, `recomputeSubtreePath`, `softDeleteSubtree`, `restoreNode`, `assignProduct`, `unassignProduct`, `bulkMove`, `listNodeProducts` — are stable throughout.
- **Open risk to watch in review:** `renameColumn('zone_id','node_id')` interaction with the existing FK name (`product_zone_assignments_zone_id_foreign`); verify or drop/re-add the FK explicitly (Task 1 Step 4 note).
