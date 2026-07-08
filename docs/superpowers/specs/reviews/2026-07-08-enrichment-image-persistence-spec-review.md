# Enrichment Image Persistence Design - Adversarial Spec Review

## 1. Summary

I reviewed `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md` against the current repo state, opening the cited implementation files directly and using the real line numbers below. I checked the enrichment paths, media upload/attachment services, media enums and migrations, ProductController dispatch path, Horizon queue coverage, product media URL resolution, rendition generation, and relevant tenancy/CompanyContext code paths.

I did not modify source code or the spec. I did not run tests because this was a read-only design critique. I could not verify claims that depend on files or repos not present under this workspace path, listed in section 4.

## 2. Findings

### IMG-PERSIST-BLOCKER-01

Severity: BLOCKER  
Area: SSRF  
Title: DNS check design still leaves a DNS-rebinding TOCTOU hole, and there is no source-host allowlist

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:55-61` says to reuse the current URL guard, resolve DNS, reject private/link-local/loopback results, and test with an injected resolver.
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:130-132` names DNS-resolution SSRF checks as non-negotiable.
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:296-337` shows the current guard only parses the URL and checks scheme, length, literal blocked hosts, and private IPv4 string prefixes.
- `apps/api/app/Modules/PlatformIntegration/Domain/ValueObjects/PlatformProductData.php:41-52` accepts platform `images` directly from API response data, so image URLs are upstream-controlled data.

Explanation:
The proposed fetcher validates a DNS resolution result but does not require the HTTP request to connect to that exact validated address, nor does it require post-connect peer-IP verification. A hostname can resolve to a public IP during the injected resolver check and then rebind to a private IP when the HTTP client performs its own lookup. Re-checking after redirects helps redirect hops, but it does not close the validation-to-connect race for any hop unless the connection is pinned or verified. The design also allows any public HTTPS host from the enrichment payload; if the platform payload is compromised or polluted, ERP workers become a public-network fetch proxy.

Impact:
The feature's main new attack surface is server-side remote fetch. As written, the design can still be implemented in a way that passes the proposed resolver-seam tests while remaining vulnerable to DNS rebinding.

Minimal fix direction:
Pin the fetch to the validated IP while preserving Host/SNI, or verify the connected peer IP before reading response bytes. Add a configurable host allowlist or platform-signed/canonical image host policy if arbitrary third-party domains are not strictly required.

### IMG-PERSIST-BLOCKER-02

Severity: BLOCKER  
Area: idempotency  
Title: `source_ref` pre-check plus a plain index is not idempotent under concurrent jobs or retries

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:69` relies on a pre-download query for an existing attachment whose asset `source_ref` equals the URL.
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:87` claims retries are safe because the job is "idempotent by construction."
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:96` proposes nullable `source_ref` with only a plain index.
- `apps/api/database/migrations/tenant/2026_06_12_100001_create_media_assets_table.php:13-37` has no existing provenance column and only a non-unique `checksum` index.
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:120-129` creates a fresh UUID storage slot for every upload.
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:174-188` always creates a new `MediaAsset` row for an upload.
- `apps/api/app/Modules/Media/Application/Services/MediaAttachmentService.php:57-78` always creates a new `MediaAttachment` row; it only demotes primaries when role is Primary.

Explanation:
The design's dedup check is a classic check-then-insert race. Two `PersistEnrichmentImagesJob` executions for the same product and URL can both observe no matching attachment, both download, both create unique storage objects and `MediaAsset` rows, and both attach. A plain index on `source_ref` improves lookup speed but provides no idempotency guarantee. The current upload path has no natural uniqueness because it creates a fresh UUID slot and fresh asset row each time.

Impact:
At-least-once delivery, manual reruns, or overlapping `enrichment:run` invocations can create duplicate assets and gallery attachments. If both workers choose Primary, the second attach demotes the first, so the "do not clobber" policy becomes timing-dependent.

Minimal fix direction:
Make idempotency database-enforced, not just query-enforced. Options include a transaction with a product-level lock plus a unique product/source association, a partial unique index over a denormalized attachment source key, or a unique `tenant_id/source_ref` asset with an idempotent attach constraint, depending on whether assets are intended to be shared across products.

### IMG-PERSIST-MAJOR-03

