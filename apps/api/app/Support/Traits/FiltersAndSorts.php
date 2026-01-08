<?php

declare(strict_types=1);

namespace App\Support\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

trait FiltersAndSorts
{
    /**
     * Get sorting parameters from request with validation.
     *
     * @param  array<string>  $allowedColumns  Whitelist of sortable columns
     * @param  string  $defaultColumn  Default sort column
     * @param  string  $defaultDirection  Default direction ('asc' or 'desc')
     * @return array{sort_by: string, sort_dir: string}
     */
    protected function getSortParams(
        Request $request,
        array $allowedColumns,
        string $defaultColumn = 'created_at',
        string $defaultDirection = 'desc'
    ): array {
        $sortBy = $request->input('sort_by', $defaultColumn);
        $sortDir = $request->input('sort_dir', $defaultDirection);

        // Validate sort column is in allowed list
        if (! in_array($sortBy, $allowedColumns, true)) {
            $sortBy = $defaultColumn;
        }

        // Validate sort direction
        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = $defaultDirection;
        }

        return [
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
        ];
    }

    /**
     * Get filter parameters from request and validate against configuration.
     *
     * @param  array<string, array{type: string, column?: string, columns?: array<string>, enum?: class-string<BackedEnum>, operator?: string, callback?: callable}>  $config  Filter configuration
     * @return array<string, mixed>
     */
    protected function getFilterParams(Request $request, array $config): array
    {
        $filters = [];

        foreach ($config as $key => $definition) {
            if (! $request->has($key)) {
                continue;
            }

            $value = $request->input($key);

            // Skip empty values
            if ($value === null || $value === '') {
                continue;
            }

            // Validate based on filter type
            $type = $definition['type'];

            switch ($type) {
                case 'enum':
                    if (isset($definition['enum'])) {
                        $enumClass = $definition['enum'];
                        $enumValue = $enumClass::tryFrom($value);
                        if ($enumValue !== null) {
                            $filters[$key] = $enumValue;
                        }
                    }
                    break;

                case 'boolean':
                    $filters[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;

                case 'text':
                    $filters[$key] = (string) $value;
                    break;

                case 'range':
                case 'date':
                    // Range and date values are passed through as-is
                    // They'll be validated when applied
                    $filters[$key] = $value;
                    break;

                case 'relationship':
                case 'multi_select':
                case 'computed':
                    // Pass through as-is
                    $filters[$key] = $value;
                    break;

                default:
                    // Unknown type, skip
                    break;
            }
        }

        return $filters;
    }

    /**
     * Apply sorting to query builder.
     *
     * @param  array{sort_by: string, sort_dir: string}  $sortParams
     */
    protected function applySorting(Builder $query, array $sortParams): Builder
    {
        return $query->orderBy($sortParams['sort_by'], $sortParams['sort_dir']);
    }

    /**
     * Apply filters to query builder based on configuration.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, array{type: string, column?: string, columns?: array<string>, enum?: class-string<BackedEnum>, operator?: string, callback?: callable}>  $config
     */
    protected function applyFilters(Builder $query, array $filters, array $config): Builder
    {
        foreach ($filters as $key => $value) {
            if (! isset($config[$key])) {
                continue;
            }

            $definition = $config[$key];
            $type = $definition['type'];

            switch ($type) {
                case 'enum':
                    $column = $definition['column'] ?? $key;
                    $query->where($column, $value);
                    break;

                case 'boolean':
                    $column = $definition['column'] ?? $key;
                    $query->where($column, $value);
                    break;

                case 'text':
                    // Case-insensitive search across multiple columns
                    $columns = $definition['columns'] ?? [$key];
                    $searchTerm = mb_strtolower((string) $value);
                    // Prevent LIKE injection
                    $searchTerm = addcslashes($searchTerm, '%_\\');

                    $query->where(function ($q) use ($columns, $searchTerm) {
                        foreach ($columns as $column) {
                            $q->orWhereRaw('LOWER('.$column.') LIKE ?', ["%{$searchTerm}%"]);
                        }
                    });
                    break;

                case 'range':
                    $column = $definition['column'] ?? $key;
                    $operator = $definition['operator'] ?? '=';
                    $query->where($column, $operator, $value);
                    break;

                case 'date':
                    $column = $definition['column'] ?? $key;
                    $operator = $definition['operator'] ?? '=';

                    try {
                        $date = Carbon::parse($value);
                        $query->whereDate($column, $operator, $date);
                    } catch (\Exception $e) {
                        // Invalid date, skip filter
                    }
                    break;

                case 'relationship':
                    $column = $definition['column'] ?? $key;
                    $query->where($column, $value);
                    break;

                case 'multi_select':
                    $column = $definition['column'] ?? $key;
                    $values = is_array($value) ? $value : [$value];
                    $query->whereIn($column, $values);
                    break;

                case 'computed':
                    // Use custom callback
                    if (isset($definition['callback']) && is_callable($definition['callback'])) {
                        $definition['callback']($query, $value);
                    }
                    break;

                default:
                    // Unknown type, skip
                    break;
            }
        }

        return $query;
    }

    /**
     * Calculate aggregates on the query.
     *
     * @param  array<string, array{type: string, column?: string, expression?: string, filter?: array<string, mixed>}>  $config
     * @return array<string, mixed>
     */
    protected function calculateAggregates(Builder $query, array $config): array
    {
        $aggregates = [];

        foreach ($config as $key => $definition) {
            $type = $definition['type'];

            // Clone query to avoid modifying the original
            $aggregateQuery = clone $query;

            // Apply additional filter if specified
            if (isset($definition['filter'])) {
                foreach ($definition['filter'] as $column => $value) {
                    $aggregateQuery->where($column, $value);
                }
            }

            switch ($type) {
                case 'count':
                    $aggregates[$key] = $aggregateQuery->count();
                    break;

                case 'sum':
                    $column = $definition['column'] ?? $key;
                    if (isset($definition['expression'])) {
                        $aggregates[$key] = $aggregateQuery->sum(\DB::raw($definition['expression']));
                    } else {
                        $aggregates[$key] = $aggregateQuery->sum($column);
                    }
                    break;

                case 'avg':
                    $column = $definition['column'] ?? $key;
                    if (isset($definition['expression'])) {
                        $aggregates[$key] = $aggregateQuery->avg(\DB::raw($definition['expression']));
                    } else {
                        $aggregates[$key] = $aggregateQuery->avg($column);
                    }
                    break;

                case 'min':
                    $column = $definition['column'] ?? $key;
                    $aggregates[$key] = $aggregateQuery->min($column);
                    break;

                case 'max':
                    $column = $definition['column'] ?? $key;
                    $aggregates[$key] = $aggregateQuery->max($column);
                    break;

                default:
                    // Unknown type, skip
                    break;
            }
        }

        return $aggregates;
    }
}
