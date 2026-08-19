# Fiscal: the `pending_seal → fiscalized` trigger branch has NO column guards — a seal may rewrite `subtotal` and `total` in the same statement

Raised by: **ES wave A0, milestone M4 (ES-41)**, 2026-08-19.
Confirmed and re-raised as NON-WAIVABLE by the M4 round-1 adversarial register
(`docs/handoff/reviews/es-wave-a0/M4-round1.md`, finding **F-3**), which found the M4
evidence's claim that this was already "ticketed" to be **false**. This file is that ticket.

Status: **OPEN — needs an owning lane. A0 does not take it (R-5 scope).**
Confidence: **CONFIRMED at runtime on live PostgreSQL**, not inferred.

---

## The defect

`prevent_receipt_modification()` — the function behind the `enforce_receipt_immutability`
trigger on `pos_receipts` — opens its UPDATE handling with an **unconditional** early return:

```sql
-- database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:60-62
IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status = 'fiscalized' THEN
    RETURN NEW;
END IF;
```

No column comparison of any kind. Every branch below it enumerates the columns it permits:

| Branch | Declared at | Guarded columns |
|---|---|---|
| `pending_seal → fiscalized` | `:60-62` | **none — unconditional `RETURN NEW`** |
| `fiscalized → voided` | `:64-75` | 7 |
| `fiscalized`, query-only customer-FK detach | `:78-95` | 13 |
| `fiscalized`, one-time `sealed_hash_algorithm` backfill | `:120-140` | 17 |
| `fiscalized` catch-all | `:143-146` | refuses |
| `pending_seal → anything else` | `:148-151` | refuses |

(Counts re-derived column by column for this ticket. The M4 evidence and the test docblock said
"7, 13 and 15" for the three guarded branches; the third is **17** — it guards the detach
branch's thirteen PLUS `partner_id`, `contact_id`, `fiscal_status` and `is_voided`. The test
docblock is corrected in the same commit as this ticket. The correction does not change the
finding: the first branch guards **zero**.)

The same unguarded branch is reproduced verbatim in the migration's `down()` at `:180-181`,
so a rollback does not close it either.

**Consequence.** A single `UPDATE` that flips the status *and* rewrites the money columns is
accepted and committed. The `pos_receipts_totals` CHECK does not stop it: that CHECK is an
arithmetic invariant (`subtotal + tax − discount = total`, in effect), not an immutability
control, and it will happily certify a coherently-forged pair of amounts. The seal is the
moment a receipt becomes fiscally binding, and it is the one moment at which the immutability
trigger looks away.

## Executable evidence — a passing test, not a reading of the SQL

```
apps/api/tests/Feature/Fiscal/ImmutabilityTriggerPresenceTest.php
  ::test_pg_the_pending_seal_to_fiscalized_branch_has_no_column_guards_characterization
```

Run it on PostgreSQL (`apps/api/phpunit-pgsql.xml`). It seeds a `pending_seal` receipt, issues
ONE `UPDATE` that sets `fiscal_status = 'fiscalized'` and adds `999.000` to both `subtotal`
and `total`, and asserts that the rewritten total is what the database now holds. It passes
today. It is a **characterization** test: it documents the gap, it does not endorse it.

The test carries its own retirement instruction in the assertion message — the day the branch
grows column guards, the assertion goes red, and the correct response is to DELETE the
characterization, replace it with a refusal test, and close this ticket.

## Why A0 did not fix it

Guarding the branch is a **per-column decision against a LIVE seal path**. The sealing write
legitimately authors `fiscal_hash`, `chain_sequence`, `sealed_hash_algorithm` and `posted_at`
during exactly this transition — see `ReceiptFinalizationService` (which computes and persists
`fiscal_hash` and moves `pending_seal → fiscalized`) and `ReceiptPaymentService.php:395,433`
(which performs the hash + `chain_sequence` + `fiscal_status` write as a single UPDATE). Deciding
which columns a seal may and may not author is an immutability redesign, which the A0 brief's R-5
names as scope creep for the ES-41 row. A0's contract for ES-41 was **trigger presence**, and a
guess at the permitted column set, shipped against a live seal path, would be worse than the
demonstrated gap.

## What the owning lane has to decide

1. **The permitted column set for a seal.** Enumerate what the `pending_seal → fiscalized`
   transition may write (candidates: `fiscal_status`, `fiscal_hash`, `previous_hash`,
   `chain_sequence`, `sealed_hash_algorithm`, `posted_at`, `synced_at`, `updated_at`) and refuse
   everything else — in particular every money column (`subtotal`, `tax_amount`, `discount_amount`,
   `total`, and the tender/change columns) and every line-count column.
2. **Whether the seal may move money at all.** If some legitimate path *does* adjust amounts at
   seal time (rounding, tender resolution), that path must be found first — otherwise the guard
   will break production sealing rather than the attack.
3. **Backfill/forward safety.** The guard belongs in a NEW migration that `CREATE OR REPLACE`s
   the function; it must not be retrofitted into `2026_07_31_940000_…`, whose `up()` has already
   run on staging and on any provisioned tenant.
4. **Whether an audit trail is owed** for a seal that changes an amount, independent of whether
   the trigger refuses it.

## Blast radius, stated honestly

- **PostgreSQL only.** Both `2026_05_14_100002_create_fiscal_events_immutability.php::up()` and
  `2026_07_31_940000_…::up()` (`:47`, `:165`) return early on any non-`pgsql` driver, so on
  SQLite there is no enforcement to have a hole in. That is the other, separately-CONFIRMED half
  of ES-41 and it is not this ticket.
- **No exploit is claimed in the field.** This is a missing guard on a write path, not evidence
  that anything has used it. A0 ran no production probe and claims none.
- **The gap is reachable by any code path that can issue an UPDATE on `pos_receipts`**, including
  a legitimate service with a bug — the failure mode does not require malice.

## Cross-references

- Register row: `docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md` **ES-41**
  (the SUSPECTED half, now CONFIRMED by this evidence).
- Adversarial register that made this ticket non-waivable:
  `docs/handoff/reviews/es-wave-a0/M4-round1.md` finding **F-3**.
- Wave ledger entry: `docs/handoff/progress/es-wave-a0.progress.yaml`, `findings:`.
