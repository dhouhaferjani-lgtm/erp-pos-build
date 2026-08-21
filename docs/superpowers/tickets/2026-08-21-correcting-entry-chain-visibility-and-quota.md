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

---

## Why these are one ticket

All three are the same root cause — a new document type inherits every generic
document surface, and each surface has its own idea of who may see what. Fixing
one without the others produces an inconsistent answer: quota that ignores
corrections, a chain that shows them, and a UI that cannot open them.
