# F&B Boss - Database Migration Specifications

**Version:** 1.0
**Date:** January 2026
**Status:** Ready for Implementation
**Target Database:** PostgreSQL 16+

---

## Overview

This document contains complete SQL specifications for all database migrations required for the F&B Boss vertical. Migrations are organized by phase and numbered sequentially.

**Total Migrations:** 13
**Estimated Execution Time:** ~5 minutes (on empty database)

---

## Migration Execution Order

Execute migrations in this exact order to satisfy foreign key dependencies:

| # | File | Description | Phase | Dependencies |
|---|------|-------------|-------|--------------|
| 1 | `2026_01_10_100000_add_product_type_to_products.php` | Add type discriminator to products | 1 | products table exists |
| 2 | `2026_01_10_100001_create_recipes_table.php` | Recipe header table | 1 | products table |
| 3 | `2026_01_10_100002_create_recipe_lines_table.php` | Recipe ingredient lines | 1 | recipes table |
| 4 | `2026_01_10_100003_create_modifier_groups_table.php` | Modifier group definitions | 1 | tenants, companies |
| 5 | `2026_01_10_100004_create_modifier_options_table.php` | Individual modifier options | 1 | modifier_groups |
| 6 | `2026_01_10_100005_create_menu_item_sizes_table.php` | Size variants | 1 | products |
| 7 | `2026_01_10_100006_create_menu_item_modifier_groups_table.php` | Menu item ↔ modifier pivot | 1 | products, modifier_groups |
| 8 | `2026_01_10_100007_add_modifiers_to_pos_receipt_lines.php` | Receipt line modifier storage | 2 | pos_receipt_lines |
| 9 | `2026_01_10_100008_add_consumption_mode_to_pos_receipts.php` | Consumption mode tracking | 2 | pos_receipts |
| 10 | `2026_01_15_100000_add_voucher_fields_to_payment_methods.php` | Restaurant voucher support | 3 | payment_methods |
| 11 | `2026_01_15_100001_create_voucher_usage_tracking_table.php` | Daily voucher usage tracking | 3 | payment_methods |
| 12 | `2026_01_15_100002_add_label_overrides_to_tenants.php` | Tenant label configuration | 3 | tenants |
| 13 | `2026_01_15_100003_add_loyalty_to_pos_receipts.php` | Link receipts to loyalty transactions | 4 | pos_receipts, loyalty_transactions |

---

## Phase 1 Migrations

### Migration 1: Add Product Type Discriminator

**File:** `apps/api/database/migrations/2026_01_10_100000_add_product_type_to_products.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Type discriminator
            $table->string('product_type', 20)
                ->default('standard')
                ->after('id');

            // F&B specific columns
            $table->string('unit_of_measure', 20)->nullable()->after('sku');
            $table->boolean('is_sellable')->default(true)->after('unit_of_measure');
            $table->decimal('cost_price', 12, 2)->nullable()->after('sell_price');
            $table->boolean('is_perishable')->default(false)->after('cost_price');
            $table->integer('reorder_point')->nullable()->after('is_perishable');
        });

        // Add index for type filtering
        Schema::table('products', function (Blueprint $table) {
            $table->index('product_type', 'idx_products_type');
        });

        // Add check constraint for valid types
        DB::statement("
            ALTER TABLE products
            ADD CONSTRAINT products_type_check
            CHECK (product_type IN ('standard', 'ingredient', 'menu_item', 'service'))
        ");

        // Add comments
        DB::statement("
            COMMENT ON COLUMN products.product_type IS
            'Discriminator: standard (retail product), ingredient (F&B raw material), menu_item (F&B sellable), service (automotive service)'
        ");

        DB::statement("
            COMMENT ON COLUMN products.unit_of_measure IS
            'Base unit for ingredients: g, ml, kg, l, pc (pieces)'
        ");

        DB::statement("
            COMMENT ON COLUMN products.is_sellable IS
            'Whether this product/ingredient can be sold directly (some ingredients are not sellable)'
        ");

        DB::statement("
            COMMENT ON COLUMN products.cost_price IS
            'Purchase cost per unit (for COGS calculation)'
        ");

        DB::statement("
            COMMENT ON COLUMN products.reorder_point IS
            'Low stock alert threshold (triggers reorder notification)'
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE products DROP CONSTRAINT IF EXISTS products_type_check");

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_type');
            $table->dropColumn([
                'product_type',
                'unit_of_measure',
                'is_sellable',
                'cost_price',
                'is_perishable',
                'reorder_point',
            ]);
        });
    }
};
```

