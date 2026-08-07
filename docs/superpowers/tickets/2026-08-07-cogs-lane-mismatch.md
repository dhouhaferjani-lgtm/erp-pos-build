# COGS lane mismatch — invoice-coupled COGS vs the ruled stock/money separation (P1, pre-existing)

Source: c1 premise verification (rulings record, R-c section) — full citations there.
Owner's ruled model (2026-08-07): stock and money are separate lanes; invoices touch money
only; inventory moves on delivery/return notes. Code reality: stock UNITS comply, but the
COGS GL entry books at INVOICE posting (PostCOGSOnInvoice on InvoicePosted, current-WAC
basis, log-never-block).

## D1 — re-invoice double-COGS (P1, reachable via normal ops)
Invoice cancel reverses only source_type=DOCUMENT legs; the COGS entry (separate source) is
NOT reversed. Cancel-then-reissue (the ordinary fix-a-mistake flow) books COGS twice for one
delivery. Detection: invoices with a sibling re-issue where two COGS entries reference the
same delivered goods.

## D2 — sales return books no GL (P1, every confirmed return)
ReturnNoteService::confirm() re-enters units + WAC but writes NO journal entry — GL
inventory asset never re-debited, COGS never credited. GL-vs-physical inventory divergence
grows with every return; COGS overstated.

## Resolution — blocked on expert question c1-bis (rulings record)
Where should COGS be recognized: at stock exit (delivery-note confirm — matches the ruled
lane model; requires moving the listener + historical-entry posture) or at invoicing
(status quo — then cancel must reverse COGS and return-note confirm must book the
re-debit/credit)? Either answer also defines the return-note GL entry. Feed into F2's GL
half; the cancel-flow PROMPT + return-note pre-linking (F2's UX half) is NOT blocked.
Also note: PostCOGSOnInvoice's log-never-block failure mode means COGS can silently be
MISSING for some invoices — the chosen fix should add detection for that too.
