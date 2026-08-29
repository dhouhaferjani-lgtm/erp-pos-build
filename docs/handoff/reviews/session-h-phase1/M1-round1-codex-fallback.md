# M1 adversarial review — round 1 (Codex fallback)

Reviewer model: `codex-fallback` after two consecutive `claude`/Opus exit-3 tool errors. Review range: `23b1b8a65..7ffcb5461`. Lenses: `frontend-conventions`, `imports`.

## Evidence read

- Focused API: `ListPartnersTest.php` — 14 tests / 52 assertions; `PartiesImportTypeTest.php` — 5 tests / 20 assertions.
- Focused web: partner + modal Vitest — 55 tests. Focused POS: pending-customer + attach-panel Vitest — 10 tests.
- Static: web and POS `tsc --noEmit`; touched-PHP PHPStan level 8; touched-PHP Pint; feature-lane manifest; `git diff --check` — all exit 0.
- Browser/API gate: `apps/web/e2e/session-h/m1-data-shape-drift.spec.ts` — 3/3 passed against normal worktree web `:5174` and API `:8011`. Screenshots:
  - `.playwright-mcp/session-h/m1/m1-customer-vat-list.png`
  - `.playwright-mcp/session-h/m1/m1-inline-partner-created.png`
- Independent SDD task review required one reproducibility fix; scoped re-review found the deterministic PharmaBio identity, literal row matching, and warning-baseline handling addressed with no new Critical/Important breakage.

## Numbered register

1. **P1 — none.** No migration, new enum value, sealed-payload file, payload key, or canonical-byte value is in the range. The two POS edits are confined to the explicitly allowed non-sealed optimistic mirrors (`apps/pos/src/lib/customer/pendingCustomerCreateService.ts:45`, `apps/pos/src/components/customers/CustomerAttachPanel.tsx:163`). A search of changed paths found no `apps/pos/src/lib/fiscal/payloads/*` file. Bypass attempted and failed: widening a8 to the sealed `AccountPaymentPayload.ts` literal is prohibited by the owner gate and is absent from the diff.

2. **P2 — none (frontend conventions).** The list renders the backend field `vat_number` (`apps/web/src/features/partners/PartnerListPage.tsx:378`) through the generated `PartnerData` feature boundary. The modal uses named request fields and an ISO-2 country selection (`AddPartnerModal.tsx:310-359`), with a tenant-scoped countries query established in the reviewed diff. Touched TSX adds no hardcoded user-facing copy or raw colour classes. Typed fixtures were updated with the generated DTO. Browser evidence proves the list and real quote inline modal persist and read back VAT/address data.

3. **P2 — none (imports).** Only `self::Parties.code` changed to `max:50` (`apps/api/app/Modules/Import/Domain/Enums/ImportType.php:172`); the legacy `self::Partners` block remains untouched. The focused validator test pins the 51-character boundary and field error (`apps/api/tests/Feature/Import/PartiesImportTypeTest.php:95-119`). Bypass attempted and failed: forcing the public import controller to return 422 would contradict its established asynchronous contract. The real gate therefore asserts `201`, `failed_rows=1`, and the public error resource naming `code` (`apps/web/e2e/session-h/m1-data-shape-drift.spec.ts:224-236`), while the focused validator test proves the underlying ValidationException is 422. This dispatch mismatch is recorded in `owes_parent` rather than hidden.

4. **P3 — note (generated output).** The required `CACHE_STORE=array php artisan typescript:transform` emitted five unrelated pre-existing enum declarations alongside the Partner DTO update. The generated file was not hand-edited, typechecks pass, and this does not change runtime behavior. Parent should expect possible generated-file merge overlap.

5. **P3 — note (test output noise).** Full lint exits 0 but reports the repository's existing warning baseline (6,465 web, 84 POS). The M1 fix does not change lint configuration or suppression, and React Doctor reports 91/100 with no changed-file findings. This is not an M1 regression.

6. **P2 — none (browser reproducibility).** The committed spec uses the dispatch-standard `owner@pharmabio.tn` identity (`apps/web/e2e/session-h/m1-data-shape-drift.spec.ts:11`), provisionable by the existing deterministic `DemoPharmacySeeder`; UI API traffic is explicitly routed to the worktree API (`:73-92`). The former manually registered test identity was removed and is not referenced. API-sourced partner names are matched literally, not interpolated into regular expressions.

VERDICT: ACCEPT