---

### Migration 2: Create Recipes Table

**File:** `apps/api/database/migrations/2026_01_10_100001_create_recipes_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Multi-tenancy
            $table->uuid('tenant_id');
            $table->uuid('company_id');

            // Menu Item Reference
            $table->uuid('menu_item_id');

            // Recipe Metadata
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('version')->default(1);

            // Yield Information
            $table->decimal('base_yield', 10, 3)->default(1.0);
            $table->string('yield_unit', 20)->nullable();

            // Costing
            $table->decimal('theoretical_cost', 12, 2)->nullable();
            $table->decimal('target_cost_percentage', 5, 2)->nullable();

            // Lifecycle
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->uuid('created_by')->nullable();

            // Foreign Keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('menu_item_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

            // Constraints
            $table->unique(['menu_item_id', 'version'], 'recipes_unique_menu_item_version');
        });

        // Indexes
        Schema::table('recipes', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_recipes_tenant');
            $table->index('company_id', 'idx_recipes_company');
            $table->index('menu_item_id', 'idx_recipes_menu_item');
            $table->index('is_active', 'idx_recipes_active')->where('is_active', true);
        });

        // Check constraints
        DB::statement("
            ALTER TABLE recipes
            ADD CONSTRAINT recipes_yield_positive
            CHECK (base_yield > 0)
        ");

        // Comments
        DB::statement("
            COMMENT ON TABLE recipes IS
            'Recipe definitions linking menu items to their ingredient composition'
        ");

        DB::statement("
            COMMENT ON COLUMN recipes.theoretical_cost IS
            'Calculated sum of (ingredient_cost × quantity) for all recipe lines'
        ");

        DB::statement("
            COMMENT ON COLUMN recipes.version IS
            'Recipe version number for change tracking (v1, v2, etc.)'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
```

---

### Migration 3: Create Recipe Lines Table

**File:** `apps/api/database/migrations/2026_01_10_100002_create_recipe_lines_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_lines', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Recipe Reference
            $table->uuid('recipe_id');

            // Ingredient Reference
            $table->uuid('ingredient_id');

            // Quantity
            $table->decimal('quantity', 10, 3);
            $table->string('unit', 20);

            // Sorting
            $table->integer('sort_order')->default(0);

            // Optional Ingredient (tied to modifier)
            $table->boolean('is_optional')->default(false);
            $table->uuid('linked_modifier_option_id')->nullable();

            // Cost Snapshot
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('line_cost', 12, 2)->nullable();

            // Notes
            $table->text('preparation_note')->nullable();

            // Timestamps
            $table->timestamp('created_at')->useCurrent();

            // Foreign Keys
            $table->foreign('recipe_id')
                ->references('id')->on('recipes')
                ->onDelete('cascade');

            $table->foreign('ingredient_id')
                ->references('id')->on('products')
                ->onDelete('restrict');

            $table->foreign('linked_modifier_option_id')
                ->references('id')->on('modifier_options')
                ->onDelete('set null');

            // Constraints
            $table->unique(['recipe_id', 'ingredient_id'], 'recipe_lines_unique_ingredient');
        });

        // Indexes
        Schema::table('recipe_lines', function (Blueprint $table) {
            $table->index('recipe_id', 'idx_recipe_lines_recipe');
            $table->index('ingredient_id', 'idx_recipe_lines_ingredient');
        });

        // Check constraints
        DB::statement("
            ALTER TABLE recipe_lines
            ADD CONSTRAINT recipe_lines_quantity_positive
            CHECK (quantity > 0)
        ");

        // Comments
        DB::statement("
            COMMENT ON TABLE recipe_lines IS
            'Individual ingredient quantities for each recipe'
        ");

        DB::statement("
            COMMENT ON COLUMN recipe_lines.is_optional IS
            'True if ingredient is added by modifier selection (e.g., extra shot)'
        ");

        DB::statement("
            COMMENT ON COLUMN recipe_lines.preparation_note IS
            'Preparation instructions for this ingredient (e.g., \"Finely ground\", \"Steamed to 65°C\")'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
    }
};
```

