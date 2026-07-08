# Enrichment → Durable Product-Image Persistence

**Date:** 2026-07-08
**Status:** Rev 2 (owner-reviewed 2026-07-08) — Codex adversarial review reconciled (2 BLOCKER / 5 MAJOR / 2 MINOR); findings + dispositions in [`reviews/2026-07-08-enrichment-image-persistence-spec-review.md`](reviews/2026-07-08-enrichment-image-persistence-spec-review.md). Pending owner review of Rev 2 before planning.
**Branch:** `feat/enrichment-image-persistence` (worktree off `origin/dev`)
**Repos touched:** `apps/erp` (api only — backend feature; no web changes)

## Problem

Catalog enrichment already fetches product images from the Synerivia platform (`images[]` with absolute, fetchable URLs — ~190 parapharmacy products carry a featured image per `REALIGNMENT-LOG.md`), but **no code path ever turns those URLs into media**. The image descriptors live only inside the `EnrichmentResult.enriched_data` JSONB and are dropped on the floor. Consequently every product renders the initials/placeholder tile in the POS and product pages, because `primary_image_url` is null.

Goal: when enrichment provides images, persist them as durable, tenant-owned `MediaAsset`/`MediaAttachment` records (downloaded into MinIO, WebP renditions generated), so `primary_image_url` resolves and real images appear in the POS and product pages — without clobbering images a human uploaded or already chose.

## Current state (audited 2026-07-08, code-grounded)

Two enrichment paths, both discarding images:

- **Path A — catalog hit (auto-applies, no human review).** `CatalogEnrichmentService::applyCatalogHit(Product, CatalogProductDTO)` (`app/Modules/Product/Application/Services/CatalogEnrichmentService.php:26`) writes scalar fields onto the product (`:55-98`, `$product->update()` at `:98`) and mints an `EnrichmentResult` with `status = EnrichmentReviewStatus::Accepted` (`:100-128`). The catalog's `images` are copied into the persisted `EnrichedProductData` DTO (`:112`) but nothing downloads or attaches them. Driven by `ApplyCatalogEnrichmentJob` (`onQueue('enrichment')`, dispatched per-product at create by `ProductController::store` when the request carries `platform_product_id` + `barcode`, `ProductController.php:539-550`).
- **Path B — platform enrichment (write-then-review).** `EnrichmentReviewService::fetchAndStore()` (`:38`) only writes a `PendingReview` `EnrichmentResult`; the product is mutated when a human calls `accept(EnrichmentResult, array $acceptedFields, string $reviewedBy)` (`:126`). The `match` over accepted fields (`:150-160`) handles `name/description/barcode`; brand is applied in a separate `in_array('brand', …)` block (`:166-177`) — **neither handles `images`.** `imagesFromPayload()` (`:368-391`) parses images to `{url, thumbnail, type}` for display only.

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
- **Shared URL guard (M-06):** extract the current *private* `MediaUploadService::guardExternalUrl()` into a shared `ExternalUrlGuard` value object used by *both* `registerExternalUrl()` and `RemoteImageFetcher`, so the two paths cannot drift. It enforces https-only, length cap, and the literal/prefix host blocklist.
- **Close the SSRF hole without a TOCTOU race (B-01):** resolving the host and checking the result is *not* sufficient — the HTTP client re-resolves at connect time, so a name can rebind to a private IP between check and connect (DNS rebinding). Instead **pin the connection to a validated public IP**: resolve → reject any address in a private/link-local/loopback/CGNAT range → connect to *that exact IP* while preserving the original Host header + TLS SNI (cURL `CURLOPT_RESOLVE` / connect-to), **or** verify the connected peer IP equals the validated address before reading any body bytes. **Redirects are denied** (`CURLOPT_FOLLOWLOCATION => false`) — a redirecting image URL is treated as unfetchable rather than followed, which removes the redirect-SSRF surface entirely (chosen over a re-guarded manual hop loop; reconciled from the plan review). Because the platform legitimately returns arbitrary public source-site hosts (e.g. `pharma-shop.tn`), a static host allowlist is *not* imposed (it would break most images); private-IP denial + IP-pin is the control. An optional configurable allowlist is left as future hardening.
- **Untrusted input (M-05):** the fetcher treats the URL as an untrusted `string` and never assumes the descriptor was pre-sanitized (Path A does not sanitize — see §2 normalization).
- Content-type allowlist: `image/jpeg`, `image/png`, `image/webp` (from response header **and** a magic-byte sniff of the leading bytes — header alone is not trusted).
- Size cap (~10 MB), streamed to a temp file so an oversized body is aborted mid-stream, not buffered whole.
- Connect + read timeouts (e.g. 5s / 15s).

Tested with `Http::fake()` + a resolver seam (inject the DNS resolver so tests can assert a public host resolving to a private IP is rejected, and that the connection is pinned to the validated IP).

