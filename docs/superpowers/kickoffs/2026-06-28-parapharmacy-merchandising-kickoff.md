# Session Kickoff — Parapharmacy Merchandising (data + UI, end-to-end)

> Paste the block below into a fresh Claude Code session started **inside `apps/erp/`**.
> It implements the plan in `docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-handover.md`.

---

You are implementing the **Parapharmacy Merchandising** feature end-to-end (backend model + seeded demo data + POS offline sync + POS UI) for the IziPOS parapharmacy vertical (Tunisia, French, cash-first).

**The full plan is the handover doc — read it first, in full:**
`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-handover.md`
Also read `apps/erp/CLAUDE.md` and `.claude/context/architecture.md` (hexagonal, db-per-tenant, conventions).

Scope: product **brand**, **skin type** (customer field + product suitability), **routine membership**, **equivalents/complements** relations — wired into the redesigned POS (Filtres drawer, skin-advice bar, product-detail Équivalents/Compléments/Routine tabs, customer skin-type capture). Build full-stack with **seeded real demo data** so it's demoable now and user-populatable; Synerivia-platform enrichment comes later.

## PRE-FLIGHT ASSERTIONS — run BEFORE writing any code
The handover was written 2026-06-28 from a code scan; the code may have moved. Verify each item; **if any assertion fails, STOP and reconcile the plan before coding.** Report findings.

1. **Isolation:** `git fetch origin dev`; create a dedicated `git worktree` off `origin/dev` (e.g. `../erp.parapharm`). Never edit/commit on shared `dev`; never push to `dev` (dev-push-guard hook). Confirm the worktree's `node_modules`/vendor are real (not stale symlinks — see `project_izipos_worktree_backend_env_gotchas`).
2. **Parapharmacy metadata** is a 1:1 table: `apps/api/app/Modules/Product/Domain/ParapharmacyProductMetadata.php` + migration `database/migrations/tenant/2026_01_05_105259_create_parapharmacy_product_metadata_table.php`. Confirm columns, tenant-scope, and the `ParapharmacyProductMetadataData` DTO.
3. **Customer = `Partner`**: `apps/api/app/Modules/Partner/Domain/Partner.php` + `partners` migration. Confirm there is **no existing `skin_type`**.
4. **Relation precedents:** confirm the ingredient pivot pattern (`product_ingredient` + `belongsToMany withPivot`) and the JSON-array columns (`oem_numbers`, `cross_references`) on `products`. Pick the pivot pattern for equivalents/complements (handover §1.6); confirm nothing equivalent already exists.
5. **Enums:** confirm the backed-enum convention (`Product/Domain/Enums/ParapharmacyCategory.php`), and that **no `SkinType` enum exists** yet.
6. **Seeder:** confirm `database/seeders/ParapharmacySeeder.php` exists, its scale env var (`PARAPHARMACY_SEEDER_SCALE`), and the `assignIngredients()` / `DB::table()->insert()` batch pattern. Confirm which vertical/seeder entrypoint runs it.
7. **TS types:** confirm `php artisan typescript:transform`, `config/typescript-transformer.php`, `#[TypeScript]` on DTOs, output `packages/shared/types/generated.d.ts`.
8. **POS offline:** in `apps/pos` confirm the current MAX migration version in `src/lib/db/migrations.ts` (your device migration = max+1); confirm `src/lib/db/repositories/productRepository.ts` (`ProductRow`, `rowToProduct`, `upsertProducts`, `PARAMS_PER_ROW`) and `POSProduct` in `src/types/product.ts`. Decide: carry parapharmacy fields in ONE `parapharmacy_metadata` JSON column (handover §3).
9. **Shared prereq — `pullCustomers` is orphaned:** confirm `pullCustomers()` in `src/lib/customer/customerSyncService.ts` is NOT called from `runFullSync()` in `src/lib/sync/syncService.ts`. You must wire it in for customer `skin_type` to sync. **Coordination:** the loyalty session needs this too — whichever session lands first owns the wiring; the other rebases. State who owns it.
10. **Conventions:** TDD first (PHPUnit **by path — NEVER run the full suite, it crashes the laptop**; Vitest for POS); PHPStan level 8 + Pint clean on new code; constructor injection only (no `app()`); enums for all type columns; money/qty via bcmath precision contract; i18n `t()` for all POS strings; design tokens only in `apps/pos/src` (the redesign added a token system — see below).

## Coordinate with the POS redesign branch
The visual layer (atoms `Pill`/`Tab`/`ProductThumb`/`StockBadge`, theme tokens, Filtres/skin-advice/detail-tab shells) is being built on `feat/pos-caisse-redesign` (worktree `../erp.pos-caisse`; tracker `docs/superpowers/pos-caisse-redesign-tracker.yaml` phases P9/P5/P8). **Reuse those atoms; do not duplicate.** That session owns the visual layer; you own the data model + sync + the data-bound parts of the UI. Confirm whether the redesign branch has merged to dev yet and rebase accordingly.

## Then
Brainstorm/confirm → writing-plans → TDD implementation per handover build order §6. Use **Codex for adversarial CODE review per phase** (save to a file; Codex is unreliable for DOC review — use a Claude agent for any spec/plan review). Answer the handover §7 open questions with the owner if unresolved (brand string vs entity; routine authoring; skin-type canonical set).
