# Codex Adversarial Review — Product Form Guided Redesign

> Reviews `docs/superpowers/specs/2026-06-20-product-form-guided-redesign-design.md`.
> Codex sandbox blocked writing into the worktree; this file was saved by Claude from Codex's returned summary. **Adjudicate each finding against code before acting.**

## Verdict: NEEDS-REWORK
The spec cannot be implemented as written within a one-day demo. Several "make optional" claims are not optional end-to-end (DB NOT NULL + DTO + generated TS), and the enriched-image / async-enrichment flow has real gaps.

## BLOCKERs
1. **SKU not optional end-to-end.** `CreateProductRequest` still `required` (CreateProductRequest.php:80–82); no derivation in the create path; `products.sku` is **NOT NULL + UNIQUE** (migration 2025_11_30_052910:20,37). Relaxing the UI alone → 422; removing validation without a derivation service → DB constraint error. **Fix:** add a server-side derivation (`sku ?: barcode ?: generated-unique`) in the create service + relax the request; keep uniqueness.
2. **Parapharmacy `category` column is NOT NULL.** Dropping `required_with` is insufficient — the DB column (parapharmacy_product_metadata migration 2026_01_05_105259:22), the DTO constructor (ParapharmacyProductMetadataData.php:33), and the generated TS type all require non-null. **Needs a tenant migration (nullable) + DTO change + `php artisan typescript:transform`.** This is the biggest timeline hit.
3. **Async enrichment not linked to the created product.** The submission tracking id is discarded (ProductForm.tsx:294); the webhook listener resolves by `platform_submission_id` which is never stored (ProcessEnrichmentEventListener.php:32) → enrichment callback silently finds nothing. **Fix:** persist `platform_submission_id` on the product at submit time.

## HIGHs
- No `POST /products/{id}/images/external` endpoint exists — `registerExternalUrl` is a **private** service method, not HTTP-exposed; `ProductMediaController::store()` is file-only.
- Two-step create→attach has no error recovery (orphan product if attach fails).
- `sku = barcode` is collision-prone (barcode not unique; sku must be) — derivation must guarantee uniqueness.
- **Vertical tabs / RHF trap:** the current `Tabs` atom **unmounts inactive panels** → react-hook-form fields disappear, validation breaks, values lost on tab switch. Need an **always-mounted** (CSS show/hide) tab implementation.
- "Image shows up straight away" only works for the **synchronous found** lookup path; not-found / no-barcode products get nothing — spec conflates sync lookup with async enrichment.
- Recent payload 422 fix (`default_tax_configuration_id`, no `tax_rate`, vertical-gated parapharmacy block) must be **explicitly preserved**; spec's payload language is too loose.
- Current `Tabs` atom hardcodes colors (Rule 18 violation) + broken ARIA → a new tokenized, accessible, always-mounted vertical-tabs component is required.

## MEDIUMs
- Existing `ProductForm.test.tsx` asserts section headings + SKU field presence that will break — test rewrites unscoped.
- Touching `ParapharmacyMetadataFields.tsx` triggers Rule 18 (token migration on touched lines).

## Suggested de-scope to fit the demo (Claude's note, verify next session)
Ship the **low-risk core** tomorrow, defer the DB-touching + async bits:
- ✅ Always-mounted **vertical tabs** (new tokenized atom) + barcode-first Essentials + per-tab error badges + save-validates-all. (No DB.)
- ✅ **SKU optional** via server-side derivation service + request relax (BLOCKER 1) — contained, no migration.
- ✅ Enriched image **preview-only** (synchronous found path) — drop attach-on-save + the new endpoint (HIGH) for now.
- ⏸ **Defer** parapharmacy-category nullability (BLOCKER 2 — migration + DTO + ts:transform) → instead, simply **don't send `parapharmacy_metadata` unless populated** (avoids the required_with trip without a schema change).
- ⏸ **Defer** async-enrichment product linkage (BLOCKER 3) and attach-on-save to a follow-up.
This keeps "guided, minimal-required, barcode-first, image-visible" for the demo without the risky schema/async work.
