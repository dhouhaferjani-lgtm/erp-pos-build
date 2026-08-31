# Staging — products import "500" (tester report) + platform-image adoption on import — 2026-08-31

Target: `erp.otospex.dev` build `6bec260a2` / bundle `index-BFTVQxGg.js`. No deployment was running (Dokploy: last ERP Staging API deploy finished 2026-08-30 14:01; `origin/dev` unchanged). Probe tenant `hist-probe-mth3y5qe@test.otospex.dev` (fresh today).

## 1. The tester's 500 — REPRODUCED, root-caused (lane K-6)
- API path (`POST /imports` → preview → options → execute): **clean**, 2 products in ~3 s.
- Wizard UI path: import **completes** (campaign fixture 4/4 imported, 7.6 s) but the browser fires **`GET /api/v1/imports//preview` — empty job id — twice → HTTP 500** → toast "Server error: Internal server error. Please try again later." ×2. That is what the tester saw; the import itself succeeded.
- FE cause: `ImportWizardPage.tsx:510-514` passes `''` to `useImportPreview()` outside the validation step; `refetchPreview()` at `:616` bypasses the hook's `enabled` guard (TanStack semantics) and fires with the empty id.
- BE cause: `ImportController::preview()` (`:318-330`) queries `ImportJob::where('id', $id)` with a non-UUID → PostgreSQL type error → **500 instead of 404** (verified: `imports//preview`, `imports/not-a-uuid/preview`, `imports//errors` → 500 `INTERNAL_ERROR`; a well-formed unknown UUID → 404). Known UUID pitfall.
- Fix brief: `docs/sessions/session-K-otospex-money-2026-08-30/LANE-K6-import-preview-empty-id-500-BRIEF.md` (FE guard + `whereUuid('id')` route constraint + tests). Evidence spec: `apps/web/e2e-local/staging-products-import.spec.ts` (asserts `5xx responses=0` after the fix).

## 2. Do images from the Synerivia platform get adopted on import? — **NO (by construction); the backlink path works**
| Path | Result on staging |
|---|---|
| Products import (5 real EANs, `options.enrichment_enabled=1`) | 5 imported; **no `platform_product_id`, no `enrichment_status`, no images**. Code: `enrichment_enabled` is validated/stored (`ImportJobOptionsData.php:41,74`, `ImportController.php:122,492,1037`) but **has no consumer** — nothing in `app/Modules/Import` calls the lookup or dispatches `ApplyCatalogEnrichmentJob`. |
| Product **create with platform backlink** (`POST /products` + `barcode` + `platform_product_id`, the UI product-form flow) | **WORKS**: `ApplyCatalogEnrichmentJob` → `PersistEnrichmentImagesJob` → `enrichment_status=completed`, result `accepted` (H-A auto-accept), platform image persisted as a durable PRIMARY media asset behind the signed route within **<10 s**; renders on the product page **400×420** (Playwright `staging-adopted-image.spec.ts`). |
| Imported product → `POST /platform/submit-for-enrichment` (the "Submit for enrichment" button) | **502 `platform_unavailable`** — not an outage: staging runs `SYNERIVA_PLATFORM_PUSH_ENABLED=false` (Session J push gate); `ProductSubmissionService.php:291-295` returns null and the controller maps it to `platform_unavailable`. UX nit: the copy says the platform is down. |
| Imported product → `POST /products/{id}/enrichment/refresh` | 422 `no_pending_submission` (correct — nothing was ever submitted). |

**Platform data coverage (production `admin.synerivia.com`, reached from staging via `POST /api/v1/platform/barcode-lookup`):** 51 candidate parapharmacy EANs from repo seeders/docs → ≈40 FOUND with full attributes/INCI, but **only 2 carry images** (5903407024073 Canpol peluche, 6199106101538 Herbeos). Notably the ERP's own `DemoImageProductsSeeder` has CDN image URLs for ~10 of these EANs that the platform returns image-less. Sweep log: scratchpad `ean-sweep.txt`.

## 3. Findings
- **F-IMG-1 (P1 product gap, imports):** import never enriches — `enrichment_enabled` is a dead option. Testers importing a real catalogue get no platform data/images and must open each product to trigger enrichment (and on staging that button is gated). Lane brief K-7.
- **F-IMG-2 (P2 platform data):** production platform has images for very few parapharmacy products; adoption cannot show anything for most EANs regardless of the ERP fix — platform-side image acquisition (data-acquisition `image_processor.py` / `platform_writer.py`) is the lever.
- **F-IMG-3 (P3 UX):** under the push gate, "Submit for enrichment" reports "Platform is currently unavailable" — should say the environment has enrichment submission disabled.
- Staging Horizon: staging supervisor inherits the default queue list (`enrichment`, `images`, … — `horizon.php:209`) — confirmed live by the <10 s Path A turnaround.
