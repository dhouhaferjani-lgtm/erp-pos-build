# Enrichment → Durable Product-Image Persistence

**Date:** 2026-07-08
**Status:** Approved design (owner-reviewed 2026-07-08) — pending Codex adversarial review before planning
**Branch:** `feat/enrichment-image-persistence` (worktree off `origin/dev`)
**Repos touched:** `apps/erp` (api only — backend feature; no web changes)

## Problem

Catalog enrichment already fetches product images from the Synerivia platform (`images[]` with absolute, fetchable URLs — ~190 parapharmacy products carry a featured image per `REALIGNMENT-LOG.md`), but **no code path ever turns those URLs into media**. The image descriptors live only inside the `EnrichmentResult.enriched_data` JSONB and are dropped on the floor. Consequently every product renders the initials/placeholder tile in the POS and product pages, because `primary_image_url` is null.

Goal: when enrichment provides images, persist them as durable, tenant-owned `MediaAsset`/`MediaAttachment` records (downloaded into MinIO, WebP renditions generated), so `primary_image_url` resolves and real images appear in the POS and product pages — without clobbering images a human uploaded or already chose.

## Current state (audited 2026-07-08, code-grounded)

Two enrichment paths, both discarding images:

- **Path A — catalog hit (auto-applies, no human review).** `CatalogEnrichmentService::applyCatalogHit(Product, CatalogProductDTO)` (`app/Modules/Product/Application/Services/CatalogEnrichmentService.php:26`) writes scalar fields onto the product (`:55-98`, `$product->update()` at `:98`) and mints an `EnrichmentResult` with `status = EnrichmentReviewStatus::Accepted` (`:100-128`). The catalog's `images` are copied into the persisted `EnrichedProductData` DTO (`:112`) but nothing downloads or attaches them. Driven by `ApplyCatalogEnrichmentJob` (`onQueue('enrichment')`, dispatched per-product at create by `ProductController::store` when the request carries `platform_product_id` + `barcode`, `ProductController.php:539-550`).
- **Path B — platform enrichment (write-then-review).** `EnrichmentReviewService::fetchAndStore()` (`:38`) only writes a `PendingReview` `EnrichmentResult`; the product is mutated when a human calls `accept(EnrichmentResult, array $acceptedFields, string $reviewedBy)` (`:126`). The `match` over accepted fields (`:150-179`) handles `name/description/barcode/brand` — **there is no `images` case.** `imagesFromPayload()` (`:368-391`) parses images to `{url, thumbnail, type}` for display only.

Supporting facts:

- Image descriptor shape everywhere: `list<array{url: ?string, thumbnail: ?string, type: ?string}>` (`EnrichedProductData.php:16,26`).
- `MediaUploadService::uploadForProduct(string $tenantId, string $productId, UploadedFile $file, ?string $userId): MediaAsset` (`MediaUploadService.php:217`) — requires an `UploadedFile`; stores to MinIO and dispatches `GenerateRenditions` **only for image assets, after commit** (`:191-195`) on `onQueue('images')`. `registerExternalUrl(...)` (`:242`) stores a URL verbatim without fetching.
- SSRF guard `guardExternalUrl()` (`:296-337`): https-only, length ≤ 2048, blocks `localhost/127.0.0.1/::1` + RFC-1918/link-local **host strings** — but does **not** DNS-resolve, so a public hostname resolving to a private IP is not caught.
- The ZIP importer already materializes remote/loose bytes into an `UploadedFile` via a temp path: `new UploadedFile($path, $name, $mime, null, true)` (`ProductImageImportService.php:250-256`), then `uploadForProduct` + `MediaAttachmentService::attach(role: Primary, sort: 0)` (`:259-275`). This is the pattern to reuse.
- `MediaRole` cases (`MediaRole.php:7-18`): `Primary, Gallery, Datasheet, Manual, VideoPoster, Spin, Swatch, SourceDocument`. `MediaAttachmentService::attach(assetId, ownerType, ownerId, role, sort, tenantId, ?caption)` (`:48-79`) demotes an existing Primary to Gallery when attaching a new Primary; a PG partial unique index `media_attachments_one_primary` is the DB backstop.
- Promote-to-Primary already exists in the product image editor: `ProductMediaController::update()` handles `is_primary` by flipping roles directly on the model (`ProductMediaController.php:149-167`).
- **No provenance/source column.** `media_assets` has `source` (storage-type enum `UPLOAD`/`EXTERNAL_URL` only), `external_url`, and an indexed `checksum` (SHA-256 of bytes, computed at `MediaUploadService.php:132`) — but no field recording *where an image came from*, so re-enrichment would duplicate images.
- **No bulk/tenant re-enrichment trigger exists.** Enrichment only fires per-product at create. `enrichment:check-pending` polls already-pending Path-B submissions; it does not initiate enrichment.
- Horizon covers `['default','fiscal-projections','enrichment','images','imports','ingestion']` (`config/horizon.php`), enforced by `HorizonQueueCoverageTest`. `enrichment` and `images` are both covered — this design introduces no new queue.

## Decisions (owner-approved 2026-07-08)

