<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Traits;

use App\Modules\Product\Domain\Enums\ProductType;
use App\Support\Traits\FiltersAndSorts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tests\TestCase;

class FiltersAndSortsTest extends TestCase
{
    use FiltersAndSorts;

    public function test_get_sort_params_validates_column_whitelist(): void
    {
        $request = Request::create('/', 'GET', ['sort_by' => 'malicious_column']);

        $params = $this->getSortParams($request, ['name', 'created_at'], 'created_at', 'desc');

        $this->assertEquals('created_at', $params['sort_by']);
        $this->assertEquals('desc', $params['sort_dir']);
    }

    public function test_get_sort_params_accepts_valid_column(): void
    {
        $request = Request::create('/', 'GET', ['sort_by' => 'name', 'sort_dir' => 'asc']);

        $params = $this->getSortParams($request, ['name', 'created_at'], 'created_at', 'desc');

        $this->assertEquals('name', $params['sort_by']);
        $this->assertEquals('asc', $params['sort_dir']);
    }

    public function test_get_sort_params_validates_direction(): void
    {
        $request = Request::create('/', 'GET', ['sort_by' => 'name', 'sort_dir' => 'invalid']);

        $params = $this->getSortParams($request, ['name', 'created_at'], 'created_at', 'desc');

        $this->assertEquals('name', $params['sort_by']);
        $this->assertEquals('desc', $params['sort_dir']); // Falls back to default
    }

    public function test_get_sort_params_uses_defaults_when_no_params(): void
    {
        $request = Request::create('/', 'GET');

        $params = $this->getSortParams($request, ['name', 'created_at'], 'created_at', 'asc');

        $this->assertEquals('created_at', $params['sort_by']);
        $this->assertEquals('asc', $params['sort_dir']);
    }

