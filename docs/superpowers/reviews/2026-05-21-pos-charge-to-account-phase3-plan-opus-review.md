# Phase 3 Stage B Implementation Plan Opus Review

Reviewed artifact: `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`

Codex self-review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-codex-review.md`

Locked spec: `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`

Final spec review: `docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r3-opus-review.md`

Verdict: REQUEST-CHANGES

## Findings

### 1. Validator/parser test matrix is materially narrower than the locked spec

The plan's Task 2 tests cover a positive payload plus payments key, missing `product_id`, invalid `buyer.codice_fiscale`, limit exceeded, amount mismatch, and invalid invoice classification (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:283`-`302`). The implementation step says to enforce exact key sets and invariants (`:315`-`:329`), but the required failing tests do not pin several locked spec gates from the test matrix: extra keys, missing top-level/nested keys, malformed money, malformed VAT partition, synced vs pending customer, stale vs fresh mirror, credit-limit present vs absent, discount present vs absent, nullable vs populated `buyer` / `references`, nullable `line_items[].non_collected_subtype`, and training vs production (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:430`-`431`).

This is a contract-drift risk because Phase 3 is adding a new canonical event type. The plan should require explicit parser/validator tests for the full spec matrix, not rely on a prose implementation bullet.

Concrete fix: expand Task 2 Step 1 with named tests for the missing parser and constraint-validator cases above, and include them in the focused PHP test command before implementation.

### 2. Device rule/full-flow coverage omits ambiguous alias and rejected-flow closure

The locked spec requires device rule coverage for inactive customer, wrong company, limit exceeded, hard-stale mirror, ambiguous alias, and any payment line (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:432`). Task 4's discriminated-union matrix covers inactive, charge disabled, limit exceeded, hard stale, and wrong company, but not ambiguous alias (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:522`-`528`). Payment-line rejection is covered later in Task 5 (`:616`-`:619`), but ambiguous alias appears only as bridge lookup prose in Task 8 (`:896`), after the event has already been sealed.

The spec also asks for full-flow closure tests for B2C, B2B, insufficient-credit, stale mirror, and POS-only deployments (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:52`-`59`). Task 10's full-flow list covers printable+AR, POS-only, B2B facture, and legacy-route rejection, but not insufficient-credit or stale-mirror rejected flows (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:1022`-`1041`).

Concrete fix: add a Task 4 ambiguous-alias rejection case that returns a discriminated `ok: false` result before authoring, and add Task 10 device full-flow tests proving insufficient-credit and hard-stale/block-policy attempts do not call `FiscalEventEngine.append()` and do not sync a fiscal event.

### 3. AR command DTO drops the spec-required line/VAT summary before the Treasury bridge

The locked spec says the Treasury posting command includes tenant, company, partner/customer, fiscal event id, charge amount, VAT/totals, business date, actor/cashier, source context, and line/VAT summary, with VAT partition detail derived from `vat_breakdown` (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:378`-`382`). Task 7's command DTO includes totals and discount fields, but no `vat_breakdown`, line summary, or equivalent source-context payload beyond `accountChargeUuid` and `fiscalEventId` (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:796`-`815`).

The R3 discounted journal fix is present and balances (`:779`-`:785`, `:820`-`:834`), but dropping the line/VAT summary makes the bridge less complete than the spec and creates a likely R2-fix hazard: the new AR path can pass the happy journal tests while losing VAT partition evidence needed for future split-liability behavior.

Concrete fix: add `vatBreakdown` and a compact line/VAT summary to `CreatePOSChargeJournalEntryCommand`, pass it from the sealed canonical payload in Task 8, and add at least one assertion that the command receives the canonical VAT partition/line summary while still posting the locked AR/ProductRevenue/VatCollected/SalesDiscount lines.

### 4. POS-core D16 grep guard misses some forbidden module families