---

### Migration 4: Create Modifier Groups Table

**File:** `apps/api/database/migrations/2026_01_10_100003_create_modifier_groups_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Multi-tenancy
            $table->uuid('tenant_id');
            $table->uuid('company_id');

            // Group Identity
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Selection Rules
            $table->boolean('is_required')->default(false);
            $table->integer('min_selections')->default(0);
            $table->integer('max_selections')->default(1);
            $table->string('selection_type', 20);

            // Display
            $table->integer('sort_order')->default(0);
            $table->string('display_style', 20)->nullable();

            // Lifecycle
            $table->boolean('is_active')->default(true);

            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // Foreign Keys
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');

            // Constraints
            $table->unique(['tenant_id', 'company_id', 'name'], 'modifier_groups_unique_name');
        });

        // Indexes
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_modifier_groups_tenant');
            $table->index('company_id', 'idx_modifier_groups_company');
            $table->index('is_active', 'idx_modifier_groups_active')->where('is_active', true);
        });

        // Check constraints
        DB::statement("
            ALTER TABLE modifier_groups
            ADD CONSTRAINT modifier_groups_selection_type_check
            CHECK (selection_type IN ('SINGLE', 'MULTIPLE'))
        ");

        DB::statement("
            ALTER TABLE modifier_groups
            ADD CONSTRAINT modifier_groups_selection_logic
            CHECK (min_selections <= max_selections)
        ");

        // Comments
        DB::statement("
            COMMENT ON TABLE modifier_groups IS
            'Customization categories for menu items (e.g., Milk Type, Sugar Level)'
        ");

        DB::statement("
            COMMENT ON COLUMN modifier_groups.selection_type IS
            'SINGLE (radio button, one choice) or MULTIPLE (checkbox, multiple choices)'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_groups');
    }
};
```

---

### Migration 5: Create Modifier Options Table

**File:** `apps/api/database/migrations/2026_01_10_100004_create_modifier_options_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_options', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Modifier Group Reference
            $table->uuid('modifier_group_id');

            // Option Identity
            $table->string('name', 100);
            $table->text('description')->nullable();

            // Pricing
            $table->decimal('price_adjustment', 12, 2)->default(0.00);

            // Ingredient Effects
            $table->string('ingredient_effect_type', 20)->nullable();
            $table->uuid('ingredient_id')->nullable();
            $table->decimal('ingredient_quantity', 10, 3)->nullable();
            $table->uuid('substitute_ingredient_id')->nullable();

            // Display
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);

            // Availability
            $table->boolean('is_available')->default(true);
            $table->text('unavailable_reason')->nullable();

            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // Foreign Keys
            $table->foreign('modifier_group_id')
                ->references('id')->on('modifier_groups')
                ->onDelete('cascade');

            $table->foreign('ingredient_id')
                ->references('id')->on('products')
                ->onDelete('set null');

            $table->foreign('substitute_ingredient_id')
                ->references('id')->on('products')
                ->onDelete('set null');

            // Constraints
            $table->unique(['modifier_group_id', 'name'], 'modifier_options_unique_name');
        });

        // Indexes
        Schema::table('modifier_options', function (Blueprint $table) {
            $table->index('modifier_group_id', 'idx_modifier_options_group');
            $table->index('ingredient_id', 'idx_modifier_options_ingredient')
                ->where('ingredient_id', '!=', null);
        });

        // Check constraints
        DB::statement("
            ALTER TABLE modifier_options
            ADD CONSTRAINT modifier_options_effect_type_check
            CHECK (ingredient_effect_type IN ('ADD', 'REMOVE', 'SUBSTITUTE', NULL))
        ");

        DB::statement("
            ALTER TABLE modifier_options
            ADD CONSTRAINT modifier_options_substitute_logic
            CHECK (
                (ingredient_effect_type != 'SUBSTITUTE') OR
                (substitute_ingredient_id IS NOT NULL)
            )
        ");

        // Comments
        DB::statement("
            COMMENT ON TABLE modifier_options IS
            'Individual modifier choices within a group'
        ");

        DB::statement("
            COMMENT ON COLUMN modifier_options.ingredient_effect_type IS
            'How this modifier affects recipe: ADD (extra ingredient), REMOVE (exclude ingredient), SUBSTITUTE (replace ingredient)'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_options');
    }
};
```

