# Ticket: INVESTIGATE — ~23 invoices in status=paid with balance_due=total (impossible combination)

Surfaced by the dashboard fix round's live cross-check (2026-08-03): on demo-pharmacy-tn,
`SUM(balance_due)` over Posted∪Paid = 31,018.322 vs an expected ~27,842 — traced to ~23 invoices
with `status='paid'` AND `balance_due = total` (never reduced), timestamps 2026-08-01 21:33 →
2026-08-02 11:41, ~10,290 TND.

A Paid invoice with an untouched balance_due should be unreachable: the Paid transition is
supposed to follow allocation writing balance_due down. Either (a) campaign/test flows PATCHed
status directly (data artifact — then the demo tenant needs a cleanup pass and the API should
REFUSE a direct status write to paid), or (b) a real payment path marks Paid without settling
balance_due (P1 money-visibility bug feeding every balance-derived KPI/report).

Investigate: how were these 23 transitioned (audit events / hash chain / payment rows)?
Enumerate every code path that writes status=paid; assert each also writes balance_due
consistently; add a DB-level or domain invariant (paid ⇒ balance_due = 0) if the ruling supports
it. Timestamps coincide with the money campaign W1/W2 runs — start with the campaign specs'
API usage.
