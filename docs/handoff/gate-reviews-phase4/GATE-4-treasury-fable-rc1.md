# Treasury Phase 4 — GATE 4 treasury Fable lane (rc1)

**Scope:** W1 + W4 money surfaces: posting, tax details, VAT, reconcile, and outbound direction guards over `git diff origin/dev..HEAD`.

## Verified

1. `TreasuryMovementService` is byte-untouched; the fiscal perimeter and existing JE source types are untouched.
2. VAT posting remains a balanced three-line shape at explicit currency scale; VAT-less expenses retain the legacy two-line shape. Off-grid VAT-present values and invalid VAT totals produce service-level 422s; zero VAT normalizes to the null trio.
3. Expense create/update and recurring generation are console-safe and do not read CompanyContext; explicit company IDs and tenant-correct actors/team IDs are used.
4. No float, literal currency bcmath scale, or no-arg scale was added. VAT deductible arithmetic is shared by GL and `document_tax_details`, preserving declared/posted parity.
5. `settle()` and linked-cost capitalization are untouched; reconcile tests exercise the authoritative cash-account branch.
6. Recurrence cursor/idempotency/actor/tenant stamping, cross-company analytics/export/command isolation, permissions, route order, tenantScopedKey/invalidation, i18n, design tokens, and pinned test coverage were verified.
7. All five outbound direction guards use identical text at the required lifecycle/remittance points; receive/cancel/updateDetails remain direction-neutral and inbound clearing remains green.

## Findings (LOW/INFO only)

1. **LOW:** Deploy checklist enumerates the expense metadata/recurrence migrations but not the analytics composite-index migration explicitly; `tenants:migrate` still covers it.
2. **LOW:** The VatDeductible presence check is described in prose rather than as a literal runnable query.
3. **INFO:** Pre-existing Arabic `pay.*` locale keys remain absent and fall back to EN.
4. **INFO:** Tax-detail metadata access mixes plain and nullsafe syntax; PHPStan confirms the path is non-null.
5. **INFO:** Zero-deductible VAT writes a zero-amount declaration row while omitting the GL 4456 line; parity is exact and the EC default supports it.
6. **INFO:** Notification currency falls back to EUR when payload currency is absent; the generation command always supplies currency.

The review harness could not independently execute PHPUnit/node commands; recorded green runs and source inspection were used.

**VERDICT: APPROVE**
