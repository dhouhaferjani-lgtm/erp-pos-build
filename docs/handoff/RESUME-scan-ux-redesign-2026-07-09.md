# RESUME — Scan-to-Document UX redesign (fresh implementation session)

**Start here.** Design is done + committed. This session executes it.

## What to do
1. Read the spec: `docs/superpowers/specs/2026-07-09-scan-to-document-ux-redesign.md` (self-contained: research basis, owner-locked decisions, reused components with paths, per-unit design, testing).
2. Run **superpowers:writing-plans** on that spec → then **superpowers:subagent-driven-development** (TDD per task, per-task review, whole-branch review) — same workflow that shipped the new-supplier-assist feature.
3. Work in worktree `apps/erp.scan-to-doc` (branch `feat/scan-to-document`) OR branch fresh off post-merge `dev` if the scan work has been promoted (check `git branch -r --contains` for the scan commits).

## Context you need (don't rediscover)
- The scan feature is functionally complete + merged to LOCAL dev (`e99ebd117`, NOT pushed). The 4 OCR-finalization fixes are ON origin/dev; the new-supplier-assist feature is local-only.
- **`PartnerPrefill` contract already exists** (`src/features/partners/partnerPrefill.ts`) — reuse it; the redesign just changes the create destination from full-page nav → `AddPartnerModal`. Build a **parallel `ProductPrefill`** the same way.
- Reused components (paths in spec §4): `ProductPicker`, `AddQuickProductModal` (+add prefill prop), `AddPartnerModal` (+add prefill prop), `Modal`.
- **Blank-preview root cause:** `SourceViewer` is `<img>`-only; PDFs fail. Fix = pdf.js client-side render (spec §5.3).
- Owner locked: staged-stepper primary + subtle scan flavor + non-blocking; review-primary sticky preview.

## Gotchas (from prior sessions — memory `project_scan_to_document_spec_b`, `reference_local_db_per_tenant_demo_launch`)
- Extraction is async (queue → erp-ml → Claude ~10–30s). erp-ml needs `ANTHROPIC_API_KEY` + `EXTRACTION_SERVICE_TOKEN`; the venv needs `anthropic` installed. Local stack recipe in the reference memory.
- Snake_case wire vs camelCase FE has bitten this feature 4×+ — normalize at the api boundary (`normalizeSuggestions`/`normalizeExtraction` pattern).
- Run vitest BY PATH; kill vitest zombies after (`ps aux | grep '[n]ode.*vitest'`).
- **Live-verify with a real PDF AND a real photographed/handwritten invoice** (Wikimedia CC0 proforma worked well) — clean synthetic renders hide real-world bugs.

## Definition of done
All spec §8 tests green, PHPStan/eslint/tsc clean, whole-branch review READY-TO-MERGE, merged to local dev (not pushed), live-verified (PDF + photo). Then update memory + this handoff.

---

## ✅ COMPLETE (2026-07-10, this session)

Executed end-to-end via subagent-driven development: 10 tasks + fix rounds (17 commits `e32d8f365..e3e5ecbc3`), final whole-branch review READY-TO-MERGE, **merged to LOCAL dev `da93d5f83` (NOT pushed)**. All gates green (typecheck, lint 0 errors, tenant-key audit, 101/101 tests by path). SDD ledger: `.superpowers/sdd/progress.md`.

**Live-verified on demo-pharmacy tenant** (real MTS facture PDF + real iPhone photo of a French receipt): upload page w/ specific errors + duplicate detection; processing stepper + auto-advance; **PDF renders on canvas** (pdf.js); photo blob preview + lightbox; supplier created in-page from OCR prefill and auto-selected; product created from line prefill (SKU auto-gen) with VAT autofill; machine/edited cues.

**2 blockers found live and fixed on the branch:**
- `SignedMediaController` image-only gates 404'd ALL PDF scans → now accepts Document+application/pdf (`f0b22cd0e`). Backend change, security-reviewed.
- `AddQuickProductModal` sent `sku: null` vs server `required` + swallowed 422s (pre-existing) → client-side SKU auto-gen + error display (`e3e5ecbc3`).

**Follow-ups (non-blocking, ledgered):** FE-advertised 20 MB vs server `media.documents.max_file_size` 10 MB drift; quick-create can't set `requires_batch_tracking`; Space-key activation on preview; failed-card error styling; config-driven upload limit; Document+ExternalUrl serve test.
