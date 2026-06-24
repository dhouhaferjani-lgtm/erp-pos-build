# Domestic Procurement-to-Pay (GR-IR) — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Each task ends with a passing test + commit. **Every task reads the surrounding code before editing** (exact method bodies for GL posting depend on existing helpers).

**Goal:** Recognize supplier AP + inventory at the correct fiscal moments for domestic purchasing — receipt-first GR-IR (Dr Inventory / Cr 408), supplier invoice clears 408 → 401 + recoverable VAT + non-recoverable timbre, payment clears 401 — with a SupplierInvoice document, 3-way matching, and per-vertical policy.

**Architecture:** New GL posting paths through the canonical hash-chained `postEntry` (resolving accounts by `SystemAccountPurpose`); a `supplier_invoice` `DocumentType` on the unified `documents` table with a separate `match_status`; a `procurement_policies` config table; supplier-invoice PDF attachments on the unified `MediaAsset` system.

**Tech Stack:** Laravel 12, PHP 8.2 strict, PostgreSQL (db-per-tenant), PHPUnit, React/TS web. Spec: `docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md`. Reviews: `…-codex-review{,-r2,-r3}.md`.

## Global Constraints (verbatim from spec)

- **HARD PREREQUISITES (do NOT start until both land via the Codex remediation `docs/superpowers/coordination/2026-06-23-opus-review-remediation-worklist.md`):** **R-1** revert of `PurchaseOrderConfirmedListener`/H-3.2+H-3.1 supplier-AP pair; **R-2** `GeneralLedgerService::postEntry` sets `chain_sequence`. Phase 1 GL collides with the old AP and fails `verifyChain` otherwise.
- **Precision:** never float on money/qty; compute GL amounts with bcmath from numeric strings; **round once with `CurrencyScale::bcround($amount, $scale)` (half-up) — NOT `bcformatStrict` (truncates)**; constructor-inject `CurrencyScaleResolverInterface` (never `app()`); TND scale 3, qty scale 4.
- **Accounts by purpose:** resolve every GL account via `SystemAccountPurpose` + `Account::findByPurpose($companyId, $purpose)` — never a hardcoded number.
- **Enums for all status/type; strict typing (no `mixed`/`any`); module boundaries via `Shared/Contracts`/events/public services; event-immutability (corrections = new credit-note entries, never mutation).**
- **db-per-tenant:** new tables/columns in `database/migrations/tenant/`; PG CHECK constraints need a real-PG test (SQLite can't catch them).
- **TDD:** red test first; **never run the full PHPUnit suite** (`--filter`/path only).
- **VAT only at invoice** (Code de la TVA Art. 9/18); **timbre non-recoverable** (never to `VatDeductible`).

---

## Stage 0 — Prerequisite gate (no code)

- [ ] **0.1** Confirm R-1 landed: `git grep -n "PurchaseOrderConfirmedListener"` shows the listener removed/unwired (no AP posted at PO confirmation). Confirm R-2 landed: `GeneralLedgerService::postEntry` sets `chain_sequence` and a `verifyChain()` test passes after a `postEntry` post. **If either is missing, STOP** — do not start Stage A.

---

## Stage A — Foundations: account purposes + chart seed + policy config

### Task A1 — Add `SystemAccountPurpose` cases for GR-IR + purchase timbre

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` (cases ~`:14-67`, `label()` `:72`, `expectedAccountType()` `:136`)
- Test: `apps/api/tests/Unit/Accounting/SystemAccountPurposeTest.php` (create)

**Interfaces — Produces:** `SystemAccountPurpose::GoodsReceivedNotInvoiced` (value `'goods_received_not_invoiced'`, `AccountType::Liability`), `SystemAccountPurpose::PurchaseStampDuty` (value `'purchase_stamp_duty'`, `AccountType::Expense`).

- [ ] **Step 1 — failing test:**
```php
public function test_grir_and_timbre_purposes_have_correct_account_types(): void
{
    $this->assertSame(AccountType::Liability, SystemAccountPurpose::GoodsReceivedNotInvoiced->expectedAccountType());
    $this->assertSame(AccountType::Expense, SystemAccountPurpose::PurchaseStampDuty->expectedAccountType());
    $this->assertNotEmpty(SystemAccountPurpose::GoodsReceivedNotInvoiced->label());
    $this->assertNotEmpty(SystemAccountPurpose::PurchaseStampDuty->label());
}
```
- [ ] **Step 2 — run, expect FAIL** (`php artisan test --filter=SystemAccountPurposeTest`): undefined case.
- [ ] **Step 3 — implement:** add the two cases; add to `label()` (`'Goods Received Not Invoiced (GR-IR)'`, `'Purchase Stamp Duty (Timbre)'`); add `GoodsReceivedNotInvoiced` to the `Liability` arm and `PurchaseStampDuty` to the `Expense` arm of `expectedAccountType()`. (Do NOT add to `requiredPurposes()` yet — keep optional so existing tenants don't break; see A3.)
- [ ] **Step 4 — run, expect PASS.**
- [ ] **Step 5 — commit:** `feat(accounting): add GoodsReceivedNotInvoiced + PurchaseStampDuty account purposes`

### Task A2 — Seed `system_purpose` on 408 + a timbre expense account (all charts)

**Files:**
- Modify: `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php` (408 at `:140`), `GenericChartOfAccountsSeeder.php`, `FranceChartOfAccountsSeeder.php` (same pattern)
- Test: `apps/api/tests/Feature/Accounting/GrIrChartSeedTest.php` (create; real-PG note)

**Interfaces — Consumes:** A1 purposes. **Produces:** `Account::findByPurpose($companyId, GoodsReceivedNotInvoiced)` resolves to 408; `…PurchaseStampDuty` resolves to a class-6 timbre expense account.

- [ ] **Step 1 — failing test:** seed a tenant + chart, assert both purposes resolve:
```php
public function test_grir_and_timbre_accounts_are_purpose_mapped(): void
{
    [$companyId] = $this->seedTenantWithTunisiaChart(); // helper: tenant + TunisiaChartOfAccountsSeeder
    $grir = Account::findByPurpose($companyId, SystemAccountPurpose::GoodsReceivedNotInvoiced);
    $this->assertNotNull($grir);
    $this->assertSame('408', $grir->code);
    $this->assertSame(AccountType::Liability, $grir->type);
    $timbre = Account::findByPurpose($companyId, SystemAccountPurpose::PurchaseStampDuty);
    $this->assertNotNull($timbre);
    $this->assertSame(AccountType::Expense, $timbre->type);
}
```
- [ ] **Step 2 — run, expect FAIL:** 408 has no `system_purpose`; timbre account/purpose absent.
- [ ] **Step 3 — implement:** set `system_purpose => SystemAccountPurpose::GoodsReceivedNotInvoiced->value` on the 408 row; add a class-6 non-recoverable timbre expense account (confirm the canonical Tunisian number with the accountant — see spec §13; pick the agreed class-6 code) with `system_purpose => PurchaseStampDuty`. Mirror in Generic/France seeders (use the relevant local account; France 408 + a timbre expense).
- [ ] **Step 4 — run, expect PASS** (real-PG: also run against Postgres per the no-SQLite-only rule for constraints; here it's data, SQLite ok but verify on PG in CI).
- [ ] **Step 5 — commit:** `feat(accounting): purpose-map 408 GR-IR + purchase timbre accounts across charts`

### Task A3 — `procurement_policies` table + enum + resolver + per-vertical seed

**Files:**
- Create migration: `apps/api/database/migrations/tenant/<ts>_create_procurement_policies_table.php`
- Create: `app/Modules/.../Procurement/Domain/Enums/{BillControlMode,MatchMode,MatchEnforcement}.php`, `ProcurementPolicy.php` (model), `ProcurementPolicyResolver.php` (service)
- Create: `apps/api/tests/Feature/Procurement/ProcurementPolicyResolverTest.php`

**Interfaces — Produces:** `ProcurementPolicyResolver::forCompany(string $companyId): ProcurementPolicy` returning `bill_control_mode` (`received` Phase 1), `match_mode` (`three_way`), `match_enforcement` (`warn`|`block`), `variance_tolerance_percent`, `variance_tolerance_max_amount`.

- [ ] **Step 1 — failing test:** resolver returns seeded vertical default for a pharmacy company; tolerances ≥ 0.
- [ ] **Step 2 — run, expect FAIL** (no table/resolver).
- [ ] **Step 3 — implement:** migration with `company_id` (unique), enum string columns each with a **PG CHECK allowlist**, `variance_tolerance_percent`/`variance_tolerance_max_amount` decimals with **PG CHECK `>= 0`**; enums (string-backed); model (casts, constructor-injected scale resolver where money is read); resolver reads the company row, falls back to a seeded vertical default. Seed pharmacy/retail/parapharmacy → `received`/`three_way`/`warn`. `bill_control_mode='ordered'` is a reserved enum value that the resolver/services **reject loudly** in Phase 1.
- [ ] **Step 4 — run, expect PASS;** add a **real-PG** test asserting a negative tolerance / invalid enum is rejected by the CHECK (`information_schema`/insert-expect-exception). Add the PG tests to the CI pgsql `--filter` allowlist.
- [ ] **Step 5 — commit:** `feat(procurement): procurement_policies table + resolver + per-vertical seed`

---

## Stage B — Goods receipt posts GR-IR (Dr Inventory / Cr 408)

### Task B1 — Emit a goods-received signal + a GR-IR posting service

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php` (`receiveGoods` ~`:41`, per-line `:196`) — emit a `GoodsReceived` event (or call a posting port) carrying tenant/company/PO-line/received-delta/unit-cost as **numeric strings** (compute BEFORE the float WAC cast at `:153`).
- Create: `app/Modules/Accounting/.../GoodsReceiptGlPoster.php` (new GR-IR posting method — do NOT reuse `createSupplierInvoiceJournalEntry`).
- Test: `apps/api/tests/Feature/Accounting/GoodsReceiptGlTest.php`

**Interfaces — Consumes:** A1/A2 purposes. **Produces:** on receipt, a posted JE `Dr Inventory / Cr GoodsReceivedNotInvoiced` for `received_delta × unit_cost`, VAT-excluded, partner-untagged, via canonical `postEntry` (R-2).

- [ ] **Step 1 — failing test:** receive 5 units @ cost 10.000 → a posted JE exists with `Dr Inventory 50.000` / `Cr 408 50.000`, no VAT line, and `verifyChain()` passes.
- [ ] **Step 2 — run, expect FAIL** (no GL posted today).
- [ ] **Step 3 — implement:** compute amount = `CurrencyScale::bcround(bcmul($unitCostStr, $qtyStr, $scale+2), $scale)` from strings; post `Dr Inventory / Cr GoodsReceivedNotInvoiced` through the canonical hash-chained path; idempotent per receipt line (unique anchor so re-delivery doesn't double-post).
- [ ] **Step 4 — run, expect PASS** (+ a partial-receipt test: two receipts accrue 408 incrementally).
- [ ] **Step 5 — commit:** `feat(accounting): post GR-IR (Dr Inventory/Cr 408) on goods receipt`

---

## Stage C — SupplierInvoice document + cumulative invoiced + 3-way match + invoice GL

### Task C1 — `supplier_invoice` DocumentType + `match_status` + `quantity_invoiced` column

**Files:**
- Modify: `app/Modules/Document/Domain/Enums/DocumentType.php` (case + `getPrefix()` `:21` + `label()` `:38` + numbering), `app/Modules/Document/Domain/Enums/FiscalCategory.php` (confirm `NON_FISCAL` default)
- Create: `app/Modules/.../Enums/SupplierInvoiceMatchStatus.php` (`unmatched|matched|price_variance|quantity_variance|exception`)
- Create migration: add `quantity_invoiced decimal(15,4) default 0` to `document_lines` (tenant); add `match_status` to the supplier-invoice (documents) representation.
- Test: `apps/api/tests/Feature/Procurement/SupplierInvoiceDocumentTest.php`

- [ ] TDD: create a `supplier_invoice` document → it persists with `DocumentType::SupplierInvoice`, hydrates (status via `DocumentStatus` draft/posted/paid only — NO `matched`), `match_status` defaults `unmatched`, `FiscalCategory::NON_FISCAL`. Red first (enum case missing), then implement the exhaustive enum surfaces + migration, then green. Commit.

### Task C2 — 3-way matcher with the HARD quantity invariant + advisory price variance

**Files:**
- Create: `app/Modules/.../Procurement/Application/SupplierInvoiceMatcher.php`
- Test: `apps/api/tests/Feature/Procurement/SupplierInvoiceMatcherTest.php`

**Interfaces — Produces:** `match(SupplierInvoice): SupplierInvoiceMatchStatus`; a `matchable_qty(POLine) = quantity_received − quantity_invoiced`.

- [ ] **Step 1 — failing tests (the invariant is the point):**
  - receive 10, invoice 6 → `matched`; matchable now 4.
  - receive 10, invoice 6 then invoice 6 → second is `quantity_variance` (6 > 10−6=4) → **MUST be blocked regardless of `match_enforcement`** (assert posting throws even when policy is `warn`).
  - price within tolerance → `matched`; price beyond tolerance → `price_variance` (post allowed under `warn`, blocked under `block`).
- [ ] Steps 2-4: run-fail → implement matcher (resolve tolerances from `ProcurementPolicyResolver`; quantity over-clear is a hard rule, price variance is advisory) → run-pass.
- [ ] **Step 5 — commit:** `feat(procurement): 3-way matcher with hard 408 quantity invariant + advisory price variance`

### Task C3 — Post supplier invoice GL (clears 408 → 401 + VAT + timbre) under lock + idempotency

**Files:**
- Create: `app/Modules/Accounting/.../SupplierInvoiceGlPoster.php`
- Modify: the supplier-invoice posting action (one DB transaction)
- Test: `apps/api/tests/Feature/Accounting/SupplierInvoiceGlTest.php`

**Interfaces — Consumes:** A1/A2 purposes, C2 matcher. **Produces:** on post, in ONE transaction: `SELECT … FOR UPDATE` the matched PO lines → recheck the hard invariant → increment `quantity_invoiced` → post `Dr GoodsReceivedNotInvoiced + Dr VatDeductible + Dr PurchaseStampDuty / Cr SupplierPayable` (partner-tagged 401) → unique idempotency anchor.

- [ ] **Step 1 — failing tests:** posting an invoice for received goods clears 408 (Dr) for the invoiced HT, debits `VatDeductible` from **recoverable** tax (sum `document_lines.recoverable_tax_amount`, NOT `documents.tax_amount`), debits `PurchaseStampDuty` from `documents.stamp_duty_amount`, credits 401 gross TTC, **debits == credits** at scale 3, `verifyChain()` passes; `quantity_invoiced` incremented; a duplicate post (same idempotency key) is a no-op. (Use `bcround` once per leg.)
- [ ] Steps 2-4: run-fail → implement the locked, idempotent transaction + the new poster (NOT `createSupplierInvoiceJournalEntry`) → run-pass.
- [ ] **Step 5 — commit:** `feat(accounting): post supplier-invoice GR-IR clearing under lock + idempotency`

### Task C4 — Payment clears 401

- [ ] Verify/extend the existing treasury payment path so paying a supplier invoice debits `SupplierPayable` (401, partner-tagged) and reduces `payable_balance`. Red-first test (payment → 401 debit, payable_balance down), implement (this is the correct re-home of the reverted H-3.1 mechanic, now against a real payable), green, commit.

---

## Stage D — Supplier credit note (GL matrix + quantity reversal)

### Task D1 — Supplier credit-note type + GL matrix + `quantity_invoiced` reversal

**Files:**
- Modify: `DocumentType` (a supplier-credit-note type/flag), a new `SupplierCreditNoteGlPoster`
- Test: `apps/api/tests/Feature/Accounting/SupplierCreditNoteGlTest.php`

**Interfaces — Consumes:** the spec §7 GL matrix. **Produces:** posting a supplier credit note applies the matrix (NOT `createFromCreditNote`) and adjusts `quantity_invoiced` per the rules.

- [ ] **Step 1 — failing tests, one per matrix row:**
  - price-only reduction → `Dr 401 / Cr VatDeductible + Cr Inventory|price-variance`; `quantity_invoiced` unchanged.
  - returned goods still in stock → `Dr 401 / Cr VatDeductible + Cr Inventory`; `quantity_invoiced −= returned qty`; re-invoicing of that qty allowed afterward.
  - over-credit (more than invoiced) → blocked.
- [ ] Steps 2-4: run-fail → implement the matrix poster + reversal → run-pass.
- [ ] **Step 5 — commit:** `feat(accounting): supplier credit-note GL matrix + quantity_invoiced reversal`

---

## Stage E — Attachment via unified MediaAsset (thin port)

### Task E1 — `MediaOwnerType::SupplierInvoice` + document upload port + `SourceDocument` role

**Files:**
- Modify: `app/Modules/Catalog/Domain/Enums/MediaOwnerType.php` (`:7` add `SupplierInvoice`), `MediaRole.php` (`:7` add `SourceDocument`)
- Create: a supplier-invoice **document upload port** (interface in the Procurement module) + an adapter that stores `MediaAssetType::Document` (PDF/scan MIME, skip image renditions) and creates a `MediaAttachment(owner_type=SupplierInvoice, owner_id=<doc id>, role=SourceDocument)` on the **s3/MinIO** disk.
- Test: `apps/api/tests/Feature/Procurement/SupplierInvoiceAttachmentTest.php`

- [ ] TDD: uploading a PDF to a supplier invoice creates a `MediaAsset` (type `Document`, disk `s3`) + a `MediaAttachment` linked by owner_type/owner_id/role; the Procurement code depends only on its own port interface (does NOT import `Catalog\…` models directly — coordinate the owner-type name with the media-unification session). Red→implement→green→commit. **Do NOT touch legacy `DocumentAttachment`/`AttachmentService`.**

---

## Stage F — Web: Supplier Invoices nav + list + match view

### Task F1 — Purchases → Supplier Invoices list/detail

**Files:** `apps/web/src/features/.../supplier-invoices/*` (list, filters by supplier/status/match_status/date; detail shows lines, linked PO/receipt, match result, attachment); route + nav under Purchases (module-gated where appropriate).
- Test: Vitest for the list/filter + the match-status badge; typecheck + ESLint.
- [ ] TDD per the web conventions (`t()` for all strings, design tokens, types from `php artisan typescript:transform`). Red→implement→green→commit.

---

## Stage G — Verification & sign-off

- [ ] Scoped backend: `php artisan test --filter='SystemAccountPurpose|GrIrChart|ProcurementPolicy|GoodsReceiptGl|SupplierInvoice|SupplierCreditNote'` green; PHPStan L8 on changed dirs; Pint; real-PG run of the CHECK-constraint + GL tests.
- [ ] Frontend: `pnpm vitest run src/features/.../supplier-invoices && pnpm typecheck && pnpm lint`.
- [ ] End-to-end (real entries): PO → receive (408 accrues) → supplier invoice (408 clears → 401 + VAT + timbre, debits==credits, `verifyChain` ok) → payment (401 clears) → a returned-goods credit note (401 reduced, `quantity_invoiced` reversed). Confirm the hard quantity invariant blocks an over-invoice under `warn`.
- [ ] **Codex + Opus review of the diff** before merge; address BLOCKER/HIGH.

---

## Self-Review (author checklist — completed)

- **Spec coverage:** §3 GL → B1/C3/D1; §4 policy → A3; §5 document/attachment → C1/E1/F1; §6 matcher (hard invariant + lock + enum) → C2/C3; §7 credit note → D1; new purposes → A1/A2; precision/bcround → global + B1/C3; R-1/R-2 gate → Stage 0. All mapped.
- **Decisions baked in:** match `warn|block` is advisory for PRICE only; quantity over-clear is hard; `bcround` not `bcformatStrict`; accounts by purpose; MediaAsset not legacy; `procurement_policies` table not company column.
- **Note:** Stages C/D/E/F tasks specify file targets + test contracts + exact GL/account/enum contracts; the executing subagent reads the existing `GeneralLedgerService`/`Document`/matcher patterns to fill method bodies (the GL contracts and invariants here are exact). Stage A + B carry full code.
