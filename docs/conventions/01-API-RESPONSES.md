# API Response Conventions

> **Purpose:** Standard API response formats used throughout the backend
> **Last Updated:** 2025-12-30

## Success Response Structure

**All successful API responses follow this pattern:**

```php
response()->json([
    'data' => $yourData,  // ← Your actual resource(s)
    'meta' => [
        'timestamp' => now()->toIso8601String(),
        'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
    ],
]);
```

## Single Resource Response

```php
// From ProductController::show()
return response()->json([
    'data' => ProductData::fromModel($product),
    'meta' => [
        'timestamp' => now()->toIso8601String(),
        'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
    ],
], 200);

// Created resource (201)
return response()->json([
    'data' => ProductData::fromModel($product),
    'meta' => [
        'timestamp' => now()->toIso8601String(),
        'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
    ],
], 201);
```

## Paginated Response Structure

**Uses the `PaginatesResults` trait:**

**File:** `apps/api/app/Http/Traits/PaginatesResults.php`

```php
return response()->json([
    'data' => $items,  // Array of DTOs
    'meta' => [
        'per_page' => $paginator->perPage(),
        'has_more' => $paginator->hasMorePages(),
    ],
    'links' => [
        'next' => $paginator->nextCursor()?->encode(),
        'prev' => $paginator->previousCursor()?->encode(),
    ],
]);
```

**Real Example:**

```php
// From PartnerController::index()
$paginator = $query->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
return response()->json(
    $this->formatPaginatedResponse($paginator, PartnerData::class)
);
```

## Error Responses

### Validation Error (422)

```php
// Auto-handled by bootstrap/app.php
return response()->json([
    'error' => [
        'code' => 'VALIDATION_ERROR',
        'message' => $e->getMessage(),
        'errors' => $e->errors(),  // Field-level errors
    ],
], 422);
```

### Business Logic Error (422)

```php
return response()->json([
    'error' => [
        'code' => 'BUSINESS_ERROR',
        'message' => 'Only draft invoices can be confirmed',
    ],
], 422);
```

### Not Found (404)

```php
return response()->json([
    'error' => [
        'code' => 'NOT_FOUND',
        'message' => 'Product not found',
    ],
], 404);
```

### Authentication Error (401)

```php
return response()->json([
    'error' => [
        'code' => 'UNAUTHENTICATED',
        'message' => 'Authentication required',
    ],
], 401);
```

## Response Type Summary

| Type | Status | Structure |
|------|--------|-----------|
| Single | 200 | `{ data: {}, meta: {} }` |
| Created | 201 | `{ data: {}, meta: {} }` |
| List | 200 | `{ data: [], meta: {per_page, has_more}, links: {} }` |
| No Content | 204 | `null` |
| Validation Error | 422 | `{ error: {code, message, errors} }` |
| Business Error | 422 | `{ error: {code, message} }` |
| Not Found | 404 | `{ error: {code, message} }` |
| Unauthorized | 401 | `{ error: {code, message} }` |

## Critical Rules

**✅ ALWAYS:**
- Wrap data in `{ data: ... }`
- Include `meta.timestamp` (ISO 8601)
- Include `meta.request_id`
- Use DTOs, not raw models
- Return proper HTTP status codes

**❌ NEVER:**
- Return bare arrays/objects
- Double-wrap: `{ data: { data: ... } }`
- Omit `meta` from success responses
- Return plain strings/numbers
- Mix success and error structures
