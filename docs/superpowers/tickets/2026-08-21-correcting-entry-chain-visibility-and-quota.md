# Correcting entries in the document chain: visibility, quota, dead link

**Raised by** fiscal-pos gate P3-11 on `feat/r2f4-correcting-documents`, 2026-08-21.
**Status** OPEN. One of the three is fixed in-lane; two are recorded here.

A correcting entry is a `documents` row like any other, so every generic
document surface picked it up for free. Three consequences, found together.

---

## 1. Related-documents chain leak — OPEN

`GET /documents/{document}/related` walks `source_document_id` and is gated on
`documents.view`, which cashiers, technicians and viewers hold. A correcting
entry linked to an invoice therefore appears as a descendant of that invoice to
anyone who can see the invoice.

**Exposure is metadata only.** `DocumentData::fromModel()` extracts named
payload keys (`converted_to_order_id`, `fully_delivered`, …) and never emits
`payload` wholesale, so the GL legs — accounts and amounts — do not cross the
boundary. What leaks is that a correction EXISTS, its number, its date, its
status.

That is not nothing: "this invoice was corrected" is itself a signal a viewer
was not given `documents.correct` to see. But it is also not the
chart-of-accounts exposure the dedicated permission was created to prevent.

**Options, in preference order:**

1. Filter `DocumentType::CorrectingEntry` out of the `related` response unless
   the caller holds `documents.correct`. One predicate; keeps the chain honest
   for those entitled to it.
2. Filter it out unconditionally, and surface corrections only through
   `GET /documents/{id}/correcting-entries` (already admin-gated). Simpler, but
   the chain then lies by omission to an admin reading it.

Not fixed in-lane: it needs a ruling on which of the two, and `related` is
shared surface that other lanes read.

## 2. Plan quota — FIXED IN LANE

`PlanEnforcementService::canCreateDocument()` (`:242`) and `getUsageStats()`
(`:290`) both counted every `documents` row created this month, correcting
entries included. A tenant repairing a bad month would have burned its document
quota on the repairs — the quota punishing accuracy — and on a busy month could
have been blocked from real invoicing by its own corrections.

Fixed: both counts now exclude `DocumentType::CorrectingEntry`. Both, and in the
same commit, because enforcement and the usage bar must report the same number.

## 3. Dead front-end fallback link — OPEN

The related-documents panel renders a link for every descendant type through a
route map that has no `correcting_entry` entry, so the fallback produces a link
that navigates nowhere. Harmless today because there is no front end for this
feature at all; it becomes a visible dead end the moment (1) is resolved in
favour of showing corrections to entitled users.

Resolve together with (1).

## 4. VAT-in-FILED refusal narrows the escape hatch — OPEN

*Added from treasury gate P3-3 / fiscal gate P3-4, re-gate round.*

The lane now refuses a leg naming a VAT control account while the target's VAT
period is FILED. Correct in the general case — moving 4457 inside a lodged
declaration diverges the ledger from the return with no reconciliation path.

But it interacts with the lane's own reason for existing. The canonical defect
this feature was built to repair (W-6 D1b) **is a stranded VAT leg**. If such a
document sits in a period that has since been FILED, the natural repair — a
counter-leg on the same VAT account — is now refused.

**The hatch is not closed**, and that is the point worth recording rather than
discovering later: the correction can still be made with **non-VAT counter-legs**
(e.g. debit AR, or a suspense/expense account), which rebalances the target's
footprint and unblocks `reverseDocumentGl()` without touching a declared VAT
figure. The declaration stays honest; the ledger comes back into balance; the
VAT itself settles through the next declaration.

That is a real answer, but it is currently an answer nobody is told. It needs
either:

- a message on the `CORRECTING_ENTRY_VAT_LEG_IN_FILED_PERIOD` refusal that names
  the non-VAT route explicitly (cheapest, and probably sufficient), or
- the acknowledged-flag alternative already recorded in the enum docblock, which
  needs the owner ruling noted there.

Not a defect in the refusal — a gap in what the refusal tells the accountant.

## 5. Auto-reversal disclosure obligation — OPEN, needs an FE

*Added from treasury gate P3-3, re-gate round.*

`reverseDocumentGl()` reverses a document's WHOLE ledger footprint, which by
design includes every correcting entry linked to it. So cancelling a corrected
document silently withdraws its corrections too.

This is the correct behaviour — a correction has no meaning once the document it
repairs is gone — but it is not currently disclosed anywhere a user can see. An
accountant who posted a deliberate correction and later cancels the underlying
invoice has no indication that their correction went with it.

**Obligation, when an FE exists:** the cancel confirmation must state that N
correcting entries will be withdrawn along with the document, and name them.
Nothing to build server-side — `correctingEntryDocumentIdsFor()` already
resolves the list, and the `can-cancel` read model is the natural carrier.

Recorded here so it is not lost between "no FE yet" and "FE shipped".

---

## Why these are one ticket

The first three are the same root cause — a new document type inherits every
generic document surface, and each surface has its own idea of who may see what.
Fixing one without the others produces an inconsistent answer: quota that ignores
corrections, a chain that shows them, and a UI that cannot open them.

(4) and (5) join them because they are the same shape one layer up: consequences
of the correcting entry's existence that are correct in the ledger and unstated
to the human. All five want deciding in one pass, by someone holding the whole
picture.
