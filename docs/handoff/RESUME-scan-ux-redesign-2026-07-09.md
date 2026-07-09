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
