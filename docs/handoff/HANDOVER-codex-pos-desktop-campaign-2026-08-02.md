# Handover: POS desktop (Tauri) test campaign → Codex Desktop (computer use)

**Mission:** execute **§Z (Z.1–Z.10, 64 items)** of `docs/qa/2026-08-01-money-test-plan.md` against
the IziPOS Tauri desktop app via computer use. §Z is the ONLY owner of device-side coverage: the v4
refund flow is 100% device-side, and Z.1 authors the SHIFT-1/SHIFT-2 fixtures that the web
campaign's W5 fiscal cases and the final §Y gate both depend on. Do not assume anything in §Z is
covered by the web Playwright campaign — the plan explicitly de-duplicates the two.

## 1. Environment recipe (validated 2026-07-20 — re-verify each step, don't trust blindly)

- **`pnpm tauri dev` spawns an UNBUNDLED binary** (`src-tauri/target/debug/izipos`, no
  CFBundleIdentifier) → invisible/unclickable to computer-use. Fix: copy the fresh debug binary
  into the stale-but-valid bundle
  `src-tauri/target/debug/bundle/macos/IziPOS.app/Contents/MacOS/izipos`, then
  `codesign --force --sign -` it, run Vite alone (`pnpm dev`, port 1420), and `open` the bundle —
  it loads the devUrl and carries `com.syneriva.izipos`, which computer-use can be granted.
  App-data dir is shared between both forms (sqlite/auth persist).
- **TRAP: `open_application "IziPOS"` launches the INSTALLED production app**
  (`/Applications/IziPOS.app`, ancient build, staging API baked in → 404 login loop). Symptom:
  console errors reference hashed `index-*.js` bundles instead of Vite source files. Focus the dev
  instance by PID via System Events instead.
- **Wispr Flow overlay** (invisible 440×510 window, center-bottom) intercepts clicks. Move the POS
  window top-left via System Events, or ask the owner to quit Wispr Flow.
- Device sqlite: `~/Library/Application Support/com.syneriva.izipos/izipos-<companyId>.db`
  (ignore `izipos-.db`). Migration ledger = `_migrations`. Shop stock = `location_stock`
  (absent row = 0); `products.stock_quantity` is a staler global figure — never compare the two.
- Local API = :8010 (`.env.development.local`, gitignored). ⚠️ A parallel session's worktree
  `artisan serve` can squat :8010 — verify `lsof` cwd matches the MAIN checkout.
- **Credentials/PINs — owner ruling 2026-08-03: the agent MAY type DEMO-tenant credentials and
  PINs directly** (seeded, non-secret values: owner 1234, manager 5678, cashiers
  0000/1111/2222/3333/4444; web logins owner@/manager@/cashier@pharmabio.tn / "password").
  Scope strictly limited to demo/dev tenants — any PRODUCTION or real-tenant credential remains
  owner-entered, never the agent.

## 2. Build prerequisites (BLOCKING — verify before any test)

The test device must run a POS build with device migrations **v60–v67** applied (v65/v66/v67 from
Lane C waves + older owed v60/61/62 from prior lanes). Check `_migrations` MAX(version) in the
device sqlite. If < 67, stop and request a build refresh — §Z results on an older schema are void.

## 3. What changed since the plan was authored (2026-08-01 → 02) — read before Z.3/Z.5

The treasury fix lane landed at `f670d37bf` (record:
`docs/superpowers/reviews/2026-08-02-treasury-money-campaign-fixes-review.md`). Device-relevant
deltas:
- **Server refund/partial-refund endpoints now FAIL CLOSED (422) when the payment's instrument
  (cheque/effet/traite) is uncleared (`Received`)**; payment REVERSE atomically cancels such an
  instrument. Pure-cash POS flows are untouched, but any §Z case that brushes deferred-tender
  payments must expect the new fail-closed semantics.
- Instrument cancellation during reversal derives its GL shape from `payment->origin`
  (`PosRevenue` for POS-bridged payments) — Z-report/GL assertions on reversed POS payments
  should see POS-shaped entries, not B2b.
- Refunds (full and partial) now reopen `balance_due` and revert document status Paid→Posted —
  relevant only to B2B document flows, not POS receipts.
- v4 refund AUTHORING remains capability-gated (E-7 non-waivable; enable sequence lives in the
  owner checklist §E). Z.3 refund cases run wherever the plan says they run — do not enable
  anything on a tenant the plan doesn't designate for it.

## 4. Pre-registered known-defect FAILs — do NOT re-file (plan §0 + results ledger header)

Blind expected-cash (cashdrawer v3) · device Z sale-branch gross-as-net (LIVE pre-existing, own
fiscal ticket) · training-gated treasury money legs · no disposition UI. A FAIL matching one of
these gets cross-referenced, not re-filed.

## 5. Evidence & reporting contract

- Append a `## §Z — POS desktop (computer-use)` section to
  `docs/sessions/MONEY-CAMPAIGN-RESULTS.md` in the established house format (scope paragraph,
  numbered defect writeups with citations, per-case verdict table
  `| Case | Verdict PASS/FAIL/BLOCKED | Evidence |`, counts line).
- Evidence per money-affecting case: screenshot + a DB probe (device sqlite AND server Postgres
  where the case spans sync) — on-screen numbers alone are not evidence for fiscal cases.
- Z.10: record the SHIFT-1/SHIFT-2 fixture identifiers (shift ids, terminal UUID, receipt ranges,
  expected totals) — §Y consumes them verbatim.
- No commits to product code, no pushes, no full test suites. Defects come back to the
  orchestrator for fix lanes (owner checklist D3).

## 6. Sequencing

Z.1 first (it unblocks web W5). Z.2→Z.9 in plan order. The web campaign W1–W4 may run in parallel
from the orchestrator's side; §Y (final fiscal re-run) only after BOTH campaigns complete.