Severity: MAJOR  
Area: failure-mode  
Title: Upload and attach are separate commits, so attach failure leaves orphaned assets and possibly queued renditions

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:71-72` uploads first, then calls `MediaAttachmentService::attach(...)`.
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:161-199` creates the asset inside its own transaction and schedules `GenerateRenditions` after that transaction commits.
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:200-203` deletes the S3 object only when the asset transaction itself throws.
- `apps/api/app/Modules/Media/Application/Services/MediaAttachmentService.php:57-78` wraps only the attachment insert/demotion in a separate transaction.

Explanation:
Once `uploadForProduct()` returns, the object and asset row are committed and the rendition job may already be scheduled. If the later `attach()` call fails, or the persister's post-download dedup decides to skip the just-created asset, the design does not say to delete the asset, delete the original object, delete any renditions, or cancel/ignore the rendition job.

Impact:
Failures and races can accumulate orphaned `media_assets`, S3/MinIO objects, and rendition jobs. Because read paths filter through attachments and READY status, these may be invisible to users while still consuming storage and operational capacity.

Minimal fix direction:
Define a cleanup path around upload-plus-attach. On attach/dedup failure, call the existing media deletion service path or add a dedicated orphan cleanup that removes unattached assets and storage after the decision is final.

### IMG-PERSIST-MAJOR-04

Severity: MAJOR  
Area: idempotency/failure-mode  
Title: The checksum backstop ignores in-flight assets and does not specify cleanup of duplicates

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:73` says to skip/detach the just-created duplicate if the checksum already exists for a READY asset attached to the product.
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:155-159` creates image assets with initial status `UPLOADED`.
- `apps/api/app/Modules/Media/Application/Jobs/GenerateRenditions.php:88-94` moves assets through Processing and then Ready only after rendition generation succeeds.
- `apps/api/app/Modules/Media/Infrastructure/Persistence/EloquentMediaAttachmentRepository.php:29-37` read queries only include attachments whose asset status is Ready.

Explanation:
The proposed checksum backstop only compares against READY assets. If a duplicate asset from an earlier or concurrent run is still Uploaded or Processing, the backstop will not see it. If the current asset is identified as duplicate, the spec says "detach/skip" but not "delete the just-created asset and object," which overlaps with the orphan issue above.

Impact:
Concurrent runs can still produce duplicates until renditions complete. Retry behavior after a rendition failure is also ambiguous because Failed/Uploaded/Processing duplicates are outside the stated checksum check.

Minimal fix direction:
Make duplicate detection include non-deleted assets in Uploaded/Processing/Ready states for the same product/source or checksum, and specify exact cleanup for the loser asset and its storage.

### IMG-PERSIST-MAJOR-05

Severity: MAJOR  
Area: SSRF/failure-mode  
Title: Path A image descriptors are not normalized before the proposed fetcher consumes them

Evidence:
- `apps/api/app/Modules/PlatformIntegration/Domain/ValueObjects/PlatformProductData.php:41-52` assigns `images: $data['images'] ?? []` without validating each descriptor.
- `apps/api/app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php:160-169` passes `$product->images` directly into `CatalogProductDTO`.
- `apps/api/app/Modules/Product/Application/Services/CatalogEnrichmentService.php:106-113` persists `$catalog->images` directly into `EnrichedProductData`.
- `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:368-391` shows Path B has a sanitizer that emits only nullable strings for `url`, `thumbnail`, and `type`.
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:65-70` types the persister input as descriptors with `url/thumbnail/type` and then chooses `url` or `thumbnail`.

Explanation:
The spec says the image descriptor shape exists everywhere, but Path A relies on PHPDoc/static shape only. Runtime data from the platform lookup can include malformed descriptors, missing keys, arrays instead of strings, excessively large descriptor arrays, or unexpected fields. The proposed persister/fetcher is security-critical and should not depend on the platform adapter having already produced a clean shape when current Path A does not enforce that.

Impact:
Malformed platform payloads can crash the queued image job, poison retries, or bypass assumptions in URL selection and logging. For SSRF-sensitive code, input normalization should be explicit at the boundary.

Minimal fix direction:
Reuse or extract the `imagesFromPayload()` normalization logic for Path A before dispatching image persistence, and make the persister treat all descriptor values as untrusted `mixed` until normalized.

### IMG-PERSIST-MAJOR-06

Severity: MAJOR  
Area: other/security  
Title: The design says to reuse `guardExternalUrl()`, but that method is private

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:55` says `RemoteImageFetcher` should reuse `guardExternalUrl($url)`.
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:296` declares `guardExternalUrl(string $url): void` as `private`.

