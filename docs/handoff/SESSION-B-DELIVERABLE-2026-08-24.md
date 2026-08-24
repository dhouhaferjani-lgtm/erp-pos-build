# Session B deliverable — state-machine & data-structure fixes (2026-08-23 → 2026-08-24)

> Handover mandate: `docs/handoff/HANDOVER-state-machine-fixes-session-B-2026-08-23.md` §7 — (a) merged-lane list with gate records,
> (b) the CHECK burn-down count (76 → n) once Slice D-1 lands, (c) the program spec skeleton + owner questions.
> Every merge is on LOCAL dev only and CI-UNVERIFIED (S-17); Session A owns promotion. DRAFT — rows marked ⏳ fill in as lanes land.

## (a) Merged lanes — Wave 1 (all gated; records in `docs/superpowers/reviews/`)

| # | Lane | Merge (local dev) | Gate path | Migration | LEDGER |
|---|---|---|---|---|---|
| Q-1 | Import job re-execution guard (#23) | `d1afcd9d8` (08-23) | imports r1 ACCEPT | no | C-9, C-10 |
| Q-2 | Counting finalize/submit/thirdCount/cancel under FOR UPDATE + counting-apply unique + typed 422 | `850e86217` | inventory-costing r1 CHANGES → r2 ACCEPT-w/-cond | YES (partial unique) | C-14 |
| Q-3 | Loyalty redemption double-spend (lock + conditional debit + idempotency) | `faa39d7ef` (08-23) | treasury r1 CHANGES → r2 ACCEPT | YES | C-11 |
| Q-4 | Coupon/promotion cap enforcement (unique per receipt + lock) | `6d601a33f` (08-23) | treasury r1 CHANGES → r2 ACCEPT | YES | C-12 |
| Q-5 | Voucher void consolidation — `VoucherVoidService` single write path + GL reversal + partial unique (#22/#24) | `b851db749` | treasury + fiscal r1 ACCEPT-w/-cond → micro-round parent-verified | YES | C-18 |
| Q-6 | `pos_receipts` immutability trigger → whitelist-with-ELSE-RAISE; voided/sync states frozen; FK-cleanup bypass closed | `681c7bf29` | fiscal r1 CHANGES → r2 ACCEPT-w/-cond | YES (function replace) | C-15 |
| Q-7 | Terminal-claim hardening — conditional-UPDATE claim, partial unique on hardware id, type/lifecycle CHECKs, `release()` refusing on OPEN shift unless force+reason | `43a2dad14` | tenancy r1 ACCEPT-w/-cond + fiscal r1 CHANGES → r2 ACCEPT-w/-cond | YES (3 legs) | C-17, **O-30** |
| Q-8 | Held-order recall under txn+lock, typed 409, soft-delete discard, status CHECK | `5de9affe2` | fiscal r1 ACCEPT-w/-cond → 1-line sanctioned fix parent-verified | YES (CHECK) | C-16 |
| Q-9 | F1 kitchen/order `module:Menu` both layers + SM-1 terminal-state guard (Cancelled→Ready chain closed) | `5e4a0af3e` | tenancy r1 CHANGES → fixture fix → fiscal r1 APPROVED | no | C-19 |
| Q-13 | `module:Tables` backend gate (none existed) + device `pullTables` skip — owner go-ahead | `0a3b629ff` | tenancy r1 APPROVED | no | C-20 |
| Q-10 | Fiscal-period quick fixes — per-company country rules (fail-safe skip), per-row audit stamps, permissioned Closed→Open reopen; scheduler respects reopens on all three arms | `f5cae1f12` | treasury r1 ACCEPT-w/-cond → fix round `8a285c495` → r2 ACCEPT | YES (additive-only) | C-23, S-20 |
| Q-11 | SupplierInvoice `match()` draft-guard + expense-number advisory lock | ⏳ queued (B-19 confirmed not in flight) | treasury | no | |
| Q-12 | Treasury orphan census command (F5, read-only) | ⏳ queued | treasury | no | |

Owner items surfaced: **O-30** (forced terminal release orphans an OPEN shift; no server surface can close it — runbook + ruling,
non-waivable before the first forced release in prod). Green-field ruling 2026-08-24: migrations are not a blocker — S-19 reduced to one
post-migrate `BLOCKED|FAILED` grep on the Q-7 token. `ci.yml` backend-pgsql `--filter` allowlist grew by 4 classes (Q-6, Q-7×2, Q-10) so the
migration-bearing pins execute somewhere while their lanes are parked (B-3 precedent; S-14 leg applies to the promotion).

## (b) CHECK burn-down — 76 → n
⏳ Filled by Slice D-1 (`fix/sb-d1-pg-constraint-parity-test`): the `pg_constraint` enum↔CHECK parity test + shrink-only baseline + the
derived register artifact. Wave 1 added exactly THREE status-column CHECKs (grep-verified on dev): `pos_terminals.type` + the `pos_terminals` lifecycle CHECK
(Q-7, `2026_08_23_140000`) and `pos_held_orders.status` (Q-8, `2026_08_23_163000`). Q-5 did NOT ship `vouchers_status_check` (brief item
deferred to Slice D). Q-6 replaced a trigger, not a CHECK. So the expected D-1 denominator is 76 − 2 enum-backed status columns
(`pos_terminals.type`, `pos_held_orders.status`) = **74 uncovered** before Slice D batches; D-1's derived register is the authority.

## (c) Program spec + owner questions
`docs/superpowers/specs/2026-08-23-state-machine-program-spec-skeleton.md` — workstreams G0/A/B/C/V/I/D/E; §R ratification questions
outstanding. Additions from this session's gates: WS-A gains the KDS 422-swallow + `OrderCancelled` event gap (C-19); WS-V gains the
fraud-counter / expiry-engine obligations (C-18 ii-iii); a Company-lane item for the manual single-period close (Q-10 residual).