---

### Migration 6: Create Menu Item Sizes Table

**File:** `apps/api/database/migrations/2026_01_10_100005_create_menu_item_sizes_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_sizes', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Menu Item Reference
            $table->uuid('menu_item_id');

            // Size Identity
            $table->string('name', 50);
            $table->string('code', 10)->nullable();

            // Pricing
            $table->decimal('price_adjustment', 12, 2)->default(0.00);

            // Recipe Scaling
            $table->decimal('recipe_multiplier', 5, 2)->default(1.0);

            // Display
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);

            // Availability
            $table->boolean('is_available')->default(true);

            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // Foreign Keys
            $table->foreign('menu_item_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            // Constraints
            $table->unique(['menu_item_id', 'name'], 'menu_item_sizes_unique_name');
        });

        // Indexes
        Schema::table('menu_item_sizes', function (Blueprint $table) {
            $table->index('menu_item_id', 'idx_menu_item_sizes_menu_item');
        });

        // Check constraints
        DB::statement("
            ALTER TABLE menu_item_sizes
            ADD CONSTRAINT menu_item_sizes_multiplier_positive
            CHECK (recipe_multiplier > 0)
        ");

        // Comments
        DB::statement("
            COMMENT ON TABLE menu_item_sizes IS
            'Size variants for menu items (Small, Medium, Large)'
        ");

        DB::statement("
            COMMENT ON COLUMN menu_item_sizes.recipe_multiplier IS
            'Multiplier applied to recipe ingredient quantities (1.0 = base, 1.5 = 50% more)'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_sizes');
    }
};
```

---

### Migration 7: Create Menu Item Modifier Groups Pivot Table

**File:** `apps/api/database/migrations/2026_01_10_100006_create_menu_item_modifier_groups_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_modifier_groups', function (Blueprint $table) {
            // Composite Primary Key
            $table->uuid('menu_item_id');
            $table->uuid('modifier_group_id');

            // Ordering
            $table->integer('sort_order')->default(0);

            // Timestamps
            $table->timestamp('created_at')->useCurrent();

            // Foreign Keys
            $table->foreign('menu_item_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->foreign('modifier_group_id')
                ->references('id')->on('modifier_groups')
                ->onDelete('cascade');

            // Primary Key
            $table->primary(['menu_item_id', 'modifier_group_id'], 'menu_item_modifiers_pk');
        });

        // Indexes
        Schema::table('menu_item_modifier_groups', function (Blueprint $table) {
            $table->index('menu_item_id', 'idx_menu_item_modifiers_menu_item');
            $table->index('modifier_group_id', 'idx_menu_item_modifiers_modifier_group');
        });

        // Comments
        DB::statement("
            COMMENT ON TABLE menu_item_modifier_groups IS
            'Many-to-many relationship between menu items and modifier groups'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_modifier_groups');
    }
};
```

---

## Phase 2 Migrations

### Migration 8: Add Modifiers to POS Receipt Lines

**File:** `apps/api/database/migrations/2026_01_10_100007_add_modifiers_to_pos_receipt_lines.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            // Size reference
            $table->uuid('selected_size_id')->nullable()->after('product_id');

            // Modifiers (JSONB)
            $table->jsonb('selected_modifiers')->nullable()->after('selected_size_id');

            // Foreign Key
            $table->foreign('selected_size_id')
                ->references('id')->on('menu_item_sizes')
                ->onDelete('set null');
        });

        // JSONB index for modifier queries
        DB::statement("
            CREATE INDEX idx_pos_receipt_lines_modifiers
            ON pos_receipt_lines USING gin(selected_modifiers)
        ");

        // Comments
        DB::statement("
            COMMENT ON COLUMN pos_receipt_lines.selected_modifiers IS
            'JSON array of selected modifiers: [{\"group_id\": \"...\", \"option_id\": \"...\", \"name\": \"Oat Milk\", \"price\": 0.50}]'
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS idx_pos_receipt_lines_modifiers");

        Schema::table('pos_receipt_lines', function (Blueprint $table) {
            $table->dropForeign(['selected_size_id']);
            $table->dropColumn(['selected_size_id', 'selected_modifiers']);
        });
    }
};
```

---

### Migration 9: Add Consumption Mode to POS Receipts

