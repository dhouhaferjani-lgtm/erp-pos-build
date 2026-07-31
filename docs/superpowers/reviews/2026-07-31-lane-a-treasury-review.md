# Lane A review record — fix/pos-cash-report-truth (first-tenant program)

**Reviewer:** treasury-reviewer (Opus). **Target:** `fix/pos-cash-report-truth` vs `origin/dev @ 711f3d79f`.
**Merged to local dev:** `b19cd3372` (2026-07-31).

## Round 1 (@ b1e1a8fd7): APPROVE-WITH-FIXES — F1 BLOCKING
- **F1 [CRITICAL]** Group-key mismatch: outer query groups on `(payment_type, payment_methods.name)`;
  change subquery grouped on `payment_type` alone and `keyBy('payment_type')` → the FULL group change
  total subtracted from EVERY outer row sharing the payment_type. Default multi-company scope
  (OwnerReportScope root+children) + code-snapshot `payment_type='CASH'` across sibling companies
  with differently-named methods → cash understated by whole extra change totals. Reproduced in
  sqlite against the exact query shapes.
- **F2 [IMPORTANT]** ForbidHardcodedBcmathScale circumvented via derived `$scale` local instead of
  the sanctioned `precision-ok` exemption marker.
- **F3 [IMPORTANT]** Fixture single-receipt/single-company — blind to F1, cross-receipt aggregation,
  NULL change_due, filter parity.
- **F4 [MINOR]** SQL orderByDesc ran on gross amounts; netting happened afterwards with no re-sort.
- Also recorded: transaction_count semantics now differ from the Z-report (counts payment rows) —
  both defensible; pre-existing float boundary in decimalString()/percentages; legacy NULL
  change_due rows stay gross (irrelevant for a fresh tenant).

## Round 2 (@ d5c726ecd): APPROVE
- F1: subquery joins payment_methods, groups on the identical pair (+receipt id inner), composite
  `paymentGroupKey()` (NUL separator — PG text cannot contain NUL → collision impossible); six
  receipt filters identical both sides; sqlite repro no longer reproduces.
- F2: sanctioned trailing `// precision-ok:` marker per the rule's own hasExemptionComment().
- F3: fixture discriminates from both directions (parent 38 / sibling 12 pin the composite key
  independently), plus NULL change_due, voided-receipt filter parity, COUNT(DISTINCT) with 4 rows.
- F4: post-netting sortByDesc + values(); pinned by deliberate card-between-gross-and-net placement.
- Non-blocking notes: no deterministic tiebreak for equal net amounts (pre-existing); test-file-only
  level-8 nullable offsets (tests outside phpstan paths); float casts in test assertions.

**VERDICT: APPROVE — merged.**
