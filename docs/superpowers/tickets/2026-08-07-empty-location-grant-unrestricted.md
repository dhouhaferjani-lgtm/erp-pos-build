# `allowed_location_ids = []` reads as UNRESTRICTED — intended semantic is deny-all (P1)

Source: R2-C gate re-verify N-1 (record on `fix/r2c-reports`, merged). Pre-existing at base
`a1952aa23` — the R2-C lane neither introduced nor worsened it.

A principal whose membership carries an EMPTY location grant array sees the FULL company
report set (probe: 200, count=3, 35.00 for a zero-grant user). The intended deny-all
semantic is already pinned by `LocationScopeResolverTest.php:108-113` and
`POS/ZReportListTest.php:84-92` (same input → 0 rows on POS) — the reports family diverges.
The state is writable: no `min:1` on allowed_location_ids in CreateUserRequest:53-54 /
UpdateUserRequest:61-62. Same []-overload root cause family as the fixed F-3.

Fix: distinguish NULL (unrestricted) from [] (deny-all) at the boundary shared by the
reports/expense family (LocationScopeBoundary / resolvers), + `min:1`-or-null validation on
the membership writers so the ambiguous state stops being creatable. Deny-all must produce
0 rows, not 403 (matches the POS pinning).

DEPLOY NOTE (tenant #1, from the same gate): confirm owner/manager memberships are
`allowed_location_ids IS NULL` (the UserController:246 / BackfillMembershipsCommand:167
default). An explicitly-listed grant missing an inactive location is now CORRECTLY
restricted and will legitimately narrow that user's company view (CashPosition/Maturing/POS
included) — expected behaviour, not a regression.
