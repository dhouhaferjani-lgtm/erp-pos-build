<?php

declare(strict_types=1);

namespace App\Support\Traits;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

trait PaginatesResults
{
    /**
     * @return array{per_page: int, cursor: string|null}
     */
    protected function getPaginationParams(Request $request): array
    {
        return [
            'per_page' => min((int) $request->input('per_page', 25), 100),
            'cursor' => $request->input('cursor'),
        ];
    }

    /**
     * Format cursor paginated response (for backward compatibility).
     *
     * @param  CursorPaginator<int, mixed>  $paginator
     * @param  class-string|null  $dataClass  DTO class with fromModel() method
     * @return array{data: array<mixed>, meta: array{per_page: int, has_more: bool}, links: array{next: string|null, prev: string|null}}
     */
    protected function formatPaginatedResponse(CursorPaginator $paginator, ?string $dataClass = null): array
    {
        $items = $paginator->items();

        // Transform items if DTO class provided
        if ($dataClass && method_exists($dataClass, 'fromModel')) {
            /** @var array<int, mixed> $collected */
            $collected = collect($items)->map(fn (mixed $item): mixed => $dataClass::fromModel($item))->all();
            $items = $collected;
        }

        return [
            'data' => $items,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'links' => [
                'next' => $paginator->nextCursor()?->encode(),
                'prev' => $paginator->previousCursor()?->encode(),
            ],
        ];
    }

    /**
     * Format offset paginated response with optional aggregates.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string|null  $dataClass  DTO class with fromModel() method
     * @param  array<string, mixed>|null  $aggregates  Optional aggregate data
     * @return array{data: array<mixed>, meta: array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}, aggregates?: array<string, mixed>}
     */
    protected function formatOffsetPaginatedResponse(
        LengthAwarePaginator $paginator,
        ?string $dataClass = null,
        ?array $aggregates = null
    ): array {
        $items = $paginator->items();

        // Transform items if DTO class provided
        if ($dataClass && method_exists($dataClass, 'fromModel')) {
            /** @var array<int, mixed> $collected */
            $collected = collect($items)->map(fn (mixed $item): mixed => $dataClass::fromModel($item))->all();
            $items = $collected;
        }

        $response = [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];

        // Include aggregates if provided
        if ($aggregates !== null) {
            $response['aggregates'] = $aggregates;
        }

        return $response;
    }
}