**File:** `apps/api/database/migrations/2026_01_10_100008_add_consumption_mode_to_pos_receipts.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->string('consumption_mode', 20)->nullable()->after('customer_identifier');
        });

        // Add check constraint
        DB::statement("
            ALTER TABLE pos_receipts
            ADD CONSTRAINT pos_receipts_consumption_mode_check
            CHECK (consumption_mode IN ('SUR_PLACE', 'A_EMPORTER', NULL))
        ");

        // Index for filtering
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->index('consumption_mode', 'idx_pos_receipts_consumption_mode');
        });

        // Comments
        DB::statement("
            COMMENT ON COLUMN pos_receipts.consumption_mode IS
            'F&B consumption mode: SUR_PLACE (dine-in) or A_EMPORTER (takeaway). Affects VAT in some countries (e.g., France)'
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pos_receipts DROP CONSTRAINT IF EXISTS pos_receipts_consumption_mode_check");

        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropIndex('idx_pos_receipts_consumption_mode');
            $table->dropColumn('consumption_mode');
        });
    }
};
```

---

## Phase 3 Migrations

### Migration 10: Add Voucher Fields to Payment Methods

**File:** `apps/api/database/migrations/2026_01_15_100000_add_voucher_fields_to_payment_methods.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('voucher_provider', 50)->nullable()->after('is_active');
            $table->decimal('voucher_daily_limit', 12, 2)->nullable()->after('voucher_provider');
            $table->boolean('voucher_working_days_only')->default(false)->after('voucher_daily_limit');
            $table->jsonb('voucher_eligible_categories')->nullable()->after('voucher_working_days_only');
        });

        // JSONB index
        DB::statement("
            CREATE INDEX idx_payment_methods_voucher_categories
            ON payment_methods USING gin(voucher_eligible_categories)
        ");

        // Comments
        DB::statement("
            COMMENT ON COLUMN payment_methods.voucher_provider IS
            'Restaurant voucher provider: SWILE, EDENRED, UP, etc.'
        ");

        DB::statement("
            COMMENT ON COLUMN payment_methods.voucher_daily_limit IS
            'Maximum voucher usage per day (e.g., €25 in France)'
        ");

        DB::statement("
            COMMENT ON COLUMN payment_methods.voucher_eligible_categories IS
            'JSON array of product/menu category IDs eligible for voucher payment'
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS idx_payment_methods_voucher_categories");

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn([
                'voucher_provider',
                'voucher_daily_limit',
                'voucher_working_days_only',
                'voucher_eligible_categories',
            ]);
        });
    }
};
```

---

### Migration 11: Create Voucher Usage Tracking Table

**File:** `apps/api/database/migrations/2026_01_15_100001_create_voucher_usage_tracking_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_usage_tracking', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Voucher Details
            $table->string('voucher_serial', 50);
            $table->uuid('payment_method_id');

            // Usage
            $table->date('usage_date');
            $table->decimal('amount', 12, 2);

            // Receipt Reference
            $table->uuid('receipt_id');

            // Timestamps
            $table->timestamp('created_at')->useCurrent();

            // Foreign Keys
            $table->foreign('payment_method_id')
                ->references('id')->on('payment_methods')
                ->onDelete('cascade');

            $table->foreign('receipt_id')
                ->references('id')->on('pos_receipts')
                ->onDelete('cascade');

            // Indexes
            $table->index(['voucher_serial', 'usage_date'], 'idx_voucher_usage_serial_date');
            $table->index('usage_date', 'idx_voucher_usage_date');
        });

        // Comments
        DB::statement("
            COMMENT ON TABLE voucher_usage_tracking IS
            'Tracks daily voucher usage to enforce daily limits'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_usage_tracking');
    }
};
```

---

### Migration 12: Add Label Overrides to Tenants

**File:** `apps/api/database/migrations/2026_01_15_100002_add_label_overrides_to_tenants.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->jsonb('label_overrides')->nullable()->after('settings');
        });

        // JSONB index
        DB::statement("
            CREATE INDEX idx_tenants_label_overrides
            ON tenants USING gin(label_overrides)
        ");

        // Comments
        DB::statement("
            COMMENT ON COLUMN tenants.label_overrides IS
            'Tenant-specific UI label customization: {\"product\": {\"singular\": \"Ingredient\", \"plural\": \"Ingredients\"}}'
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS idx_tenants_label_overrides");

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('label_overrides');
        });
    }
};
```

