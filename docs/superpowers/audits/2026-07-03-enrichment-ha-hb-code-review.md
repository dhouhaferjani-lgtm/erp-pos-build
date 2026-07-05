# Adversarial code review — feat/enrichment-ha-hb (2026-07-03)

**Scope:** full branch diff `origin/dev...HEAD` (10 commits, worktree `apps/erp.enrichment-loop`) vs plan `docs/superpowers/plans/2026-07-03-enrichment-ha-hb.md` and handovers H-A (`2026-07-02-enrichment-ha-found-path.md`) / H-B (`...-hb-capture-panel.md`).
**Context:** 64 BE / 88 FE tests green, PHPStan L8 clean, typecheck clean. This review hunts what tests didn't catch. All claims verified against code with file:line citations (paths relative to the worktree root).

**Verdict: NOT mergeable as-is.** No BLOCKER, but 4 MAJORs — two are silent data-loss paths (job clears a valid backlink on transient platform failure; edit-mode capture panel discards uploads), one is a validation regression outside plan scope, one is user-visible broken French across every new string.

---

## MAJOR findings

### MAJOR-1 — ApplyCatalogEnrichmentJob destroys a valid backlink on *transient* platform failure (and never logs the miss path)
`apps/api/app/Modules/Product/Application/Jobs/ApplyCatalogEnrichmentJob.php:44-48` clears `platform_product_id` whenever `lookupCatalogProduct()` returns null. But the adapter collapses three distinct outcomes into null (`BarcodeLookupService.php:99-104` — anything with `status !== 'found'`): genuine not_found, `platform_unavailable` (circuit open, `BarcodeLookupService.php:56-58`), and `platform_error` (exception path, `:89-96`). Error results are **not cached**, so when the 1h FOUND cache has lapsed (queue backlog, cache flush, deploy restart) and the platform is briefly down, the job permanently strips a correct backlink and never applies enrichment — no retry, and contrary to locked decision 1 ("the job logs and exits") the null path has **no log line at all** (only the mismatch path logs, `:53`).
**Fix:** distinguish miss vs error in the contract (nullable DTO + thrown `PlatformUnavailableException`, or a small result enum). On error: log + `release()` (bounded tries) or exit *without* touching `platform_product_id`. On genuine not_found: clear + log info.