Explanation:
The stated reuse cannot compile unless the method is moved, made accessible, or duplicated. Duplicating it would be risky because the new fetch path is security-critical and can drift from the external-URL registration guard.

Impact:
Implementation may silently fork URL validation rules, or a planner may miss the required extraction/refactor.

Minimal fix direction:
Extract the structural URL guard into a small shared service/value object used by both `MediaUploadService::registerExternalUrl()` and `RemoteImageFetcher`.

### IMG-PERSIST-MAJOR-07

Severity: MAJOR  
Area: tenancy/queue-coverage  
Title: `enrichment:run` is underspecified for tenant context, and the existing catalog job does not carry tenant id or self-select its queue

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:98-100` proposes `php artisan enrichment:run {tenant}` dispatching `ApplyCatalogEnrichmentJob` for products in that tenant.
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:104-106` labels `ApplyCatalogEnrichmentJob` as queue `enrichment`.
- `apps/api/app/Modules/Product/Application/Jobs/ApplyCatalogEnrichmentJob.php:37-42` constructor carries product id, expected platform product id, barcode, and vertical, but no tenant id.
- `apps/api/app/Modules/Product/Application/Jobs/ApplyCatalogEnrichmentJob.php:18-20` documents that the job relies on tenant database context restored by `QueueTenancyBootstrapper`.
- `apps/api/app/Modules/Product/Application/Jobs/ApplyCatalogEnrichmentJob.php:44-47` loads the product by `Product::query()->find($this->productId)` only.
- `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:543-549` shows the current create flow explicitly adds `->onQueue('enrichment')` at the callsite.
- `apps/api/config/horizon.php:201-210` confirms both `default` and `enrichment` are consumed, so this is not an unconsumed-queue bug, but it is still a behavior/intent drift risk.

Explanation:
The proposed console command is the first bulk trigger for existing products. The existing job is safe only when dispatched from an initialized tenant context that queue tenancy can serialize and restore. The spec does not say `enrichment:run` initializes Stancl tenancy before querying/dispatching, nor does it say the command calls `->onQueue('enrichment')`. Because the job itself does not carry `tenantId` and does not call `onQueue()` in its constructor, those details must be explicit in the command design.

Impact:
The command can dispatch jobs that cannot find tenant products in workers, or can route jobs to `default` rather than the intended `enrichment` queue. `default` is consumed, but the design's operational queue isolation and observability would be wrong.

Minimal fix direction:
Specify tenant initialization/rebinding in `enrichment:run`, or introduce a tenant-explicit catalog job. Also require the command callsite to dispatch `ApplyCatalogEnrichmentJob` with `->onQueue('enrichment')`.

### IMG-PERSIST-MINOR-08

