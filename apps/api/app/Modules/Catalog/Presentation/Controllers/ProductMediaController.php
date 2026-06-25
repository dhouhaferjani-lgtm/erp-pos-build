<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Controllers;

use App\Modules\Catalog\Presentation\Requests\AttachMediaRequest;
use App\Modules\Catalog\Presentation\Requests\ReorderMediaRequest;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Media\Application\Services\MediaAttachmentService;
use App\Modules\Media\Application\Services\MediaUploadService;
use App\Modules\Media\Application\Services\MediaUrlResolver;
use App\Modules\Media\Domain\Contracts\MediaStorageInterface;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Façade over the media_assets / media_attachments model, exposing the
 * existing `/products/{product}/images[...]` API contract.
 *
 * {image} in every URL is a media_attachments.id (UUID).
 *
 * Tenant isolation is enforced by resolving every product through the
 * caller's tenant + company scope (via CompanyContext), and every
 * attachment through that product's owner_id.  Cross-tenant or
 * cross-company ids are indistinguishable from missing ids (→ 404) to
 * prevent information leakage.
 *
 * Replaces ProductImageController; route group middleware is unchanged:
 * ['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim,'module:Inventory']
 * + per-route can:products.* + throttle:image-upload.
 */
final class ProductMediaController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploadService,
        private readonly MediaAttachmentService $attachmentService,
        private readonly MediaStorageInterface $storage,
        private readonly MediaUrlResolver $urlResolver,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all READY image attachments for a product.
     *
     * Returns an array with the same envelope as the old controller:
     * { data: [ { id, is_primary, sort_order, url, role, … } ] }
     */
    public function index(string $product): JsonResponse
    {
        $productModel = $this->resolveProduct($product);

        $attachments = $this->loadAttachments($productModel->id, $productModel->tenant_id);

        return response()->json(['data' => $this->formatList($attachments)]);
    }

    /**
     * Upload a new image for a product and create the media_attachment link.
     *
     * The first upload becomes PRIMARY; subsequent uploads become GALLERY.
     *
     * Validation is done inline (not via a FormRequest) so that product
     * resolution (→ 404 on cross-tenant ids) runs BEFORE validation (→ 422).
     * This mirrors ProductImageController's approach and preserves the
     * tenant-isolation guarantee: a 404 always wins over a 422.
     */
    public function store(Request $request, string $product): JsonResponse
    {
        $productModel = $this->resolveProduct($product);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('image');

        $userId = Auth::id();

        $asset = $this->uploadService->uploadForProduct(
            $productModel->tenant_id,
            $productModel->id,
            $file,
            $userId !== null ? (string) $userId : null,
        );

        // Determine role: PRIMARY if no existing READY attachment for this product.
        // TOCTOU note: this count runs outside the attach() transaction, so two
        // concurrent first-uploads can both compute PRIMARY here.  That is
        // intentional and benign: attach() demotes any existing PRIMARY inside a
        // transaction, and the PG partial unique index on (owner_id, role='primary')
        // guarantees exactly one PRIMARY survives — the same safety net as the
        // prior ProductImageService.  The race window is cosmetic, not fiscal.
        $existingCount = MediaAttachment::where('tenant_id', $productModel->tenant_id)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $productModel->id)
            ->whereHas('mediaAsset', static function (Builder $q) use ($productModel): void {
                /** @var Builder<MediaAsset> $q */
                $q->where('tenant_id', $productModel->tenant_id)
                    ->where('status', MediaStatus::Ready);
            })
            ->count();

        $role = $existingCount === 0 ? MediaRole::Primary : MediaRole::Gallery;

        $sortOrder = (int) MediaAttachment::where('tenant_id', $productModel->tenant_id)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $productModel->id)
            ->max('sort_order');

        if ($existingCount > 0) {
            $sortOrder += 1;
        }

        $attachment = $this->attachmentService->attach(
            $asset->id,
            MediaOwnerType::Product,
            $productModel->id,
            $role,
            $sortOrder,
            $productModel->tenant_id,
        );

        // Reload with the asset relation so we can resolve the URL
        $attachment->load('mediaAsset');

        return response()->json(['data' => $this->formatOne($attachment)], 201);
    }

    /**
     * Update an attachment: set primary and/or change sort_order.
     */
    public function update(AttachMediaRequest $request, string $product, string $image): JsonResponse
    {
        $productModel = $this->resolveProduct($product);
        $attachment = $this->resolveAttachment($productModel, $image);

        if ($request->boolean('is_primary')) {
            // Demote any existing PRIMARY for this product (other than this attachment),
            // then promote this attachment to PRIMARY.  Done directly on the Eloquent model
            // (not via attach(), which creates a NEW row) so the attachment id stays stable.
            MediaAttachment::where('tenant_id', $productModel->tenant_id)
                ->where('owner_type', MediaOwnerType::Product)
                ->where('owner_id', $productModel->id)
                ->where('role', MediaRole::Primary)
                ->where('id', '!=', $attachment->id)
                ->update(['role' => MediaRole::Gallery]);

            $attachment->role = MediaRole::Primary;
            $attachment->save();
        }

        if ($request->has('sort_order') && $request->input('sort_order') !== null) {
            $attachment->sort_order = (int) $request->input('sort_order');
            $attachment->save();
        }

        $attachment->load('mediaAsset');

        return response()->json(['data' => $this->formatOne($attachment)]);
    }

    /**
     * Hard-delete an attachment. If it was PRIMARY, promotes the next.
     */
    public function destroy(string $product, string $image): JsonResponse
    {
        $productModel = $this->resolveProduct($product);
        $attachment = $this->resolveAttachment($productModel, $image);

        $this->attachmentService->detachLink($attachment->id, $productModel->tenant_id);

        return response()->json(null, 204);
    }

    /**
     * Serve an image inline (or redirect for external-URL assets).
     */
    public function download(Request $request, string $product, string $image): StreamedResponse|RedirectResponse
    {
        $productModel = $this->resolveProduct($product);
        $attachment = $this->resolveAttachment($productModel, $image);

        $variant = $request->query('variant');
        $validVariants = ['sm', 'md'];
        $resolvedVariant = is_string($variant) && in_array($variant, $validVariants, true) ? $variant : null;

        // Eager-load asset + renditions so serve() can resolve the best path
        $attachment->load(['mediaAsset.renditions']);

        return $this->storage->serve($attachment, $resolvedVariant);
    }

    /**
     * Reorder attachments for a product.
     *
     * Every id in image_ids must belong to the bound product; any foreign id
     * is a 422 validation failure (same behaviour as the old controller's
     * `exists:product_images,id,product_id,…` rule).
     */
    public function reorder(ReorderMediaRequest $request, string $product): JsonResponse
    {
        $productModel = $this->resolveProduct($product);

        /** @var array<int, string> $imageIds */
        $imageIds = $request->input('image_ids', []);

        // Validate that every id belongs to this product (422 for foreign ids)
        $validator = Validator::make($request->all(), [
            'image_ids.*' => [
                'uuid',
                function (string $attribute, mixed $value, \Closure $fail) use ($productModel): void {
                    if (! is_string($value)) {
                        $fail('The attachment id must be a string.');

                        return;
                    }

                    $exists = MediaAttachment::where('id', $value)
                        ->where('tenant_id', $productModel->tenant_id)
                        ->where('owner_type', MediaOwnerType::Product)
                        ->where('owner_id', $productModel->id)
                        ->exists();

                    if (! $exists) {
                        $fail("The attachment [{$value}] does not belong to this product.");
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $this->attachmentService->reorder(
            MediaOwnerType::Product,
            $productModel->id,
            $imageIds,
            $productModel->tenant_id,
        );

        $attachments = $this->loadAttachments($productModel->id, $productModel->tenant_id);

        return response()->json(['data' => $this->formatList($attachments)]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve the product through the caller's tenant + company scope.
     * Returns 404 if the product does not exist in the scope (cross-tenant
     * and cross-company ids are indistinguishable from missing ids).
     */
    private function resolveProduct(string $productId): Product
    {
        if (! Str::isUuid($productId)) {
            abort(404, 'Product not found');
        }

        $company = $this->companyContext->requireCompany();

        $product = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $productId)
            ->first();

        if ($product === null) {
            abort(404, 'Product not found');
        }

        return $product;
    }

    /**
     * Resolve a media_attachments row scoped to the resolved product.
     * Returns 404 on any mismatch (foreign id, cross-product id, etc.).
     */
    private function resolveAttachment(Product $product, string $attachmentId): MediaAttachment
    {
        if (! Str::isUuid($attachmentId)) {
            abort(404, 'Media attachment not found');
        }

        $attachment = MediaAttachment::query()
            ->where('id', $attachmentId)
            ->where('tenant_id', $product->tenant_id)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $product->id)
            ->first();

        if ($attachment === null) {
            abort(404, 'Media attachment not found');
        }

        return $attachment;
    }

    /**
     * Load all READY image attachments (with their assets) ordered by sort_order.
     *
     * @return Collection<int, MediaAttachment>
     */
    private function loadAttachments(string $productId, string $tenantId): Collection
    {
        return MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', MediaOwnerType::Product)
            ->where('owner_id', $productId)
            ->whereHas('mediaAsset', static function (Builder $q) use ($tenantId): void {
                /** @var Builder<MediaAsset> $q */
                $q->where('tenant_id', $tenantId)
                    ->where('status', MediaStatus::Ready);
            })
            ->with([
                'mediaAsset' => static function ($relation) use ($tenantId): void {
                    $relation->where('tenant_id', $tenantId);
                },
            ])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Map a single MediaAttachment to the legacy-compatible response shape.
     *
     * @return array<string, mixed>
     */
    private function formatOne(MediaAttachment $attachment): array
    {
        $url = $this->urlResolver->forAttachment($attachment, null);

        return [
            'id' => $attachment->id,
            'asset_id' => $attachment->media_asset_id,
            'is_primary' => $attachment->role === MediaRole::Primary,
            'sort_order' => $attachment->sort_order,
            'role' => $attachment->role->value,
            'url' => $url,
            'alt' => $attachment->alt,
            'caption' => $attachment->caption,
        ];
    }

    /**
     * Map a collection of MediaAttachment models to the response array.
     *
     * @param  Collection<int, MediaAttachment>  $attachments
     * @return array<int, array<string, mixed>>
     */
    private function formatList(Collection $attachments): array
    {
        return $attachments->map(fn (MediaAttachment $a): array => $this->formatOne($a))->values()->all();
    }
}
