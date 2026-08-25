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
| Q-10 | Fiscal-period quick fixes — per-company country rules (fail-safe skip), per-row audit stamps, permissioned Closed→Open reopen; scheduler respects reopens on all three arms | `f5cae1f12` | treasury r1 ACCEPT-w/-cond → fix round `8a285c495` → r2 ACCEPT | YES (additive-only) | C-24, S-20 |
| Q-11 | SupplierInvoice `match()` draft-guard + expense-number advisory lock | ⏳ queued (B-19 confirmed not in flight) | treasury | no | |
| Q-12 | Treasury orphan census command (F5, read-only) | ⏳ queued | treasury | no | |
| D-1 | **Slice D entry — `pg_constraint` enum↔CHECK parity test + shrink-only baseline + derived register (test-only)** | `2288299bf` | fiscal r1 APPROVED-w/-res + tenancy r1 APPROVED-w/-cond → fix `33e26cd69` → combined r2 APPROVED-w/-res | no | C-26, **O-31** |

**Found out-of-lane (needs an owner priority call): C-27 — `journal_entries.entry_number` is unique per tenant but generated + locked per company
(`GeneralLedgerService.php:5339-5356`): the second company of any tenant cannot post anything that mints a JE. Single-company first tenant is
unaffected; multi-company is blocked at the first posting. Fix shape = Q-11's tenant-keyed lock.**

