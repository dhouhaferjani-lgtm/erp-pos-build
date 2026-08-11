# H-3 clean tenant registration — deploy notes (merged to local dev @ 7d423d162)

**Filed:** 2026-08-10 (DPA session 2). Lane: `fix/dpa-h3-clean-tenant-registration`
(`8d1740ca7` + `064f4332b`), treasury gate APPROVED after round 1.

## What deploys

- No fabricated Treasury data on registration: `PaymentRepositorySeeder` no longer mints fake banks
  or ~57,700 in fabricated opening cash; migration `2026_03_24_200000`'s re-instantiation path
  inherits the clean shape.
- `TenderRepositoryResolver` fallback now prefers cash registers over safes deterministically
  (CASE preference ahead of `orderBy('id')`), pinned by a UUID-inversion test.

## Deploy notes

1. **N-1 — the fallback change is NOT confined to fresh tenants.** For any tenant provisioned
   before H-3 (v4 UUIDs), a `bank_account` row could have been the lowest-uuid GL-linked winner for
   unmapped tenders; after H-3 the cash register always wins. The GL effect flows through the POS
   bridge: `TreasuryReceiptBridge.php:1187` then `:1200-1202` takes the GL account straight off the
   resolved repository (`$repository->gl_account_id`), and bank repositories link
   `SystemAccountPurpose::Bank` vs `Cash` for tills — so unmapped tenders shift Bank→Cash GL purpose
   at deploy time for such tenants.
   *(Citation correction vs the round-1 gate file: `GeneralLedgerService.php:3948/:4093` are the
   Expense-credit and Income paths, not the POS bridge path. Same conclusion, corrected evidence —
   confirmed by the independent session-2 re-review.)*
2. **"Greenfield ⇒ no affected tenants" is ASSUMED, not verified** — staging PG was unreachable from
   both review sessions. Before/at deploy: confirm no staging/production tenant predates H-3's
   uuid7 seeding (`reference_erp_staging_db_access` has the connection route). If any exists, expect
   the Bank→Cash purpose shift above on its unmapped tenders.
3. **N-2 (deferred, cosmetic):** same-type ties (CASH-01 vs CASH-02) still resolve by raw UUID —
   fresh tenants unaffected; legacy multi-register tenants get an arbitrary-but-stable winner.
4. **N-3 (deferred):** `DemoPaymentRepositorySeeder.php:108` still mints `Str::uuid()` (v4) while
   `PaymentRepositorySeeder` relies on `HasUuids` (uuid7) — inconsistent id generation in one table,
   and precisely the mechanism the C-1 pin defends against. Sweep candidate.
5. **M-3 (deferred, cleanup):** the demo seeder's `$alreadyOpened` guard is redundant — the
   idempotency-key port (`TreasuryMovementService:76` + unique index) already makes a second opening
   leg impossible; proven by mutation (deleting the guard leaves all 7 seeder tests green). Delete
   the guard or fix its comment so it stops asserting a defect that cannot occur.

## Related tickets filed the same night

- `2026-08-10-g3-shiftcashvariance-fixture-forcefill-pg-trigger.md` (C-7)
- `2026-08-10-second-company-provisioning-parity-gap.md` (M-8)
