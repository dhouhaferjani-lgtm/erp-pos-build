# R2-O gate — fiscal-sale fixture terminal selection (branch `fix/r2o-fixture`, commit 52d387e57, base a1952aa23)

**Reviewer:** fiscal-pos-reviewer (adversarial, code-grounded). **Scope:** narrow gate on a test/fixture-only change.
**Ticket:** `docs/superpowers/tickets/2026-08-06-c2-fixture-terminal-location.md` §51-69.

## VERDICT: spec ✅ · quality APPROVED (APPROVE — one Important non-blocking hardening recommended)

---

## 1. Diff is test/fixture-only — CONFIRMED

`git diff --name-only a1952aa23 52d387e57` returns exactly two paths:

- `apps/web/e2e/money-campaign/statement-support.ts` (+18/−2)
- `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts` (+23/−2)

Nothing under `apps/web/src/`, nothing under `apps/api`, nothing under `apps/pos`. No production code, no migration, no config.

## 2. The predicate — re-derived from the committed code, not from the implementer's script

Committed logic (money-campaign) `apps/web/e2e/money-campaign/statement-support.ts:429-446`:

```ts
const locationsRes = await request.get(`${API_BASE}/locations`, { headers })
await expectOk(locationsRes, 'locations')
const locations = ((await jsonData(locationsRes)) as unknown as Array<{ id: string; type: string }>) ?? []
const shopLocationIds = new Set(locations.filter((l) => l.type === 'shop').map((l) => l.id))
...
const template = terminals.find((t) => t.is_active && shopLocationIds.has(t.location_id))
expect(template, 'an active terminal at a SHOP location exists — a fiscal sale must never be rung at a warehouse').toBeTruthy()
```

Smoke sibling is byte-equivalent in shape at `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:588-613`.

Contract facts verified server-side:

- `GET /api/v1/locations` → `{ data: [LocationResource], meta }`, a FLAT array (not paginated):
  `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:73-91`. So `jsonData()`/`.data` yields an array — no pagination truncation risk, no double-`data` nesting.
- `type` is serialized as the enum VALUE: `apps/api/app/Modules/Company/Presentation/Resources/LocationResource.php:28` (`'type' => $this->type->value`) with `LocationType::Shop = 'shop'`,
  `LocationType::Warehouse = 'warehouse'` (`apps/api/app/Modules/Company/Domain/Enums/LocationType.php:8-13`). `'shop'` is the correct literal.
- `is_active` IS emitted on the terminal payload: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:103`. The `t.is_active` half of the predicate is not vacuously false.
- `GET /pos/terminals` orders `created_at DESC` (`apps/api/app/Modules/POS/Presentation/Controllers/TerminalController.php:60`) — this is the exact mechanism by which the ticket's contamination compounded (the newest terminal always sorts first).
- Seeded topology matches the ticket: WH-01 `LocationType::Warehouse`, `pos_enabled => false` (`apps/api/database/seeders/DemoPharmacySeeder.php:224-241`); the four `STORE-*` shops `LocationType::Shop`, `pos_enabled => true`, `is_active => true` (`.../DemoPharmacySeeder.php:308-322`).

### Scenario probes (re-derived independently)

(a) **Ticket's literal terminal table** — list order (created_at DESC): `C2-001d096a` @ WH-01 → `location_id ∉ shopLocationIds` (WH-01 is `warehouse`) → skipped. `VADMIN` @ WH-01 → skipped. `C2-7f6e8992` @ STORE-TUN1 → `is_active && shopLocationIds.has(...)` → **selected**. Warehouse contamination stops. ✅

(b) **Only-warehouse tenant** — `terminals.find(...)` → `undefined`; `expect(undefined, '…must never be rung at a warehouse').toBeTruthy()` throws (Playwright `expect` is non-soft here), so `template!.location_id` at `:453` / `:619` is unreachable. **No fallback, loud failure.** ✅

(c) **Inactive shop terminal** — excluded by the `t.is_active &&` conjunct before the location test. ✅

(d) **Self-stabilization** (not in the brief, worth recording): the terminal the fixture then creates is `is_active => true` at the VERIFIED shop (`TerminalController.php:112-129`), and it becomes the newest row. Every subsequent run therefore picks a shop terminal again. The compounding loop is now closed inside the shop set instead of escaping to the warehouse.

## 3. Smoke sibling — identical defect shape, identical fix, and it IS sufficient

- Same "first active, no location predicate" pick pre-fix (diff hunk at `smoke:607`, old line `terminals.find((candidate) => candidate.is_active)`), same clone of `location_id` onto a new terminal (`smoke:619`), same real hash-chained `SALE_RECEIPT` ingest at `POST /pos/sync/fiscal-events` (`smoke:738`). Confirmed defect shape identical.
- Its `API_BASE` is env-overridable (`smoke:18`, `TREASURY_PHASE5B_API_BASE`), so it can be aimed at a non-local (staging) tenant — which is why the loud guard, not a fallback, is the right shape here. On a tenant with no shop location it now fails at setup instead of minting a warehouse receipt. Sufficient.
- **Is extra safety needed in the spec body?** Not required. The guard validates the TEMPLATE, and the created terminal is given exactly the verified `terminalTemplate!.location_id` (`smoke:619`), so the created terminal cannot land elsewhere. An optional end-to-end closure would be to assert the PROJECTED receipt's `location_id` ∈ `shopLocationIds` after the existing movement poll, since the projection is what actually stamps location (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:346`). Nice-to-have, not a gate condition.