Owner items surfaced: **O-31** (arm the owner-pinned blob over the three parity artifacts before the first CHECK batch), **O-30** (forced terminal release orphans an OPEN shift; no server surface can close it — runbook + ruling,
non-waivable before the first forced release in prod). Green-field ruling 2026-08-24: migrations are not a blocker — S-19 reduced to one
post-migrate `BLOCKED|FAILED` grep on the Q-7 token. `ci.yml` backend-pgsql `--filter` allowlist grew by 5 classes (Q-6, Q-7×2, Q-10, Q-11's `ExpensePostTest`) so the
migration-bearing / PG-only pins execute somewhere while their lanes are parked (B-3 precedent; S-14 leg applies to the promotion).
Still wired NOWHERE: D-1's `EnumCheckParityTest` (needs a PG-service job — `treasury-spine-pgsql`; owner/S-14).

## (b) CHECK burn-down — the honest denominator (D-1 merged `2288299bf`; fix round `33e26cd69`; r2 APPROVED-w/-residuals)

D-1 derives the population MECHANICALLY (every Eloquent model's `$casts` → `app/**/Domain/Enums/*`, plus the governed audit columns)
and reads `pg_constraint` on a freshly-migrated tenant schema. Result — **the audit's §#26 census (90 / 76 uncovered / 14 constrained)
was wrong in both directions**:

| Population (merged `2288299bf`; r2 APPROVED-w/-residuals) | columns | COVERED | MISSING | INTENDED_NARROWER | COVERED_BY_COMPOSITE | **baselined** |
|---|---|---|---|---|---|---|
| enum-governed tenant columns (asserted) | **245** | 55 | 188 | 1 | 1 | **188** |
| of which `*status`-suffixed (comparable to §#26) | 74 | 15 | 59 | 0 | 0 | 59 |
| central-DB columns (asserted on the union DB only) | 20 | 9 | 11 | 0 | 0 | 11 |

Arithmetic from the first cut (244/190): +1 `products.enrichment_status` (was silently excluded — `Shared/Enums` now in scope) → 191;
−1 `fiscal_event_quarantine.integrity_exception_class` (INTENDED_NARROWER, predicate-keyed acknowledgement) → 190;
−1 `pos_receipts.receipt_type` (COVERED_BY_COMPOSITE via `pos_receipts_return_logic`) → **189**. The parser fixes (NOT VALID, parens,
OR-chains) moved zero live verdicts. At merge the ratchet flagged `documents.status::MISSING` STALE — Session A's N-6 had landed
`2026_08_24_100100_add_status_check_constraint_to_documents` after the baseline cut — and the baseline shrank to **188** (COVERED 55). That is
the gate working on its first day.

¹ 244 at `70adfcb2e`; the tenancy gate found `products.enrichment_status` (cast to `App\Shared\Enums\EnrichmentStatus`, no CHECK) silently excluded by the `Domain/Enums` filter — population widened to `Shared/Enums` in the fix round.

Why §#26 was wrong: it grepped for `ADD CONSTRAINT … CHECK` and so missed every `$table->enum()` column (Laravel renders varchar +
an auto-named `{table}_{column}_check` on PG) — `pos_receipts.fiscal_status`, `impersonation_grants.status`,
`impersonation_elevations.status` were ALREADY constrained; conversely the enum-governed population is 244, not 90.
Wave 1 contributed 2 of the 54 COVERED (`pos_terminals.type`, `pos_held_orders.status`; Q-6 replaced a trigger, Q-5 did not ship
`vouchers_status_check`). Two columns are un-gateable by construction and go to a cast/model lane, not a CHECK lane:
`bank_reconciliations.status` (CHECK exists, NO Eloquent model) and `fiscal_event_quarantine.payload_parse_status` (no enum cast).

**The one NARROWER row is INTENDED, not a defect (fiscal gate ruling):** `fiscal_event_quarantine.integrity_exception_class` CHECK admits exactly the two
classes `IntegrityExceptionClass::isAdmissibleToLedger()` routes to quarantine; the other four go to `fiscal_events`. The register must label it
INTENDED-narrower (D-1 fix round) — widening that CHECK would destroy the ledger/quarantine partition. Two hard preconditions before the first
CHECK-adding batch (fiscal gate F-2/F-4): the parser must see `NOT VALID` CHECKs (the mandated batch idiom is invisible to it today), and
cross-column CHECKs that pin a value set (`pos_receipts.receipt_type` via `pos_receipts_return_logic`) must not read as MISSING.

**Final (post fix round):** Final register (fix round `33e26cd69`): **245 tenant enum-governed columns — 54 COVERED / 189 MISSING / 1 INTENDED_NARROWER (quarantine partition) / 1 COVERED_BY_COMPOSITE (`pos_receipts.receipt_type`) → shrink-only baseline 189 at the fix round, **188 at merge** (Session A's N-6 landed `2026_08_24_100100_add_status_check_constraint_to_documents` between the baseline cut and the merge — the ratchet flagged `documents.status::MISSING` STALE, exactly as designed, and the baseline shrank)**; central scope asserted on the union DB: 20 columns, 9 COVERED (impersonation/grant authz) / baseline 11. §#26's 90/76/14 is superseded; Slice D burns down from 189.

The artifact the owner reads is `apps/api/tests/Architecture/baselines/enum-check-parity-register.md` (column → enum → verdict);
the ratchet is `EnumCheckParityTest` (PG-only, shrink-only baseline `enum-check-parity-baseline.json`, 190 keys) + 18 liveness/tamper
tests. It runs in NO CI job yet (S-14): proposed home `treasury-spine-pgsql` + `backend-architecture`. Burn-down batches (Slice D
proper) start from 190, money/fiscal-first, each censused + `NOT VALID`/`VALIDATE`.

## (c) Program spec + owner questions
`docs/superpowers/specs/2026-08-23-state-machine-program-spec-skeleton.md` — workstreams G0/A/B/C/V/I/D/E; §R ratification questions
outstanding. **Erratum for the spec:** §#26's 90/76/14 census is superseded by D-1's 244/190 (see (b)); the spec's Slice D sizing must be re-based. Additions from this session's gates: WS-A gains the KDS 422-swallow + `OrderCancelled` event gap (C-19); WS-V gains the
fraud-counter / expiry-engine obligations (C-18 ii-iii); a Company-lane item for the manual single-period close (Q-10 residual).
