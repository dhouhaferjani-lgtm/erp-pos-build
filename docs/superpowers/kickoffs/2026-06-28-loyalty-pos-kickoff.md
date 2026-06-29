# Session Kickoff — Loyalty in the POS (upgrade module, gating + offline-first)

> Paste the block below into a fresh Claude Code session started **inside `apps/erp/`**.
> Implements `docs/superpowers/specs/2026-06-28-loyalty-pos-gating-offline-handover.md`.

---

You are implementing **Loyalty in the POS** — a points-per-money-unit **upgrade module**, gated per vertical, working **offline-first**. The simplest form: customers **earn** points on purchase and the POS **displays** their balance + an earn estimate. (Redeem / pay-with-points + device-side accrual are explicitly **Phase 2 — out of scope now**.)

**Read first, in full:** `docs/superpowers/specs/2026-06-28-loyalty-pos-gating-offline-handover.md`, plus `apps/erp/CLAUDE.md`. Key fact: **most of the backend already exists** — your work is mainly POS-side UI + gating + an offline balance mirror, after landing the in-flight earn branch.

## PRE-FLIGHT ASSERTIONS — run BEFORE writing any code
Verify each; **if any fails, STOP and reconcile.** Report findings.

1. **Isolation:** `git fetch origin dev`; dedicated `git worktree` off `origin/dev`. Never push to shared `dev`.
2. **Engine exists:** confirm `apps/api/app/Modules/Loyalty/Application/Services/PointEarningService.php` implements the **Spend** rule (`points = amount × reward_value`, bcmath at currency-scale+4) and `EarningProcessingService::earnPoints()` is idempotent via `findBySourceDocument(sourceType, sourceId)`.
3. **Gating exists:** confirm `apps/api/config/verticals.php` lists **Loyalty as a `compatible_extra` for `parapharmacy`**; `app/Http/Middleware/RequireModule.php` (`module:Loyalty`) on all loyalty routes; `GET /api/v1/company/config` returns `all_enabled_modules`. Read `docs/architecture/vertical-module-gating.md`.
4. **POS module awareness:** confirm `apps/pos/src/types/companyConfig.ts`, `productStore.companyConfig`, and the existing `hasModule(config,'Menu')`-style check in `src/lib/sync/syncService.ts`. You'll add a `useHasModule('Loyalty')` selector.
5. **In-flight earn branch:** inspect `feat/loyalty-earn-per-product` (worktree `../erp.loyalty-earn`, was `@28de62f90`): `git -C ../erp.loyalty-earn log --oneline -12`. Confirm it adds the earn hook in `app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php` (after `redeemVouchers`), `SaleEarningService`, the earn-rate preview API, default 1 TND=1 pt Spend-rule seeding, module gating, and tests + its Codex review (`docs/superpowers/audits/2026-06-27-loyalty-earn-spec-codex-review.md`). **Decide: is it merged to dev?** If not, that branch must land (or rebase onto current dev) BEFORE the POS UI — it is your foundation. Coordinate merge order with the owner.
6. **Earn trigger discipline (rule 20):** earn ONLY when `receiptType===Sale && !training`; timestamp = `event_time_device` not now(); replay (same fiscal event twice) → one Earn txn. Confirm the branch honors these (its memory `project_loyalty_earn_demo_cutoff` says yes).
7. **Customer mirror / offline:** confirm `CustomerMirrorRow` (`src/lib/customer/customerTypes.ts`), `upsertCustomer` (`customerRepository.ts`), `/pos/customers/sync`. Confirm `pullCustomers()` is **orphaned** (not in `runFullSync`) — needed for the balance mirror. **Coordinate with the parapharmacy session** (it also needs this wiring); whoever lands first owns it.
8. **Conventions:** TDD (PHPUnit by path — **NEVER the full suite, it crashes the laptop**; Vitest for POS); PHPStan 8 + Pint; constructor injection; i18n; design tokens (the redesign added a token system — see below); offline-first device-authored-fiscal-event pattern.

## Offline-first decision (from handover §1)
**Phase 1 = read-only balance mirror + local earn estimate.** Earn accrues **server-side on `SALE_RECEIPT` sync** (already idempotent). The device shows: (a) cached `loyalty_balance`/`loyalty_tier` on the customer mirror (server includes in `/pos/customers/sync`), and (b) an earn **estimate** `floor(total_TTC × rate)` computed locally, with `rate` cached at login from the earn-rate API. Label balances "à jour au …" (eventually-consistent). No local accrual, no redeem.

## Coordinate with the POS redesign branch
Customer surfaces + checkout (where points display + earn line live) are being restyled on `feat/pos-caisse-redesign` (phases P4/P5/P8). Reuse its atoms (`Badge`, `KpiCard`) and gate loyalty chrome with `hasModule('Loyalty')`. Confirm rebase order vs that branch and the loyalty-earn branch.

## Then
Brainstorm/confirm → writing-plans → TDD. **Codex for CODE review per phase** (to file). Resolve handover §5 open questions with owner (auto-enrollment on customer attach? earn base TTC vs HT? stale-balance UX? merge ordering).
