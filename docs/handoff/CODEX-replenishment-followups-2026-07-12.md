# Codex Handover — Replenishment Follow-ups (heavier items) — 2026-07-12

> Autonomous Codex-desktop work, one branch per wave off `dev`, same operating rules as the replenishment run (see `docs/superpowers/plans/2026-07-10-replenishment-requests.md` Global Constraints — they apply verbatim: TDD, targeted suites only, snake_case wire, tokens, tenantScopedKey for QUERY keys / bare prefixes for INVALIDATION filters, `claude -p --model opus` adjudication for routine plan-vs-reality stops, HARD-STOP gate at the end of each wave for external Opus review).
> Context: the replenishment feature shipped to origin/dev @ `a80854f7f` (absorbed into `18ad9e67c`); full review record in `docs/superpowers/specs/reviews/2026-07-10-replenishment-requests-adversarial-review.md` (Rounds 1–7). Light follow-ups (invalidation sweep, transfer-detail batch rendering, POS outbox retention) are being handled by Claude agents — check `git branch --list 'chore/*' 'feat/transfer-batch*'` and MEMORY before starting to avoid overlap.

## Wave A — FE permission-map drift consolidation (the recurring foot-gun)

**Problem (bit twice already):** `apps/web/src/hooks/usePermissions.ts` hardcodes a PERMISSIONS→roles map that silently diverges from `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (Gate C: operator couldn't see the replenishment nav; earlier: three replenishment permissions missing entirely). The file's own header TODO acknowledges the drift.

**Deliverable:** a generator + CI guard so the map can never silently drift again.
1. `apps/api` artisan command `permissions:export-frontend-map` — walks the seeder's role→permission grants (execute the seeder's data structure, NOT a DB read, so it works without a tenant) and emits a generated TS file (e.g. `apps/web/src/hooks/permissionsMap.generated.ts`) with the map + a hash header. Deterministic output (sorted).
2. `usePermissions.ts` imports the generated map; hand-written entries removed; `MODULE_PERMISSIONS` stays hand-authored (UI grouping is a FE concern) but its permission KEYS must exist in the generated map (compile-time via `keyof`).
3. CI/preflight check: regenerate + `git diff --exit-code` on the generated file (pattern: the existing types-in-sync preflight check).
4. Migration note: the generated map may LEGITIMATELY differ from today's hand map — diff them first, list every behavioral visibility change (role gains/loses a nav/UI gate), and STOP for owner review of that list before committing (visibility changes are product decisions).

## Wave B — Pagination-meta consolidation

10+ features each inline an anonymous `{current_page, per_page, total, last_page}` meta type (stock-transfers, expenses, income, loyalty, compliance, crm, admin, scheduling, catalog, replenishment's `ReplenishmentPaginationMeta`…). Create ONE shared exported `OffsetPaginationMeta` (in `apps/web/src/types/` or `lib/`), with `from/to: number | null` included, migrate all features' response types to it (type-level change only — zero runtime edits), delete the local duplicates (keep deprecated aliases only if a barrel exports one publicly). Typecheck is the main gate; run each touched feature's vitest by path.

## Wave C — POS refill sheet: server-side quantity suggestion

Gate B accepted the POS sheet having no min/max suggestion (device lacks the data). Close it server-side: the POS pull feed (`ReplenishmentQueryService::feedForLocation`) additionally returns, per open line's product, a `suggested_qty` computed order-up-to-max at the requesting location (`max_quantity − available`, fallback `min_quantity − available`, floor 1 — same rule as the web dialog, spec §5/GC-3). Device: store it in `open_replenishment_cache` (new column → **SQLite migration version bump, check current max first**) and prefill the sheet's qty input when present (still editable/optional). Wire contract stays snake_case; update `ServerReplenishmentRow` + the sync-service envelope guard + tests both sides.

## Wave D — Multi-batch FEFO demo data + browser exercise

Backend tests pin multi-batch FEFO splits, but the demo tenant has single-batch products so the browser flow never exercised a split. Extend `DemoPharmacySeeder` (or a dedicated additive seeder) so 3–5 batch-tracked products carry 2–3 batches with staggered expiries at the warehouse; then a Playwright pass: replenishment create-transfer for qty spanning two batches → transfer detail shows both allocations earliest-expiry-first (requires the batch-allocation rendering follow-up to have merged — check first). Screenshot artifact.

## Explicitly OUT of scope here
- Per-location reorder policy / min-max unification (G11) — belongs to the multi-location design session (`docs/superpowers/audits/2026-07-09-multi-location-management-audit.md`).
- Franchise/intercompany fulfillment, partial-quantity settlement, notifications — spec §10 backlog, need owner prioritization.
- Anything touching fiscal surfaces.

## Deploy notes for whatever merges
Standard owes: `tenants:migrate` (Wave C adds a POS sqlite migration — device update), seeder re-run only if permissions change (none planned), `permission:cache-reset` if reseeded. Wave A changes no backend behavior.
