# `reverseDocumentGl` allocates a chain sequence without the company advisory

**Severity:** HIGH — pre-existing fiscal-chain concurrency race (T19 / fiscal N-11).
**Owner:** Accounting/fiscal ledger owner.

## Problem

`AccountingService::reverseDocumentGl()` creates and posts a document reversal
with a `chain_sequence`, but its allocation path does not acquire the
company-scoped `pg_advisory_xact_lock` used by the hardened journal posting
path. Two concurrent reversals can therefore race on the same chain head. This
predates the inventory-GL seam; Wave 3C does not absorb it.

## Required resolution

Route the reversal through the same terminal advisory discipline as other
Posted entries, inside the caller's transaction and after its last inventory
lock. Add a deterministic two-connection PostgreSQL sensitivity test that
reproduces the conflicting allocation before the fix and proves serialized,
gap-free sequences afterward. Preserve the existing closed-period and
document-cancellation rulings; do not mutate or delete a Posted reversal.

