<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Shared\Contracts\PosVariantFeedReader;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Product-variant catalog feed for the offline POS device sync.
 * GET /api/v1/pos/variants
 *
 * COMPANY-SCOPED (no terminal_id): the variant catalog is company-wide, unlike
 * /pos/stock-levels which is per-terminal-location. Delta cursor mirrors
 * stock-levels (server as_of); tombstones (deleted_ids) capture deactivation +
 * soft-delete, mirroring the /products pull.
 */
final class PosVariantController extends Controller
{
    private const PER_PAGE = 500;

    public function __construct(
        private readonly PosVariantFeedReader $reader,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'updated_since' => ['sometimes', 'string'],
            'updated_until' => ['sometimes', 'string'],
        ]);

        $updatedSince = $this->parseIsoQueryParam($request, 'updated_since');

        // The upper bound pins the delta window so every page of one sync shares
        // the same snapshot. Page 1 mints it (now()); pages 2+ echo the client's
        // pinned value. as_of always equals the upper bound that was used.
        $updatedUntil = $this->parseIsoQueryParam($request, 'updated_until') ?? CarbonImmutable::now();
        $asOf = $updatedUntil->toIso8601String();

        $feed = $this->reader->read(
            tenantId: $this->companyContext->requireTenantId(),
            companyId: $this->companyContext->requireCompanyId(),
            updatedSince: $updatedSince,
            updatedUntil: $updatedUntil,
            page: (int) ($validated['page'] ?? 1),
            perPage: self::PER_PAGE,
        );

        return response()->json([
            'data' => [
                'variants' => array_map(static fn ($v) => [
                    'id' => $v->id,
                    'product_id' => $v->productId,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'name_suffix' => $v->nameSuffix,
                    'is_default' => $v->isDefault,
                    'display_order' => $v->displayOrder,
                    'price_override' => $v->priceOverride,
                    'image_url' => $v->imageUrl,
                    'updated_at' => $v->updatedAt,
                ], $feed->variants),
                'deleted_ids' => $feed->deletedIds,
                'as_of' => $asOf,
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $feed->page,
                    'last_page' => $feed->lastPage,
                    'total' => $feed->total,
                ],
            ],
        ]);
    }

    private function parseIsoQueryParam(Request $request, string $key): ?CarbonImmutable
    {
        $raw = $request->query($key);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        // Restore a '+HH:MM' offset that the HTTP layer decoded to a space.
        $normalised = preg_replace('/T(\d{2}:\d{2}:\d{2}(?:\.\d+)?) (\d{2}:\d{2})$/', 'T$1+$2', $raw);
        try {
            return CarbonImmutable::parse($normalised ?? $raw);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages([$key => ["The {$key} must be a valid date."]]);
        }
    }
}
