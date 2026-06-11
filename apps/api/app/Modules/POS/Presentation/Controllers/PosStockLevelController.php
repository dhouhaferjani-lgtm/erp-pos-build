<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Terminal;
use App\Shared\Contracts\LocationStockReader;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Location stock feed for the offline POS device sync.
 *
 * GET /api/v1/pos/stock-levels
 *
 * The terminal_id identifies the device; the location is always derived
 * server-side from the terminal row (spec §4.1 trust boundary).
 *
 * Supports two modes:
 * - Full mode (no updated_since):  complete stock set for the location.
 * - Delta mode (updated_since set): only rows updated after the cursor.
 *
 * The incoming set (in-transit transfers + unreceived PO lines) is ALWAYS
 * the complete set for the location regardless of mode — incoming changes
 * do not touch stock_levels.updated_at.
 */
final class PosStockLevelController extends Controller
{
    public function __construct(
        private readonly LocationStockReader $stockReader,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validate([
            'terminal_id' => ['required', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        // updated_since is an ISO-8601 timestamp passed as a URL query param.
        // Laravel's built-in `date` rule calls strtotime() which decodes `+` as
        // a space, making e.g. "2026-06-11T23:00:00+00:00" fail. We parse it
        // manually so that both "+00:00" and "Z" timezone variants are accepted.
        $updatedSince = null;
        $updatedSinceRaw = $request->query('updated_since');
        if (is_string($updatedSinceRaw) && $updatedSinceRaw !== '') {
            // When an ISO-8601 timestamp with a +HH:MM timezone offset is passed as
            // a URL query parameter, RFC 3986 query-string decoding treats `+` as a
            // space (application/x-www-form-urlencoded convention). Re-encode any
            // space that appears in the numeric timezone segment so that Carbon can
            // parse both "2026-06-11T22:00:00+00:00" and the space-decoded variant.
            $normalised = preg_replace('/T(\d{2}:\d{2}:\d{2}) (\d{2}:\d{2})$/', 'T$1+$2', $updatedSinceRaw);
            try {
                $updatedSince = CarbonImmutable::parse($normalised ?? $updatedSinceRaw);
            } catch (InvalidFormatException) {
                throw ValidationException::withMessages([
                    'updated_since' => ['The updated_since must be a valid date.'],
                ]);
            }
        }

        $companyId = $this->companyContext->requireCompanyId();

        $terminal = Terminal::query()
            ->where('company_id', $companyId)
            ->where('id', $validated['terminal_id'])
            ->first();

        if ($terminal === null) {
            return response()->json([
                'error' => [
                    'code' => 'TERMINAL_NOT_FOUND',
                    'message' => 'Terminal not found for this company',
                ],
            ], 404);
        }

        $asOf = now()->toIso8601String();

        $page = $this->stockReader->read(
            tenantId: $this->companyContext->requireTenantId(),
            companyId: $companyId,
            locationId: (string) $terminal->location_id,
            updatedSince: $updatedSince,
            page: (int) ($validated['page'] ?? 1),
            perPage: 500,
        );

        return response()->json([
            'data' => [
                'stock' => array_map(static fn ($r) => [
                    'product_id' => $r->productId,
                    'variant_id' => $r->variantId,
                    'quantity' => $r->quantity,
                    'reserved' => $r->reserved,
                    'available' => $r->available,
                    'updated_at' => $r->updatedAt,
                ], $page->stock),
                'incoming' => array_map(static fn ($r) => [
                    'product_id' => $r->productId,
                    'variant_id' => $r->variantId,
                    'incoming_transfer' => $r->incomingTransfer,
                    'incoming_po' => $r->incomingPo,
                ], $page->incoming),
                'as_of' => $asOf,
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $page->page,
                    'last_page' => $page->lastPage,
                    'total' => $page->total,
                ],
            ],
        ]);
    }
}