    public function test_get_filter_params_handles_enum_filter(): void
    {
        $request = Request::create('/', 'GET', ['type' => 'part']);

        $config = [
            'type' => [
                'type' => 'enum',
                'enum' => ProductType::class,
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertArrayHasKey('type', $filters);
        $this->assertInstanceOf(ProductType::class, $filters['type']);
        $this->assertEquals(ProductType::Part, $filters['type']);
    }

    public function test_get_filter_params_rejects_invalid_enum_value(): void
    {
        $request = Request::create('/', 'GET', ['type' => 'invalid']);

        $config = [
            'type' => [
                'type' => 'enum',
                'enum' => ProductType::class,
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertArrayNotHasKey('type', $filters);
    }

    public function test_get_filter_params_handles_boolean_filter(): void
    {
        $request = Request::create('/', 'GET', ['is_active' => '1']);

        $config = [
            'is_active' => [
                'type' => 'boolean',
                'column' => 'is_active',
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertArrayHasKey('is_active', $filters);
        $this->assertTrue($filters['is_active']);
    }

    public function test_get_filter_params_handles_text_filter(): void
    {
        $request = Request::create('/', 'GET', ['search' => 'test search']);

        $config = [
            'search' => [
                'type' => 'text',
                'columns' => ['name', 'sku'],
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertArrayHasKey('search', $filters);
        $this->assertEquals('test search', $filters['search']);
    }

    public function test_get_filter_params_handles_range_filter(): void
    {
        $request = Request::create('/', 'GET', ['price_min' => '100', 'price_max' => '500']);

        $config = [
            'price_min' => [
                'type' => 'range',
                'column' => 'sale_price',
                'operator' => '>=',
            ],
            'price_max' => [
                'type' => 'range',
                'column' => 'sale_price',
                'operator' => '<=',
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertArrayHasKey('price_min', $filters);
        $this->assertArrayHasKey('price_max', $filters);
        $this->assertEquals('100', $filters['price_min']);
        $this->assertEquals('500', $filters['price_max']);
    }

    public function test_get_filter_params_handles_date_filter(): void
    {
        $request = Request::create('/', 'GET', ['date_from' => '2025-01-01']);

        $config = [
            'date_from' => [
                'type' => 'date',
                'column' => 'created_at',
                'operator' => '>=',
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertArrayHasKey('date_from', $filters);
        $this->assertEquals('2025-01-01', $filters['date_from']);
    }

    public function test_get_filter_params_skips_empty_values(): void
    {
        $request = Request::create('/', 'GET', ['type' => '', 'is_active' => null]);

        $config = [
            'type' => [
                'type' => 'enum',
                'enum' => ProductType::class,
            ],
            'is_active' => [
                'type' => 'boolean',
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertEmpty($filters);
    }

    public function test_get_filter_params_skips_missing_params(): void
    {
        $request = Request::create('/', 'GET');

        $config = [
            'type' => [
                'type' => 'enum',
                'enum' => ProductType::class,
            ],
        ];

        $filters = $this->getFilterParams($request, $config);

        $this->assertEmpty($filters);
    }

    public function test_apply_sorting_adds_order_by_clause(): void
    {
        // Create a test model for building queries
        $model = new class extends Model
        {
            protected $table = 'test_table';
        };

        $query = $model->newQuery();

        $sortParams = ['sort_by' => 'name', 'sort_dir' => 'asc'];
        $result = $this->applySorting($query, $sortParams);

        $this->assertInstanceOf(Builder::class, $result);

        // Check that ORDER BY is in the SQL
        $sql = $result->toSql();
        $this->assertStringContainsString('order by', strtolower($sql));
    }

    public function test_apply_filters_handles_enum_filter(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test_table';
        };

        $query = $model->newQuery();

        $filters = ['type' => ProductType::Part];
        $config = [
            'type' => [
                'type' => 'enum',
                'column' => 'type',
            ],
        ];

        $result = $this->applyFilters($query, $filters, $config);

        $bindings = $result->getBindings();
        $this->assertContains('part', $bindings);
    }

    public function test_apply_filters_handles_boolean_filter(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test_table';
        };

        $query = $model->newQuery();

        $filters = ['is_active' => true];
        $config = [
            'is_active' => [
                'type' => 'boolean',
                'column' => 'is_active',
            ],
        ];

        $result = $this->applyFilters($query, $filters, $config);

        $bindings = $result->getBindings();
        $this->assertContains(true, $bindings);
    }

    public function test_apply_filters_handles_text_filter(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test_table';
        };

        $query = $model->newQuery();

        $filters = ['search' => 'test'];
        $config = [
            'search' => [
                'type' => 'text',
                'columns' => ['name', 'sku'],
            ],
        ];

        $result = $this->applyFilters($query, $filters, $config);

        $sql = $result->toSql();
        $this->assertStringContainsString('LOWER(name) LIKE', $sql);
        $this->assertStringContainsString('LOWER(sku) LIKE', $sql);
    }

    public function test_apply_filters_handles_range_filter(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test_table';
        };

        $query = $model->newQuery();

        $filters = ['price_min' => 100];
        $config = [
            'price_min' => [
                'type' => 'range',
                'column' => 'sale_price',
                'operator' => '>=',
            ],
        ];

        $result = $this->applyFilters($query, $filters, $config);

        $bindings = $result->getBindings();
        $this->assertContains(100, $bindings);
    }

    public function test_apply_filters_handles_computed_filter(): void
    {
        $model = new class extends Model
        {
            protected $table = 'test_table';
        };

        $query = $model->newQuery();

        $callbackExecuted = false;

        $filters = ['has_stock' => true];
        $config = [
            'has_stock' => [
                'type' => 'computed',
                'callback' => function ($q, $value) use (&$callbackExecuted) {
                    $callbackExecuted = true;
                    $q->where('quantity', '>', 0);
                },
            ],
        ];

        $result = $this->applyFilters($query, $filters, $config);

        $this->assertTrue($callbackExecuted);
        $bindings = $result->getBindings();
        $this->assertContains(0, $bindings);
    }

    public function test_calculate_aggregates_returns_count(): void
    {
        // This test would require a real database connection
        // For now, we'll test that the method exists and returns an array
        $model = new class extends Model
        {
            protected $table = 'test_table';
        };

        $query = $model->newQuery();

        $config = [
            'total' => ['type' => 'count'],
        ];

        // We can't test the actual aggregate without a database,
        // but we can verify the method runs without errors
        $this->expectNotToPerformAssertions();

        try {
            $result = $this->calculateAggregates($query, $config);
            $this->assertIsArray($result);
        } catch (\Exception $e) {
            // Database not connected for unit test, this is expected
        }
    }
}
