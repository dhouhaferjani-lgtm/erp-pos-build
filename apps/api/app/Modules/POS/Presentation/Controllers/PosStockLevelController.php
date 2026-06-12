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
    /**
     * 5× the voucher/receipt sync page size (PAGE_SIZE = 100): stock rows are
     * flat scalar tuples (no nested payloads), and the client pulls this feed
     * on every sync tick — fewer round-trips matter more than payload size.
     *
     * FU-8 — no shared client constant by design: the client
     * (`pullLocationStock` in apps/pos/src/lib/sync/syncService.ts) paginates by
     * `meta.pagination.last_page`, NOT by assuming this page size, so changing
     * this value is self-adapting and needs no coordinated client change. The
     * only client-side assumption is payload-size headroom (it buffers all pages
     * in memory before writing) — a large increase here should be sanity-checked
     * against that buffer.
     */
    private const PER_PAGE = 500;

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

        $updatedSince = $this->parseUpdatedSince($request);

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
            perPage: self::PER_PAGE,
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

    /**
     * Parse the optional ISO-8601 cursor. Laravel's `date` rule (strtotime)
     * rejects the server's own as_of format: RFC 3986 query decoding turns the
     * `+` of a "+HH:MM" offset into a space (x-www-form-urlencoded convention),
     * so "2026-06-11T22:00:00+00:00" arrives as "...22:00:00 00:00". Restore
     * the `+` in the offset segment (second or sub-second precision), then
     * parse; anything Carbon still rejects is a 422.
     */
    private function parseUpdatedSince(Request $request): ?CarbonImmutable
    {
        $raw = $request->query('updated_since');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $normalised = preg_replace(
            '/T(\d{2}:\d{2}:\d{2}(?:\.\d+)?) (\d{2}:\d{2})$/',
            'T$1+$2',
            $raw,
        );

        try {
            return CarbonImmutable::parse($normalised ?? $raw);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages([
                'updated_since' => ['The updated_since must be a valid date.'],
            ]);
        }
    }
}