### MAJOR-2 — CreateProductRequest opening-qty guard silently skips for JSON-number input (out-of-plan regression)
`apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:94-99`: the rewritten `$hasPositiveQty` handles `is_int` and numeric strings only. `opening_qty`'s rules are `['nullable','numeric','min:0','regex:...]` (`:213`), so a JSON float (`"opening_qty": 5.5`) passes validation — then `is_int(5.5)` and `is_string(5.5)` are both false → `$hasPositiveQty = false` → the "opening cost required with positive qty" error is **no longer raised**. The pre-branch code (`$qty !== null && $qty !== '' && bccomp((string) $qty, '0', 4) > 0`) caught this. FE sends strings so the UI path is safe, but any API client sending native JSON numbers now bypasses a costing guard. No plan task touches this block (scope creep, rule 4).
**Fix:** `if (is_numeric($qty)) { $hasPositiveQty = bccomp((string) $qty, '0', 4) > 0; }` — covers int/float/numeric-string and still rejects arrays.

### MAJOR-3 — Capture panel renders in EDIT mode, but the enrichment submit path is create-only → photo uploads silently discarded
`apps/web/src/features/inventory/ProductForm.tsx:~780`: the opt-in block + `<EnrichmentCapturePanel>` render whenever `lookupState === 'not_found' && enrichmentOptIn`. `BarcodeHero` is mounted unconditionally (`:761`), so editing/scanning a new barcode on an existing product reaches `not_found` in edit mode. But `onSubmit`'s edit branch (`:485`) returns before the not_found submission block (`:519+`, which uses `created.id` from `createMutation`). Result: in edit mode a user can upload up to 2 photos (real presigned PUTs, platform photo records minted), enter brand/attributes, hit Save — and everything is silently thrown away, no submission, no message. The dead opt-in checkbox pre-existed; the panel upgrades it to active data loss.
**Fix:** gate the panel (and ideally the opt-in block) on `!isEditing`, or wire an edit-mode submit path.

### MAJOR-4 — Every new French string is missing its diacritics
`apps/web/src/locales/fr/inventory.json:15,20-41`: `"deja lie a"`, `"aide a identifier"`, `"jusqu'a deux photos"`, `"cote code-barres"`, `"a echoue"`, `"quand meme"`, `"indiquee"`, `"Details supplementaires"`, `"pret"`, `"donnees ... pretes"`, `"applique"` — all should carry accents (`déjà lié à`, `côté`, `échoué`, `prêt`, `données`, …). Neighboring pre-existing keys in the same file are properly accented (`"enregistré"`, `"échoué"`, `"Confiance"`), so this is new-copy-only and user-visible in the primary launch language (France/Tunisia).
**Fix:** re-accent all 23 new FR values.

---

## MINOR findings

### MINOR-1 — Ready card self-dismisses if the product query refetches while `phase='ready'`
`apps/web/src/features/inventory/hooks/useEnrichmentFastPath.ts:26-28` resets to `idle` whenever `enabled` flips false. Consumers gate `enabled` on `enrichment_status === 'pending'` (`ProductDetailPage.tsx:111-115`, `ProductForm.tsx:311-315`), and the hook's own successful poll persists `completed` server-side (`ManualEnrichmentRefreshService.php:50`) *without* updating the FE cache — so the card survives only until anything refetches the product query (cost-price realtime event → `useProductRealtime` invalidates the exact same key, `useProductRealtime.ts:83-86`; any sibling mutation). `refetchOnWindowFocus` is globally false (`apps/web/src/lib/queryClient.ts:8`), so the mainline holds, but the state is fragile. **Fix:** latch — once `ready`, ignore subsequent `enabled=false` until accept/unmount.

### MINOR-2 — Catalog-apply idempotency is check-then-insert; concurrent redelivery can double-insert
`apps/api/app/Modules/Product/Application/Services/CatalogEnrichmentService.php:34-42`: the `alreadyApplied` guard runs in a plain transaction with no row lock and no backing unique constraint (`tracking_id` NULLs are distinct under the unique index). Two workers running a redelivered job concurrently both pass the check and create two accepted rows. Low likelihood, cheap to close: `lockForUpdate()` on the product row, or a partial unique index on `(product_id) WHERE enrichment_quality = 'catalog' AND status = 'accepted'`.

### MINOR-3 — `category` never sent on not_found submit (handover H-B item 1 / plan Task 11 deviation)
Handover H-B lists category among the optional capture fields, and plan Task 11 specified `if (selectedCategoryName) payload.category = selectedCategoryName`. The built payload (`ProductForm.tsx:522-538`) has no `category` key; the BE accepts one (`ProductSubmissionController.php:43`). Silent contract narrowing.

### MINOR-4 — `attributes` values unvalidated server-side
`ProductSubmissionController.php:48`: `'attributes' => ['sometimes','nullable','array']` with no element rule — arbitrarily nested arrays are forwarded verbatim to the platform. FE sends `Record<string, string>`. Add `'attributes.*' => ['string','max:255']` (or scalar) to pin the contract.

### MINOR-5 — Backlink persists unverified when created without a barcode; uuid-case can defeat the holder comparison
`ProductController.php:515-524`: the verification job dispatches only when **both** `platform_product_id` and `barcode` are strings. A client POSTing `platform_product_id` with no barcode persists a never-verified backlink forever (the `uuid` rule accepts any well-formed uuid — no existence check by design, but the job was the compensating control). Separately, `ProductSubmissionController.php:102` compares `$holder->productId !== $validated['product_id']` — Laravel's `uuid` rule accepts uppercase, DB stores lowercase, so an uppercase-uuid client gets a spurious 409 on its own product. Normalize with `Str::lower()` (or `strcasecmp`).

### MINOR-6 — Cross-company (same-tenant) tracking collision yields an unexplained generic 409
The 1h lookup cache is tenant-scoped (CacheTenancyBootstrapper enabled, `apps/api/config/tenancy.php:38-44`) but shared across companies within a tenant, and cached not_found results carry a `tracking_id` (`BarcodeLookupService.php:83-87`, pre-existing). If company A and company B in one tenant both submit the same barcode, the second correlate hits the tenant-wide `platform_submission_id` unique constraint; the company-scoped holder re-query (`ProductSubmissionController.php:118-120`) correctly returns null (no name leak — good), but the user gets a bare 409 with no actionable detail and the FE shows nothing (only `invalid_barcode`/`enrichment_tracking_conflict` with details are handled). Acceptable for launch; note it in the module doc.

---

## NITs

- **NIT-1** `useEnrichmentFastPath.ts:50-51`: refresh returns `completed` but no `pending_review` row (e.g., already reviewed elsewhere) → phase `timeout` → copy says "we'll keep working" while enrichment is actually done. A distinct `done` phase (or reuse the accepted toast) would be truthful.
- **NIT-2** `EnrichmentCapturePanel.tsx:177`: attribute rows keyed by array index with mid-list removal — controlled inputs keep it correct today, but fragile; use a generated row id.
- **NIT-3** `ProductForm.tsx:559` (`toastSavedWithEnrichment`) fires before `submissionMutation` settles; on 409/422 the user sees success then error. Pre-existing pattern, now more visible with the conflict toast.
- **NIT-4** `CatalogEnrichmentService.php:36,100`: `enrichment_quality => 'catalog'` is a new magic string on a type-ish column (rule 9). Existing code also uses raw strings (`'unknown'`, `EnrichmentReviewService.php:64`) — consistent drift, but a backed enum would have been the right move for new code.
- **NIT-5** `ApplyCatalogEnrichmentJob` pulls in `SerializesModels` with no model properties — harmless dead weight.

---

## Plan/handover conformance walk (required behaviors)

**H-A:** (1) backlink rule + FE payload + persistence — DELIVERED (`CreateProductRequest.php:208`, `ProductForm.tsx:403-415`, `CreateProductPlatformBacklinkTest.php`). (2) auto-accepted result / brand upsert `brand_source=enriched` / ingredients / `enrichment_status=completed` / no review-queue row — DELIVERED via new `CatalogEnrichmentService` (locked decision 2), brand only when `brand_id` null (locked decision 4), ingredients with explicit pivot uuid + `order` (`CatalogEnrichmentService.php:150-155`, pivot PK verified NOT NULL no-default in migration `2026_01_08_174850`). (3) images from URL — unchanged. (4) types regenerated — `generated.d.ts` committed. Stale comment at `EnrichmentReviewService.php:88` fixed as instructed.
**H-B:** (1) photo-first panel — DELIVERED except **category pass-through (MINOR-3)** and the **edit-mode dead-end (MAJOR-3)**. (2) nullable brand DTO+controller — DELIVERED (`ProductSubmissionData.php:19`, controller `:41`). (3) normalized barcode server-side — DELIVERED per locked decision 5 (`BarcodeNormalizer` verbatim extraction, controller normalizes at `:52-60`, FE sends raw and does NOT double-normalize — `ProductForm.tsx:524`). (4) correlation pre-check + 409 with holder + link — DELIVERED (`ProductSubmissionController.php:100-103,131-147`; unique-violation catch retained as race backstop `:118-120`). (5) 45s fast-path polling 3/5/8/13/21s, terminal/timeout/unmount handling, apply via review accept path — DELIVERED (`useEnrichmentFastPath.ts`, `EnrichmentReadyCard.tsx:16,32`), with the null-safe `accept()` guards landed (`EnrichmentReviewService.php:99-104`; brand already guarded by `filled()` at `:112`).

## Verified spot-checks (clean)

- **Module boundaries:** PlatformIntegration ↔ Product only via `Shared/Contracts` + `Shared/DTOs` (`CatalogLookupInterface`, `TrackingIdHolderDTO`); job consumes the contract, correlator implemented Product-side; no cross-module model imports anywhere in the diff.
- **Authz:** `upload-url` sits in the `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` group (`routes.php:24,92-93`) + explicit `enrichment.submit` check (`PhotoUploadUrlController.php:21`); size/type validation matches the 5 MB / image-only contract.
- **`product_id` filter cannot cross companies:** `listForReview` always applies `tenant_id` + `company_id` before the product filter (`EnrichmentReviewService.php:181-198`); invalid uuid → 422 via `['sometimes','uuid']` (no PG uuid-500).
- **409 does not leak names across companies:** `findTrackingIdHolder` is company-scoped (`ProductEnrichmentCorrelationService.php:47-60`); cross-company conflict degrades to a detail-less 409.
- **Tenancy:** `QueueTenancyBootstrapper` + `CacheTenancyBootstrapper` enabled (`config/tenancy.php:38-44`); job never touches `CompanyContext` and always passes vertical explicitly (`lookup()` only reads CompanyContext when vertical is null — `BarcodeLookupService.php:37-39`), so it is queue-safe per rule 20.
- **`enrichment` queue** is in Horizon defaults (`config/horizon.php:209`) — rule 20 named-queue trap avoided.
- **Events:** zero Event-class changes in the branch diff (rule 8 clean).
- **Precision (rule 19):** no `parseFloat`/`Number()` on money/qty in any new FE code; no money handling introduced (only the opening-qty guard rewrite, flagged as MAJOR-2).
- **i18n:** all 23 new keys present in en/fr/ar (grep-verified per key per file); ar values are real Arabic; `t('actions.view')` resolves via defaultNS `common` (`i18n.ts:390`, `common.json` `actions.view`); `trackingConflict` interpolates `{{name}}` in all three. (FR accent quality → MAJOR-4.)
- **Design tokens:** `EnrichmentCapturePanel`/`EnrichmentReadyCard` use `tokens`/`colors`/`textColors`/`borderColors` exclusively; all referenced token paths exist (`designTokens.ts:375-378,412-418`); no hardcoded Tailwind colors in new components.