### 2. `EnrichmentImagePersister` (Application/Product)

`persist(Product $product, list<array{url,thumbnail,type}> $images, string $tenantId, ?string $userId): EnrichmentImagePersistOutcome`

**Input normalization (M-05):** the persister first runs the raw `images` through a shared `normalizeImageDescriptors(mixed $images): list<array{url,thumbnail,type}>` (extracted from Path B's `imagesFromPayload()` and reused by Path A, which today passes platform data un-sanitized). Every value is untrusted `mixed` until normalized: non-array items, non-string urls, and missing keys are dropped; the list is truncated to the per-run cap **before** any work.

Per normalized descriptor, in list order:
1. **Choose source URL:** prefer `url`, else `thumbnail`; skip if both empty/blank.
2. **Idempotent get-or-create asset (B-02, DB-enforced):** look up a non-deleted `MediaAsset` for this tenant with `source_ref = url`. If found, **reuse it** (no re-download — also dedups storage across products sharing an image) and go to step 5. If not, download + upload (steps 3–4); a **partial unique index `(tenant_id, source_ref) WHERE source_ref IS NOT NULL`** makes creation atomic — a concurrent worker that loses the race catches the unique violation, then re-reads and reuses the winner's asset. This replaces the racy check-then-insert.
3. **Fetch:** `RemoteImageFetcher::fetch()`. On failure, log (product id + URL), record on the outcome, and **continue** — one dead URL never aborts the rest.
4. **Materialize** an `UploadedFile` from the temp bytes (ZIP-importer pattern), then `MediaUploadService::uploadForProduct($tenantId, $productId, $uploadedFile, $userId, sourceRef: $url)` — MinIO store (+ `source_ref`) + `GenerateRenditions` on `images`.
5. **Attach idempotently:** role via `EnrichmentImagePolicy` (below); `MediaAttachmentService::attach($assetId, MediaOwnerType::Product, $productId, $role, $sort, $tenantId)`. A **unique `(tenant_id, owner_type, owner_id, media_asset_id)`** on `media_attachments` makes re-attach a no-op (catch violation → treat as already attached), so retries never double-attach.
6. **Orphan cleanup + backstop (M-03/M-04):** wrap upload→attach so that if `attach()` throws — or the checksum backstop rejects the just-created asset — the asset + its storage object + any renditions are removed via the existing media deletion path, never left orphaned. The checksum backstop compares against **all non-deleted** assets for the product (UPLOADED/PROCESSING/READY), not just READY, and explicitly deletes the loser.

Caps: at most **6** images per product per run (enforced at normalization). Temp files cleaned in a `finally` on every path (success, fetch-fail, attach-fail).

### 3. `EnrichmentImagePolicy`

Role decision, evaluated against **current** attachments plus what this run has already attached:
- Product has **no existing READY Primary** and this is the first successfully-persisted image of the run → **Primary** (auto-apply).
- Otherwise → **Gallery** (the suggested alternate). Sort order appends after existing gallery items.

This yields "auto-apply Primary when empty, else suggested alternate." The reviewable override is the existing editor's promote-to-Primary — no new surface.

### 4. `PersistEnrichmentImagesJob` (queued: `enrichment`)

Thin wrapper both paths dispatch. Loads the product by `(tenantId, productId)` (explicit tenant — jobs run with **no `CompanyContext`**, per POS cross-layer rule 20), calls the persister. `tries = 3`, `backoff = [60, 300]`. Idempotent by construction (DB-enforced dedup, §2), so retries are safe. Like `ApplyCatalogEnrichmentJob`, it relies on `QueueTenancyBootstrapper` to restore the tenant DB context captured at dispatch — so it is only ever dispatched from within an initialized tenant context.

- **Path A hook** (`CatalogEnrichmentService::applyCatalogHit`, after `$product->update()` at `:98`): if the catalog DTO has images, `PersistEnrichmentImagesJob::dispatch($tenantId, $product->id, $images)->onQueue('enrichment')`.
- **Path B hook** (`EnrichmentReviewService::accept`, after `$product->update()` at `:179`): dispatch the same job with `imagesFromPayload($enrichedData)`. Persisting on accept (not before) keeps images gated behind the human review that Path B already requires. Whether images ride along automatically or require `'images'` in `$acceptedFields` is resolved in Open Questions Q1.

Both paths dispatch the identical job so behavior, retries, and failure isolation are uniform and tested once.

### 5. Schema — provenance + DB-enforced idempotency

The asset domain lives in **`Modules/Media/Domain/Media`** (MINOR-09 — Catalog only exposes product-media *query* DTOs).

Migration on `media_assets`:
- add nullable `string source_ref` (length 2048) — the origin URL for any programmatically-sourced asset (enrichment now; import/external later);
- **partial unique index `(tenant_id, source_ref) WHERE source_ref IS NOT NULL`** — the DB-enforced idempotency guarantee for B-02 (one downloaded copy per source URL per tenant; also storage dedup).

Migration on `media_attachments`:
- **unique `(tenant_id, owner_type, owner_id, media_asset_id)`** so an asset attaches to an owner at most once (idempotent re-attach; catch violation → no-op).

Extend `MediaUploadService::uploadForProduct(..., ?string $sourceRef = null)` and its private `upload(...)` to persist `source_ref`, and add it to `MediaAsset::$fillable` — **non-breaking** (defaults null; existing callers unchanged). `source_ref` naming coordinated with the media-unification track (memory `project_media_unification_strategy`); `Modules/Media` is the unification target, so the column lands in the right place.

### 6. `enrichment:run` command

`php artisan enrichment:run {tenant} {--vertical=} {--limit=} {--only-missing-images}` — dispatches `ApplyCatalogEnrichmentJob` for products in the tenant carrying `platform_product_id` + `barcode` (Path A). `--only-missing-images` skips products that already have a Primary attachment. Produces **fresh** enrichments (new `EnrichmentResult` rows) — consistent with "new enrichments only." Demo-population entry point + general ops tool. Logs a summary (dispatched / skipped / ineligible).

**Tenancy + queue (M-07):** `ApplyCatalogEnrichmentJob` carries no `tenantId` and does **not** self-select a queue — it relies on `QueueTenancyBootstrapper` restoring the tenant DB context captured at dispatch, and its callsite pins `->onQueue('enrichment')` (`ProductController::store`). Therefore the command MUST `tenancy()->initialize($tenant)` (Stancl) *before* querying products and dispatching, and MUST dispatch each job with `->onQueue('enrichment')`. Without the former, workers can't find tenant products; without the latter, jobs silently land on `default`. Tenancy is torn down in a `finally`.

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

## Rev 2 — Codex adversarial review reconciliation

Full findings: [`reviews/2026-07-08-enrichment-image-persistence-spec-review.md`](reviews/2026-07-08-enrichment-image-persistence-spec-review.md).

| # | Sev | Finding | Disposition |
|---|---|---|---|
| 01 | BLOCKER | DNS-rebinding TOCTOU; no host policy | **Accepted** → §1: pin connection to validated public IP (Host/SNI preserved) or verify peer IP pre-body; re-guard each hop. No allowlist (arbitrary public source hosts are legit); private-IP deny + pin is the control. |
| 02 | BLOCKER | `source_ref` check-then-insert not idempotent | **Accepted** → §2/§5: partial unique `(tenant_id, source_ref)` (get-or-create, catch violation) + unique `(tenant_id, owner_type, owner_id, media_asset_id)` on attachments. |
| 03 | MAJOR | Upload+attach separate commits → orphans | **Accepted** → §2.6: cleanup path deletes asset+object+renditions on attach/dedup failure. |
| 04 | MAJOR | Checksum backstop ignores in-flight assets | **Accepted** → §2.6: backstop covers all non-deleted statuses; loser explicitly deleted. |
| 05 | MAJOR | Path A descriptors un-sanitized | **Accepted** → §2: shared `normalizeImageDescriptors()` (from `imagesFromPayload`) used by both paths; values untrusted until normalized. |
| 06 | MAJOR | `guardExternalUrl()` is private | **Accepted** → §1: extract to shared `ExternalUrlGuard` VO used by both callers. |
| 07 | MAJOR | `enrichment:run` tenancy + queue | **Accepted** → §6: `tenancy()->initialize()` before dispatch; pin `->onQueue('enrichment')` at callsite. |
| 08 | MINOR | `accept()` brand wording stale | **Accepted** → Current-state wording fixed (brand handled outside the `match`). |
| 09 | MINOR | Module path `Catalog/Domain/Media` | **Accepted** → §5: corrected to `Modules/Media/Domain/Media`. |

The three UNVERIFIED items are context outside the `apps/erp` workspace, not design gaps: the REALIGNMENT-LOG lives in the parent `syneriva` repo; `project_media_unification_strategy` is a Claude memory file; platform/`pharma-shop.tn` fetchability is a runtime/network check (covered under Prerequisites).

## Open questions (for owner at the Rev 2 gate)

1. **Path B image gating** — *proposed resolution:* enriched images persist **automatically on `accept`** (lower-risk than name/barcode, and the accept itself is the human review); if `$acceptedFields` is provided and excludes `'images'`, skip. Owner to confirm this default.
2. **`platform_product_id` on demo products** — seeded, or does `DemoPharmacySeeder` need linking to platform SKUs? Resolved during planning as a verification task (adds a seeder tweak if absent).

*(Former Q3 closed — Codex verified `GenerateRenditions` produces `sm`: `RenditionService` generates THUMBNAIL/SMALL/WEB and POS `sm` maps to Thumbnail, so downloaded assets yield POS thumbnails.)*