## 4. `wx-gate-compliance.spec.ts` non-fix — claim VERIFIED

- `MTP-CMP-02` (`apps/web/e2e/money-campaign/wx-gate-compliance.spec.ts:137-155`) creates a terminal at `locations.data[0]` and hard-deletes it in a `finally` (204 asserted `:151`, 404 asserted `:153`). No fiscal event is authored.
- `MTP-CMP-03` (`:157-167`) expects the cashier create to 403.
- Independent proof of the "never authors" claim: the ONLY two `POST …/pos/sync/fiscal-events` call sites in the whole e2e tree are `statement-support.ts:563` and `treasury-phase5b-reconciliation.smoke.ts:738`. `wx-gate-compliance.spec.ts` has none. The non-fix decision is correct.

## 5. Sweep completeness — INDEPENDENTLY RE-RUN, no misses

- `grep -rn "invoice_type_code" apps/web apps/pos scripts` (excluding `src/`, node_modules) → 2 hits, both the fixed files (`smoke:644`, `statement-support.ts:473`).
- `grep -rn "fiscal-events"` in `apps/web/e2e` → 2 hits (`smoke:738`, `statement-support.ts:563`).
- `grep -rn "pos/terminals"` in `apps/web/e2e` → the 2 fixed files + `wx-gate-compliance.spec.ts` (dispositioned in §4).
- `grep -rln "SALE_RECEIPT"` → the 2 fixed files + `w7-multilocation.spec.ts:389`, which is a COMMENT only.

**No unfixed fiscal-sale author or terminal-template pick remains in the e2e tree.**

## 6. Fiscal-safety framing — WHERE changed, WHAT signed did not

- No diff hunk touches `salePayload` (`statement-support.ts:463-524`, `smoke:634-700`) or the hash envelope (`statement-support.ts:525+`, `smoke:701+`). Amounts (`100.000`), `invoice_type_code: 'SALE'`, `currency_scale: 3`, VAT breakdown, sequence (`sequenceNumber = 1`, `previousHash = terminal.genesis_seed`) are byte-identical to base.
- Location is NOT part of the signed fiscal payload; `pos_receipts.location_id` is derived downstream from the terminal by the projection (`PosCoreReceiptProjection.php:346`). So the change is strictly WHERE the fixture rings, never WHAT is signed.
- **Stock side effect checked, none exists:** both fixtures use a non-UUID `product_id` (`statement-support.ts:482` `c2-stmt-<uuid>`, `smoke:653` `phase5b-<RUN_ID>`), and `decrementStockForLines` skips non-UUID product ids (`PosCoreReceiptProjection.php:1658-1666`). Relocating the fixture to a shop therefore does not start decrementing shop stock. No new stock/loyalty write, no idempotency-anchor change (nothing server-side changed at all).

