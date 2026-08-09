# P1 — `fiscal:verify-chains` reports tampering on untampered chains (seal-date mismatch)

**Raised by:** plan CF (guided cancel flow), task T13. **Status:** OPEN, LIVE defect.
**Severity:** P1 — it is a *false positive on the fraud detector*, which is worse than a
missed detection: it trains operators to ignore the alarm.
**Cross-reference:** the launch program's fiscal-verifier readiness gate;
`docs/superpowers/tickets/2026-08-07-cancel-reversal-bypasses-closed-fiscal-period.md`.

## The defect in one line

Every fiscal document is **sealed** with the hash input `posted_at = now()`, and
**verified** with the hash input `posted_at = document_date`. Whenever those two dates
differ, verification fails on a chain that nobody touched.

## Evidence

| Role | Site | Date fed into the hash |
|---|---|---|
| Sealer (invoice / credit note) | `DocumentPostingService.php:455,460` | `$postedAt = now()` |
| Sealer (delivery note) | `DeliveryNoteService.php:135` | `$confirmedAt = now()` |
| Sealer (return note) | `ReturnNoteService.php` (`confirmWithFiscalChain`) | `$confirmedAt = now()` |
| **Verifier** | `Compliance/Commands/VerifyFiscalChainsCommand.php:310-312` | **`$document->document_date`** |

`VERIFIABLE_TYPES = [Invoice, CreditNote]`
(`VerifyFiscalChainsCommand.php:258`) — exactly the two types the divergence was
previously assumed to be hidden for. It is not hidden:

- **`document_date` is user-supplied** — `CreateDocumentRequest` validates it as a plain
  `date` with no same-day constraint.
- **Nothing normalizes it at post time** — `grep -c document_date
  DocumentPostingService.php` returns **0**. Posting never touches the field.

So any invoice whose `document_date` differs from the day it was posted —
back-dated, future-dated, or simply **created on Friday and posted on Monday** —
fails `fiscal:verify-chains` today and is reported as "Hash verification failed".

## Why it is P1 and not a nuisance

The verifier is the tool an auditor or an operator reaches for to answer "has this chain
been tampered with". A detector that fires on ordinary, lawful documents is worse than
one that stays silent: the first real tampering event arrives in a list of false
positives that everyone has learned to scroll past. It also blocks the launch program's
fiscal-verifier readiness gate, which cannot be signed off while a clean tenant reports
failures.

## What lane CF already fixed, and what it did not

Lane CF fixes the **return-note half of the input-destruction problem**. T4(a) makes
`ReturnNoteService::confirmWithFiscalChain()` persist `confirmed_at` and `confirmed_by`
alongside the seal — the columns existed and were simply never written, unlike
`DeliveryNoteService.php:149-150` which has always stamped both. Persisting is
hash-neutral (it is the exact value the hash consumed) and trigger-safe
(`OLD.fiscal_status` is DRAFT at that update). Before T4(a) the seal date for a return
note was **not recoverable at all**, so no fix to the verifier could have worked for
that type.

It does **not** fix the verifier, and deliberately so — changing the seal formula or the
verifier's date basis is a fiscal-compliance decision, not a refactor, and the wrong
choice retro-invalidates every already-sealed document in production.

## ⚠ Do not add `ReturnNote` to `VERIFIABLE_TYPES` before this is settled

It would report **every backdated return note as tampered**. Lane CF makes backdating
routine: option 2 of the guided cancel flow ("products were already returned, on date X")
seals a return note whose `document_date` is the stated return date and whose
`confirmed_at` is today, by design and by owner ruling. Adding the type first would turn a
latent defect into a flood.

## Options for the fix (needs a ruling, not a patch)

1. **Verify against the persisted seal moment.** Change the verifier to feed
   `confirmed_at` / `posted_at` — the value actually sealed — instead of
   `document_date`. Correct by construction, and now possible for return notes thanks to
   T4(a). Requires that every verifiable type persists its seal moment; invoices and
   credit notes need auditing for that (`DocumentPostingService` writes `$postedAt` into
   the hash but the persisted column must be confirmed).
2. **Change the seal input to `document_date`.** Rejected in lane CF: it would
   retro-invalidate every document already sealed under the `now()` formula.
3. **Normalize `document_date` at post time.** Rejected: it silently rewrites a
   user-supplied fiscal date, which is its own compliance problem.

Option 1 is the recommendation. It changes no stored bytes and no past document's
validity — only how the verifier recomputes the input.

## Acceptance

- A tenant with a back-dated and a future-dated posted invoice passes
  `fiscal:verify-chains` with zero failures.
- A genuinely mutated `total` still fails, on the same fixture.
- Only once both hold: consider adding `ReturnNote` to `VERIFIABLE_TYPES`, with a
  backdated return note in the fixture.