| Decision | Choice |
|---|---|
| Apply model | **Auto-apply + reviewable override**: no existing Primary → attach enriched image as Primary; Primary exists → attach as Gallery (a suggested alternate). |
| "Override" UI | **Reuse the existing product-image editor** (`is_primary` promote). No new review UI. |
| Storage | **Download bytes into MinIO + WebP renditions** (tenant-owned, durable, small thumbnails for POS). Not hotlinking. |
| Backfill | **New enrichments only.** Do not mine old `enrichment_results` payloads. A fresh (re-)enrichment run populates images. |
| Populating the demo | Add a **`enrichment:run` bulk command** (dispatches fresh Path-A enrichments) — the only way to trigger enrichment on already-seeded products. |
| Dedup / provenance | Add a **`source_ref` column** on `media_assets` (origin URL, indexed); skip a product image whose `source_ref` already exists. `checksum` is the post-download backstop. |
| Queues | Reuse `enrichment` (persist orchestration) + `images` (renditions). No new queue. |
| Frontend | None. |

## Design

One shared persister, invoked from both enrichment paths via a queued job.

### 1. `RemoteImageFetcher` (Infrastructure/Media) — the security-critical unit

`fetch(string $url): FetchedImage` where `FetchedImage` is a DTO of `{bytes|tempPath, mime, filename, byteSize}`.

Guards (all enforced, fail-closed — throws a typed `RemoteImageFetchException` on any violation):
- Reuse `guardExternalUrl($url)` (https-only, host blocklist, length cap).
- **Close the SSRF hole:** resolve the host (DNS) and reject any resolved address in a private/link-local/loopback range — the existing guard is host-string-only. Re-validate after each redirect; cap redirects (≤ 3) and re-guard every hop's host+resolved IP.
- Content-type allowlist: `image/jpeg`, `image/png`, `image/webp` (from response header **and** a magic-byte sniff of the leading bytes — header alone is not trusted).
- Size cap (~10 MB), streamed to a temp file so an oversized body is aborted mid-stream, not buffered whole.
- Connect + read timeouts (e.g. 5s / 15s).

Tested with `Http::fake()` + a resolver seam (inject the DNS resolver so tests can assert a public host resolving to a private IP is rejected).

### 2. `EnrichmentImagePersister` (Application/Product)

`persist(Product $product, list<array{url,thumbnail,type}> $images, string $tenantId, ?string $userId): EnrichmentImagePersistOutcome`

Per descriptor, in list order:
1. **Choose source URL:** prefer `url`, else `thumbnail`; skip if both empty/blank.
2. **Dedup (pre-download):** if the product already has a `MediaAttachment` whose asset `source_ref` equals this URL → skip (idempotent re-runs).
3. **Fetch:** `RemoteImageFetcher::fetch()`. On failure, log with product id + URL, record the failure on the outcome, and **continue** — one dead URL must never abort the rest.
4. **Materialize** an `UploadedFile` from the temp bytes (ZIP-importer pattern), then `MediaUploadService::uploadForProduct($tenantId, $productId, $uploadedFile, $userId, sourceRef: $url)` — MinIO store + `GenerateRenditions` on `images`.
5. **Role via `EnrichmentImagePolicy`** (below); `MediaAttachmentService::attach($assetId, MediaOwnerType::Product, $productId, $role, $sort, $tenantId)`.
6. **Post-download dedup backstop:** if the freshly computed `checksum` already exists for a READY asset attached to this product, detach/skip the just-created duplicate.

Caps: at most **6** images persisted per product per run (defensive against a pathological payload). Cleans up temp files in a `finally`.

### 3. `EnrichmentImagePolicy`

Role decision, evaluated against **current** attachments plus what this run has already attached:
- Product has **no existing READY Primary** and this is the first successfully-persisted image of the run → **Primary** (auto-apply).
- Otherwise → **Gallery** (the suggested alternate). Sort order appends after existing gallery items.

This yields "auto-apply Primary when empty, else suggested alternate." The reviewable override is the existing editor's promote-to-Primary — no new surface.

### 4. `PersistEnrichmentImagesJob` (queued: `enrichment`)

Thin wrapper both paths dispatch. Loads the product by `(tenantId, productId)` (explicit tenant — jobs run with **no `CompanyContext`**, per POS cross-layer rule 20), calls the persister. `tries = 3`, `backoff = [60, 300]`. Idempotent by construction (dedup), so retries are safe.

- **Path A hook** (`CatalogEnrichmentService::applyCatalogHit`, after `$product->update()` at `:98`): if the catalog DTO has images, `PersistEnrichmentImagesJob::dispatch($tenantId, $product->id, $images)->onQueue('enrichment')`.
- **Path B hook** (`EnrichmentReviewService::accept`, after `$product->update()` at `:179`): dispatch the same job with `imagesFromPayload($enrichedData)`. Persisting on accept (not before) keeps images gated behind the human review that Path B already requires. Whether images ride along automatically or require `'images'` in `$acceptedFields` is resolved in Open Questions Q1.

Both paths dispatch the identical job so behavior, retries, and failure isolation are uniform and tested once.

### 5. Schema — `source_ref` on `media_assets`

