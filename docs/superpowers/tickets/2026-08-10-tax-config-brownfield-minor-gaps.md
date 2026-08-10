# Tax Configuration — Brownfield and Ergonomics Gaps (F-9, F-10, F-11)

Raised by: tenancy-authz-reviewer gate verdict (item G, 2026-08-10).
Status: three independent P3s, deliberately deferred out of fix round 1.

## F-9 — `destroy()` is an undocumented escape hatch for uneditable rows

Item G refuses any non-stamp `DOCUMENT_TOTAL` configuration on both `store()` and `update()`, evaluated on merged state. A **pre-existing** row of that shape (seeded, imported, or written by raw SQL before the guard) is therefore partially uneditable: any PATCH that leaves it non-stamp and document-total is rejected, including one that only renames it.

The only way out is `destroy()` — which is a soft delete (`is_active = false`) and is not blocked. That is a reasonable answer, but it is undocumented and untested, so an operator hitting the wall has no signposted path and a future refactor could remove it without anyone noticing it was load-bearing.

Do: document the deactivate-then-recreate path in the taxation module docs, add a test pinning that `destroy()` works on a brownfield non-stamp DOCUMENT_TOTAL row, and consider a clearer rejection message that names the escape hatch.

## F-10 — `code` column ceiling (varchar(20)) vs validation (max:50) vs auto-generation (from max:100 name)

Three mismatched limits on one field:

- column: `tax_configurations.code` is `varchar(20)`;
- validation: `'code' => ['nullable', 'string', 'max:50']`;
- auto-generation: when `code` is blank, `store()` derives it from `name` (`max:100`) via `strtoupper(str_replace(' ', '_', $name))`.

A blank-code create with a name longer than 20 characters therefore passes validation and dies at the driver with PostgreSQL `22001` (`value too long for type character varying(20)`) — a 500, not a 422. The implementer hit exactly this wall during item G and worked around it by shortening the test fixtures rather than fixing the ceiling.

Do: pick the real limit and make all three agree. Widening the column to 50 and truncating the auto-generated code to the column width is the smallest correct change; the migration is trivial but touches a table with country-scoped reference rows, so it wants its own gate.

## F-11 — Capability query now mounts from every tax select (note only)

`useTaxConfigurationCapabilities()` fires from `TaxConfigFormModal`, which is rendered by every form containing a tax select. TanStack dedupes by key and `staleTime: Infinity` means one fetch per session per company, so the practical cost is negligible. Recorded only so a future performance sweep does not rediscover it as a surprise.

## Addendum — re-review round 1 residuals (tenancy-authz-reviewer + treasury-reviewer, non-blocking)

- **N-1 (P3):** `stampDutyHintKey()` (`TaxConfigFormModal.tsx:44-51`) keys "unsupported country" on `!isLoading && !supportsStampDuty`; a **disabled** capabilities query (no tenant scope yet — `enabled: hasTenantScope`) yields `isLoading: false, isError: false`, so the user is told their country doesn't support stamp duty when the question was never asked. Controls stay disabled (fail-closed preserved); wording only. Fix: key on `isSuccess` (or `data !== undefined`).
- **N-2 (P3):** `StackingBehavior` in `apps/web/src/features/settings/types/tax.ts:3` duplicates the backend-generated `packages/shared/types/generated.d.ts:2199` (currently agreeing, free to diverge). Re-export from the generated module, or add the `// replace with an import from packages/shared/types/` marker used in `apps/web/src/features/purchases/supplier-invoices/types.ts:8`.
- **N-3 (P3):** no positive test that a TN `DOCUMENT_TOTAL`+stamp row survives a legitimate partial PATCH (e.g. `fixed_amount` — the timbre-adjustment operation). One extra assertion in `TaxConfigurationManagementTest.php`.
- **(treasury re-review P3):** `TreasuryReceiptBridgeRoundingGlTest.php:669-680` repository-lookup helpers use unordered `firstOrFail()`; harden to `->sole()` on next touch of the file so a second fixture repository fails loudly instead of making money assertions non-deterministic.