---

## Findings

- **[Important — non-blocking] `apps/web/e2e/money-campaign/statement-support.ts:432` and `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:593`** — the predicate is `type === 'shop'`, but the message asserts the stronger invariant "a fiscal sale must never be rung at a **non-POS** location". The domain's actual POS flag is `pos_enabled` (`DemoPharmacySeeder.php:232` warehouse `pos_enabled => false`; `:318` shops `true`), and it IS in the payload both fixtures read (`LocationResource.php:38`). The server does not enforce location type or `pos_enabled` on terminal creation — `CreateTerminalRequest::rules()` validates `location_id` only with `ScopedExists::company('locations', $companyId)` (`apps/api/app/Modules/POS/Presentation/Requests/CreateTerminalRequest.php:33-40`) — so this fixture predicate is the ONLY guard. If any `shop` location is ever flipped to `pos_enabled = false`, the same bug class silently reopens one notch narrower. **Fix:** `l.type === 'shop' && l.pos_enabled !== false`. No live row violates this today, hence non-blocking.
- **[Minor] `statement-support.ts:442` / `smoke:607`** — selection is deterministic by TYPE but not by IDENTITY; the winner is the newest active shop terminal (`TerminalController.php:60` orders `created_at DESC`). The ticket explicitly allowed either ("Either way", §66-69) and the loop self-stabilizes (§2d), so this is spec-compliant. The ticket's stronger option (anchor on the seeded `POS01` code) remains the more reproducible choice if these fixtures ever need a fixed subject location. Related pre-existing, out of scope: neither fixture deletes the terminal it creates, so terminal rows keep accruing per run.
- **[Minor] `statement-support.ts:429` / `smoke:588` — scope asymmetry.** `GET /locations` is filtered by the caller's location scope (`LocationController.php:82` → `LocationScopeResolver::resolve`, `apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31-45`), while `GET /pos/terminals` is company-scoped only (`TerminalController.php:54` `Terminal::forCompany`). Run with a location-pinned principal (e.g. `PINNED_CASHIERS`, `apps/web/e2e/money-campaign/w7-support.ts:80-84`) the guard could fire on a tenant that genuinely has a valid shop terminal. Failure mode is a LOUD red, never a wrong ring, and both current callers use the owner (`statement-support.smoke.spec.ts:24-25`, `smoke:19`) — informational.
- **[Minor] new coupling to the Inventory module.** `/locations` sits behind `module:Inventory` + `can:inventory.view` (`apps/api/app/Modules/Inventory/Presentation/routes.php:30,32-34`). The treasury/fiscal fixtures now hard-depend on that module being enabled on the target tenant — relevant because the smoke's API base is env-overridable. Acceptable: this is already house convention (`w7-support.ts:58-62`, `wx-gate-compliance.spec.ts:138` both read `/locations` as owner). If the smoke is ever aimed at a tenant without Inventory, `GET company/locations` (`apps/api/app/Modules/Company/routes.php:50-51`, ungated, returns `id/code/type`) is the lower-coupling source.
- **[Minor] `statement-support.ts:431`** — `jsonData()` already coalesces a missing `data` to `{}` (`statement-support.ts:88-90`), so the trailing `?? []` is dead and a payload-shape change would surface as a bare `TypeError: locations.filter is not a function` rather than a labelled failure. Same shape as the pre-existing terminals read at `:436`; the file's own `Array.isArray` guard at `:118` is the better pattern.
- **[Observation, no action]** the fix stops the ILLEGITIMATE contamination (a warehouse cannot ring a sale) but the fixture still mints one real `100.000` receipt per run at a shop, concentrating on whichever shop hosts the newest terminal. That is the intended semantics and the campaign specs already derive subjects from live data (`w7-multilocation.spec.ts:400-423`, header census `:9-24`). The ticket's owner-decision cleanup of the stray `FE-C2-001d096a-2026-00000001` warehouse receipt (§71-76) is still owed and is NOT closed by this commit.

## What to fix before merge

Nothing blocking — optionally tighten the predicate to `l.type === 'shop' && l.pos_enabled !== false` in both files so the code enforces the exact invariant its own message asserts.