Migration: add nullable `string source_ref` (length 2048) + a plain index. Records the origin URL for any programmatically-sourced asset (enrichment now; import/external later). Extend `MediaUploadService::uploadForProduct(..., ?string $sourceRef = null)` and its private `upload(...)` to persist it — **non-breaking** (defaults null; existing callers unchanged). Column name coordinated with the media-unification track (`project_media_unification_strategy`); this asset system (`Catalog/Domain/Media`) is the unification target, so the column lands in the right place.

### 6. `enrichment:run` command

`php artisan enrichment:run {tenant} {--vertical=} {--limit=} {--only-missing-images}` — dispatches `ApplyCatalogEnrichmentJob` for products in the tenant carrying `platform_product_id` + `barcode` (Path A). `--only-missing-images` skips products that already have a Primary attachment. Produces **fresh** enrichments (new `EnrichmentResult` rows) — consistent with "new enrichments only." This is the demo-population entry point and a generally useful operational tool. Logs a summary (dispatched / skipped / ineligible).

## Data flow

```
enrichment:run  ──► ApplyCatalogEnrichmentJob (queue: enrichment)
                      └─ applyCatalogHit(): writes scalars + Accepted EnrichmentResult
                         └─ dispatch PersistEnrichmentImagesJob (queue: enrichment)
                              └─ EnrichmentImagePersister
                                   ├─ dedup on source_ref
                                   ├─ RemoteImageFetcher (guarded download → temp file)
                                   ├─ uploadForProduct → MinIO (+ source_ref)
                                   │     └─ GenerateRenditions (queue: images) → WebP sm/…
                                   └─ attach(Primary if empty else Gallery)
                                        └─ primary_image_url resolves
                                             └─ POS sync + product page render the image

EnrichmentReviewService::accept()  ──► (same PersistEnrichmentImagesJob)
```

## Testing (TDD — write red first)

- **RemoteImageFetcher:** rejects `http://`; rejects `localhost`/`127.0.0.1`/RFC-1918 host strings; **rejects a public host that resolves to a private IP** (injected resolver); rejects non-image content-type despite an image extension; rejects when magic bytes don't match; aborts over the size cap; caps redirects and re-guards each hop.
- **EnrichmentImagePersister:** Primary when product has no image; Gallery when a Primary exists; idempotent (second run with same URLs creates no new assets — dedup on `source_ref`); one failing URL does not abort the others (mixed good/bad list persists the good ones); >6 images capped; temp files cleaned on both success and failure paths.
- **PersistEnrichmentImagesJob:** runs with no `CompanyContext` bound (`app(CompanyContext::class)->clear()` in the test, per rule 20); retry is idempotent.
- **Integration (Path A):** `applyCatalogHit` with an images-bearing catalog DTO → job → attachment created → `ProductData.primary_image_url` populated → `SyncController` returns it. **Path B:** `accept` with images → persisted per Q1 resolution.
- **Regression:** `HorizonQueueCoverageTest` stays green (no new queue). PHPStan level 8 clean on new code; Pint clean.

Use `Http::fake()` for downloads and a fake/local MinIO disk; no real network in tests.

## Security

The download path is the attack surface. Non-negotiables: DNS-resolution SSRF check (not just host strings), magic-byte content sniff, streamed size cap, redirect re-guarding, timeouts, https-only. Fetch failures are contained per-image and never surface bytes or internal errors to the enrichment result.

## Scope boundaries (YAGNI)

**In:** the persister, fetcher, policy, job, `source_ref` migration, both path hooks, `enrichment:run`, tests.
**Out:** backfilling old `enrichment_results` (owner: new-only); any new review/gallery UI; platform-side canonical image hosting (separate `REALIGNMENT-LOG` follow-up); gallery reordering; non-image media; automatic re-download when a source URL changes.

## Prerequisites to *see* it in the demo (verification, not design blockers)

1. `apps/platform` reachable; `services.platform.url` + `services.platform.api_key` set for the ERP.
2. `DemoPharmacySeeder` products carry `barcode` (+ ideally `platform_product_id`) matching the platform catalog — **verify during planning; if absent, a seeder tweak to link demo products to platform SKUs is a plan task.**
3. Source image URLs (e.g. `pharma-shop.tn`) are public and fetchable from the ERP host (public → allowed by the SSRF guard).
4. Run `enrichment:run <demo-tenant> --vertical=parapharmacy`, watch the `enrichment` then `images` queues, confirm `primary_image_url` populated and POS renders real cards.

## Open questions (resolve before/at planning)

1. **Path B image gating.** On `accept`, do enriched images persist automatically, or only when the reviewer includes `'images'` in `$acceptedFields`? Recommendation: **automatic on accept** (images are lower-risk than name/barcode and the accept itself is the review), but honor an explicit opt-out if `$acceptedFields` is present and excludes images. Confirm.
2. **`platform_product_id` on demo products.** Confirmed present or does the seeder need linking? (Verification task.)
3. **Rendition size for POS.** POS sync resolves the `sm` rendition — confirm `GenerateRenditions` produces `sm` for downloaded assets so the POS gets a thumbnail, not the full image.