---

## Phase 4 Migrations

### Migration 13: Add Loyalty to POS Receipts

**File:** `apps/api/database/migrations/2026_01_15_100003_add_loyalty_to_pos_receipts.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->uuid('loyalty_transaction_id')->nullable()->after('notes');

            // Foreign Key (assuming loyalty_transactions table exists)
            $table->foreign('loyalty_transaction_id')
                ->references('id')->on('loyalty_transactions')
                ->onDelete('set null');
        });

        // Index
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->index('loyalty_transaction_id', 'idx_pos_receipts_loyalty_txn');
        });

        // Comments
        DB::statement("
            COMMENT ON COLUMN pos_receipts.loyalty_transaction_id IS
            'Link to loyalty transaction (points earned, stamps awarded, rewards redeemed)'
        ");
    }

    public function down(): void
    {
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->dropForeign(['loyalty_transaction_id']);
            $table->dropIndex('idx_pos_receipts_loyalty_txn');
            $table->dropColumn('loyalty_transaction_id');
        });
    }
};
```

---

## Migration Testing

### Test Each Migration Up/Down

```bash
# Test individual migration
php artisan migrate --path=database/migrations/2026_01_10_100000_add_product_type_to_products.php
php artisan migrate:rollback --step=1

# Test all F&B migrations
php artisan migrate --path=database/migrations --from=2026_01_10_100000

# Rollback all F&B migrations
php artisan migrate:rollback --step=13
```

---

### Verify Data Integrity

```sql
-- Check products with type
SELECT product_type, COUNT(*) FROM products GROUP BY product_type;

-- Check recipes with lines
SELECT
    r.name,
    COUNT(rl.id) as ingredient_count,
    r.theoretical_cost
FROM recipes r
LEFT JOIN recipe_lines rl ON rl.recipe_id = r.id
GROUP BY r.id;

-- Check modifier groups with options
SELECT
    mg.name,
    COUNT(mo.id) as option_count
FROM modifier_groups mg
LEFT JOIN modifier_options mo ON mo.modifier_group_id = mg.id
GROUP BY mg.id;

-- Check menu items with modifiers
SELECT
    p.name,
    COUNT(DISTINCT mimgmodifier_group_id) as modifier_group_count
FROM products p
LEFT JOIN menu_item_modifier_groups mimg ON mimg.menu_item_id = p.id
WHERE p.product_type = 'menu_item'
GROUP BY p.id;
```

---

## Performance Considerations

### Indexes Added

| Table | Index | Purpose | Type |
|-------|-------|---------|------|
| products | idx_products_type | Filter by product type | B-tree |
| recipes | idx_recipes_menu_item | Find recipe for menu item | B-tree |
| recipe_lines | idx_recipe_lines_ingredient | Aggregate ingredient usage | B-tree |
| modifier_groups | idx_modifier_groups_active | List active groups | Partial |
| pos_receipt_lines | idx_pos_receipt_lines_modifiers | Query modifier selections | GIN (JSONB) |
| voucher_usage_tracking | idx_voucher_usage_serial_date | Daily limit checks | Composite |

### Estimated Storage Impact

| Table | Rows (Café with 50 menu items) | Storage |
|-------|--------------------------------|---------|
| recipes | 50 | ~10 KB |
| recipe_lines | 300 (avg 6 ingredients/recipe) | ~50 KB |
| modifier_groups | 10 | ~2 KB |
| modifier_options | 50 | ~10 KB |
| menu_item_sizes | 150 (avg 3 sizes/item) | ~20 KB |
| menu_item_modifier_groups | 200 | ~5 KB |

**Total:** ~100 KB of master data

---

## Rollback Plan

If critical issues found after deployment:

```bash
# Quick rollback of all F&B migrations
php artisan migrate:rollback --step=13

# Or rollback specific phases
php artisan migrate:rollback --step=7  # Rollback Phase 1
php artisan migrate:rollback --step=2  # Rollback Phase 2
```

**Data Loss Warning:** Rolling back will permanently delete:
- All recipes and recipe lines
- All modifier groups and options
- Menu item configurations
- Historical modifier data in receipts (JSONB)

**Mitigation:** Always backup database before major migrations.

---

*Database schema version: 1.0*
*Last updated: January 2026*
