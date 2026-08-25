# IziPOS device build checklist — the D-1 release (prepared 2026-08-25)

Why a build is required: D-1 (`9f2aed21e`) moves VAT sealing to the post-discount base (`SALE_RECEIPT` event_version 5). The
server accepts v≤4 forever, so un-upgraded tills keep working — but they keep sealing the pre-remise VAT base until they run this
build. Tenant #1's tills should start on v5.

## What this build carries (all merged on local dev; promote server FIRST)
- **D-1** — `TransactionRemiseSplit` ventilation in the device engine, v5 payload, per-chain forward gate (server), zero-tender
  100 % comps, receipt print layout (Remise line, per-rate base/VAT), Z/X/EOD from sealed aggregates. ACCOUNT_CHARGE with a remise
  is REFUSED on v5 chains until the ventilation lane lands — train cashiers: no transaction discount on account charges.
- **C-2 + C-6** device-Z fixes (mandatory coupling, LEDGER C-2 — reversal window closes at build rollout).
- **W2-7 device residuals** (batch/lot display) and **W2-1** header hygiene (`apps/pos/src/lib/api.ts:76` — stop sending
  `X-Company-Id` on bootstrap; server already exempts the route).
- N-5 has-pins behaviour (server) — device cache residual C-13(ii): a terminal whose last operator was offboarded keeps the stale PIN
  offline until the next sync; force a sync after the build.

## Order of operations
1. Promote server to remote dev/staging and run the post-deploy censuses (`PROMOTION-CHECKLIST-2026-08-26.md` §6).
2. Build the Tauri app from the SAME dev commit (`apps/pos`, `pnpm tauri build`); version bump; note the commit SHA in the release.
3. Point the build at the target API (`VITE_API_URL` — the installed prod app trap in memory: the old `/Applications/IziPOS.app`
   points at `api.riserpos.app`; never reuse it).
4. On one terminal first: claim terminal → shift open → a sale with a 7 % + 13 % + 19 % line and a transaction discount → check
   the receipt shows Remise + per-rate base/VAT and the server seals `event_version 5` with `discount_allocated` rows →
   `pos:census-vat-legs` exit 0 → refund one line → reversed VAT = that line's share of the sealed group.
5. Offline test: API down → sale → API up → resync → chain continuous, gate accepts (same chain_context).
6. Roll out to the remaining terminals; force a sync on each (pin cache).

## Known device-side residuals NOT in this build (LEDGER)
- Composite (combo) returns never re-enter stock (C-31 (i)) — no combos on tenant #1.
- ACCOUNT_CHARGE remise ventilation (C-41 (iv)).
- X/Z/shift-close are device-only surfaces (server X reports retired) — the owner's smoke sheet §3 rows.