Severity: MINOR  
Area: other  
Title: Spec claim about `accept()` field handling is slightly stale for brand

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:19` says the `match` over accepted fields handles `name/description/barcode/brand`.
- `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:150-160` shows the `match` handles only `name`, `description`, and `barcode`.
- `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:166-177` handles brand in a separate `in_array('brand', ...)` block.

Explanation:
The behavioral conclusion is still correct that there is no `images` case, but the citation overstates what the `match` itself does.

Minimal fix direction:
Update the wording during planning to say scalar fields are handled in the match and brand is handled by the separate brand resolution block.

### IMG-PERSIST-MINOR-09

Severity: MINOR  
Area: scope  
Title: The spec names the media asset system as `Catalog/Domain/Media`, but current code lives under `Modules/Media`

Evidence:
- `docs/superpowers/specs/2026-07-08-enrichment-image-persistence-design.md:96` says "this asset system (`Catalog/Domain/Media`) is the unification target."
- `apps/api/app/Modules/Media/Domain/Media/MediaAsset.php:5-15` shows `MediaAsset` is in `App\Modules\Media\Domain\Media`.
- `apps/api/app/Modules/Catalog/Application/Queries/CatalogMediaQuery.php:5-13` shows Catalog consumes media through a query/contract layer, but the asset domain model is not under Catalog.

Explanation:
This is a naming/scope drift, not a functional blocker. It matters because the design places new fetcher/persister responsibilities across Product, Media, and Catalog boundaries.

Minimal fix direction:
Use current module names in the plan: media assets are in `Modules/Media`; Catalog currently exposes product-media query DTOs.

## 3. Spec Claims Verified As Correct

- Path A citation is correct: `CatalogEnrichmentService::applyCatalogHit()` starts at `apps/api/app/Modules/Product/Application/Services/CatalogEnrichmentService.php:26`; it updates product fields at `:55-98`, creates an accepted `EnrichmentResult` at `:100-128`, and copies `images: $catalog->images` at `:112`.
- Path A dispatch citation is correct: `ProductController::store()` dispatches `ApplyCatalogEnrichmentJob` when `platform_product_id`, `barcode`, and platform vertical exist at `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:539-550`.
- Path B core citation is correct: `EnrichmentReviewService::fetchAndStore()` starts at `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:38`, and `accept()` starts at `:126`.
- Path B images parser citation is correct: `imagesFromPayload()` is at `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:368-391` and emits nullable string fields.
- Image DTO shape citation is correct at the PHPDoc/constructor level: `apps/api/app/Modules/Product/Application/DTOs/EnrichedProductData.php:16` documents the shape and `:26` stores `public array $images`.
- `MediaUploadService::uploadForProduct()` signature is correct at `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:217-222`.
- Image uploads are stored before asset creation, assets compute checksum at `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:131-132`, and image rendition jobs are dispatched after commit at `:190-195`.
- `GenerateRenditions` self-selects the `images` queue at `apps/api/app/Modules/Media/Application/Jobs/GenerateRenditions.php:60-65`.
- `registerExternalUrl()` stores the URL verbatim without fetching at `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:242-256`.
- Current URL guard behavior is correctly described: `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:296-337` validates URL parseability, HTTPS, length, literal localhost/loopback, and private IPv4 string prefixes, but does not DNS-resolve.
- ZIP importer pattern is correct: `apps/api/app/Modules/Product/Application/Services/ProductImageImportService.php:249-256` creates an `UploadedFile`, then `:259-275` uploads and attaches it as Primary.
- `MediaRole` enum cases match the spec at `apps/api/app/Modules/Media/Domain/Enums/MediaRole.php:7-18`.
- `MediaAttachmentService::attach()` signature and behavior are correct at `apps/api/app/Modules/Media/Application/Services/MediaAttachmentService.php:48-79`.
- The actual PostgreSQL partial unique index is `media_attachments_one_primary` at `apps/api/database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php:32-35`.
- Product image editor promotion is correctly cited at `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductMediaController.php:149-167`.
- Current `media_assets` schema has `source`, `external_url`, indexed `checksum`, and no provenance/source-ref column at `apps/api/database/migrations/tenant/2026_06_12_100001_create_media_assets_table.php:13-37`; `MediaAsset::$fillable` likewise has no `source_ref` at `apps/api/app/Modules/Media/Domain/Media/MediaAsset.php:22-26`.
- Horizon covers `default`, `fiscal-projections`, `enrichment`, `images`, `imports`, and `ingestion` at `apps/api/config/horizon.php:201-210`; `HorizonQueueCoverageTest` scans `onQueue()` literals and compares them with `horizon.defaults.*.queue` at `apps/api/tests/Unit/Config/HorizonQueueCoverageTest.php:28-82`.
- POS/product image URL claim is correct: `ProductData` exposes `primary_image_url` at `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:55` and fills it from `ProductMediaData` at `:104`; POS sync emits `image_url` from media at `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:74-89`.
- POS `sm` rendition support exists: `CatalogMediaQuery` resolves media with variant `sm` at `apps/api/app/Modules/Catalog/Application/Queries/CatalogMediaQuery.php:48-53`; `MediaStorageAdapter` maps `sm` to `RenditionName::Thumbnail` at `apps/api/app/Modules/Media/Infrastructure/Storage/MediaStorageAdapter.php:40-42`; `RenditionService` generates `THUMBNAIL`, `SMALL`, and `WEB` at `apps/api/app/Modules/Media/Application/Services/RenditionService.php:33-37`.

## 4. UNVERIFIED

- `REALIGNMENT-LOG.md` and the claim that approximately 190 parapharmacy products carry featured images: I could not find a `REALIGNMENT-LOG` file under `/Users/houssamr/Projects/syneriva/apps/erp`.
- `project_media_unification_strategy`: I could not find a file or symbol with this name under the repo path, so I could not verify the claimed column-name coordination.
- `apps/platform` source image availability and `pharma-shop.tn` fetchability: this review was limited to the `apps/erp` repo and did not perform network calls.
- The proposed classes `RemoteImageFetcher`, `EnrichmentImagePersister`, `EnrichmentImagePolicy`, `PersistEnrichmentImagesJob`, and `enrichment:run` do not exist yet in the current repo, so their exact implementation behavior is necessarily unverified. Findings above review the design against current code constraints.
