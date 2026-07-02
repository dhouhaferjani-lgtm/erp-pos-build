# CODEX Handover — Product Line-Entry Phase 1 + Product View Redesign

> **Date:** 2026-07-02 · **Demo tomorrow.** Order of work: Task 1 (line entry, highest value) → Task 2 (view screen).
> **Worker:** Codex desktop. **Reviewer/merger:** Claude orchestrator session (do NOT merge/push yourself).
> **Branch:** from `apps/erp`: `git fetch origin && git worktree add ../erp.product-ui -b feat/demo-product-ui origin/dev`. `pnpm install` at worktree root.
>
> ⚠️ Do NOT touch `apps/web/src/features/inventory/ProductForm.tsx` (owned by another merged branch — rebase risk). Editor components under `src/features/products/editor/` are reuse-only unless a read-only variant needs a prop.

## Task 1 — Line-item entry standard, Phase 1

**Source of truth: `docs/superpowers/specs/2026-07-02-line-item-entry-standard-design.md` (REVISION 2, post-Codex-adversarial-review) — read it fully, implement exactly its Phase 1 checklist.** Highlights (the doc is authoritative where this summary differs):

1. **Shared `ProductCell`** (thumbnail + name + SKU + barcode, sm/md sizes, graceful image fallback).
2. **New backend `GET /line-entry/resolve-code` endpoint** — exact code resolution with variant precedence (`product_variants.barcode` first, then products) returning `{product_id, variant_id}` or `requires_variant`; the old `?barcode=` filter is variant-blind. Small, TDD.
3. **`LineItemEntryBar`** — persistent add-row: scan auto-adds (FIFO queue for scans during in-flight lookups, stale-result tokens), re-scan increments qty, Enter-to-add, never submits the form, layout-agnostic (reads input value, not key events — AZERTY-safe). Variant contract: variant barcode → direct add; parent barcode with variants → required chooser, never silent increment.
4. **Wire into `DocumentLineEditor` ONLY (Phase 1)** — invoices/quotes/POs. `CreateStockTransferPage` is Phase 1B (its batch/FEFO auto-allocate + panel-expand contract is specified in §3.1 — implement 1B only if Phase 1 lands with time to spare). Do not expand further.

Scan-to-add currently works ONLY in POS; documents have no key handling. GS1/weighted barcodes are an explicit v1 non-goal. Two open owner questions are flagged in the doc — implement the doc's stated defaults, don't block on them.

## Task 2 — Product VIEW screen on the new editor design

Rebuild the **Details tab** of `src/features/inventory/ProductDetailPage.tsx` read-optimized on the izipos editor design language:
- Keep route + Movements/Financial tabs untouched.
- Read-only hero (image, name, barcode, SKU, status, key prices) echoing `BarcodeHero`'s language without dark inputs (build sibling `ProductHero` if needed), `EditorSectionCard` sections in a single-scroll 2-column layout: General, Pricing (cost vs sale + margin), Stock (`ProductStockLevels`), vertical-gated sections as today (`ProductDetailPage.tsx:311`).
- Prominent Edit button → editor.

## Rules (binding — repo CLAUDE.md)

- TS strict, no `any`; design tokens for all colors touched; ALL text via `t()` (i18n.ts = import+resources+ns array).
- `apiGet`/`apiPost` already unwrap; never `parseFloat` on money (`formatCurrency`/`formatQuantity`).
- TDD where testable (Vitest; rendered HTML, not class names). The scan-to-add behavior MUST have tests (simulate scanner: rapid keystrokes + Enter).
- Gates: `pnpm test` (your files), `pnpm lint`, `pnpm typecheck`.
- Verify visually with Playwright on the local stack if available (owner@pharmabio.tn/password).

## Delivery

Small logical commits on `feat/demo-product-ui`. **No merge, no push.** End with: files changed, test evidence, screenshots if any, open questions.