Task 6 correctly requires POS-core projection to avoid Treasury, Accounting, Document, and Partner imports (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:689`-`697`). The locked D16 guard is broader: POS-core and Fiscal must not import Treasury, Accounting, B2B, Partner, Customer, or Contact operational services (`docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md:360`-`367`, `:456`).

Concrete fix: extend `AccountChargeD16Test` to also reject `Modules\\Customer`, `Modules\\Contact`, and any B2B module namespace/token that exists in the codebase. Since the plan uses Document/Sales for the facture bridge, keep `Modules\\Document` in the POS-core forbidden list too.

### 5. Task 6 makes the PG CI filter update conditional even though the planned projection is PG-sensitive

Task 6 creates a migration with `jsonb('payload_snapshot')` (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:708`-`721`) and a feature projection test (`:681`-`:687`), but Step 4 says to update the PG merge-gate filter only "if" the test depends on PostgreSQL-specific behavior (`:733`-`:737`). This plan is already creating a PG-shaped projection table and feature test, and the user explicitly called out PG filter updates for new fiscal PG-sensitive tests.

Concrete fix: make the `.github/workflows/ci.yml` PG filter update mandatory for `AccountChargeProjectionTest` in Task 6, matching the unconditional updates already required for Task 8 and Task 9 (`:901`-`:905`, `:986`-`:990`).

## Passes

- The `requiresModule() === 'Sales'` choice for the Document/Facture bridge is sound. `Vertical::defaultModules()` exposes `Sales` across the relevant verticals and not `Document` (`apps/api/app/Enums/Vertical.php:122`-`136`), while the fiscal projector token contract requires exact PascalCase tokens from that surface (`apps/api/app/Shared/Contracts/Fiscal/FiscalEventProjector.php:20`-`33`). The plan pins `requiresModule() === 'Sales'` and requires a test for it (`docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md:971`-`981`).
- D8 is preserved at the plan level: POS authors only `ACCOUNT_CHARGE`; the Document/Sales bridge creates a draft and must not post or emit a Tax Invoice fiscal event (`:21`, `:955`-`:969`).
- The discounted AR journal shape from the R3 spec review is carried into Task 7 and Task 8: AR debit, SalesDiscount debit for discounts, ProductRevenue credit, VatCollected credit, no `Payment`/`ReceiptPayment`, and no `createPOSPaymentEntry()` (`:779`-`:785`, `:818`-`:834`, `:866`-`:872`).
- Dead-path coverage is mostly strong: new projectors have provider-registration tasks, bridge tests, and CI entries; the new chokepoint script is wired into CI in Task 10 (`:733`-`:756`, `:901`-`:921`, `:986`-`:1008`, `:1044`-`:1083`).

## Verification Commands

Commands run from `/Users/houssamr/Projects/syneriva/apps/erp.phase-3`:

- `git status --short`
- `nl -ba docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-plan-codex-review.md`
- `nl -ba docs/superpowers/reviews/2026-05-21-pos-charge-to-account-phase3-spec-v1-r3-opus-review.md`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '52,125p'`
- `nl -ba docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md | sed -n '424,440p'`
- `rg -n "extra keys|missing keys|malformed money|VAT partition|synced|pending|stale|fresh|credit-limit|buyer|references|training|production|ambiguous|payment line|insufficient|SalesDiscount|vat_breakdown|line/VAT|line summary|fiscal_event_id" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `rg -n "DocumentAccountChargeFactureBridge|Sales|defaultModules\\(\\)|requiresModule\\(\\).*Sales|DocumentServiceProvider|CompanyConfig::hasModule|hasModule" docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md apps/api/app -g '*.md' -g '*.php'`
- `sed -n '1,180p' apps/api/app/Enums/Vertical.php`
- `sed -n '1,120p' apps/api/app/Shared/Contracts/Fiscal/FiscalEventProjector.php`
- `sed -n '1,140p' apps/api/app/Modules/Fiscal/Application/Services/DefaultModuleActivationResolver.php`
- `rg -n "case CustomerReceivable|case VatCollected|case ProductRevenue|case SalesDiscount|createPOSPaymentEntry" apps/api/app/Modules`
- `test -f .github/workflows/ci.yml && echo ci_exists`

No test suite was run; this was a plan/document review.
