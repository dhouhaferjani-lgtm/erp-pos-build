<?php

namespace App\Support\Traits;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

trait PaginatesResults
{
    protected function getPaginationParams(Request $request): array
    {
        return [
            'per_page' => min((int) $request->input('per_page', 25), 100),
            'cursor' => $request->input('cursor'),
        ];
    }

    protected function formatPaginatedResponse(CursorPaginator $paginator, string $dataClass = null): array
    {
        $items = $paginator->items();

        // Transform items if DTO class provided
        if ($dataClass && method_exists($dataClass, 'fromModel')) {
            $items = collect($items)->map(fn ($item) => $dataClass::fromModel($item))->all();
        }

        return [
            'data' => $items,
            'meta' => [
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
                // Note: cursor pagination doesn't provide total by default
                // Include only if explicitly requested and query is simple
            ],
            'links' => [
                'next' => $paginator->nextCursor()?->encode(),
                'prev' => $paginator->previousCursor()?->encode(),
            ],
        ];
    }
}
