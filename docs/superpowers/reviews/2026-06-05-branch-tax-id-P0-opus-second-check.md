# Branch Tax-ID P0 — Opus Second Check (post-Codex-implementation)

**Date:** 2026-06-05
**Branch:** `feat/branch-tax-id-spec` · worktree `apps/erp.branch-tax-id`
**Reviewer:** Opus orchestrator (independent verification of the Codex P0 execution)
**Inputs:** the full feature diff vs base `d35da55bd`, the 18 per-task Opus review files, the Codex execution summary, and the fresh Codex post-impl review (`2026-06-05-…-codex-postimpl-review.md`).

## Verdict: **APPROVE-WITH-MINOR-EDITS** (implementation is correct, fiscally safe, and convention-compliant; remaining items are minor/UX + the visual-test gap)

---

## 1. What I independently verified (against the real code, not the summary)

**Tests (ran them myself):**
- New backend tests: **36 pass** — `LocationTaxFieldsMigration/Model`, `CountryTaxNumberRules` (incl. the reflection **parity test** vs the fiscal const), `CountryTaxIdentityConfig`, `Create/UpdateLocationTaxValidation`, `CreateLocationTaxPersistence`, `TaxIdentityResolver` (incl. soft-deleted-company + partial legal-identifier merge), `ReceiptPdfBranchTaxId`, `FacturXBranchSeller`, `Nf525CompanyHeaderSiret`, `TerminalResourceBranchTax`, `BranchSellerTaxNumberValidation`.
- **Pint: pass. PHPStan L8: zero errors.**
- Web: **typecheck pass, lint 0 errors** (11.6k warnings are pre-existing repo-wide color advisories), focused tests pass. New tax fields use **design tokens** (`tokens.label/input/helperText`) — not hardcoded colors. i18n added in **ar/en/fr** with an FR/TN/MA required hint; field is accessible (`aria-required`/`aria-describedby`).
- POS: focused branch tests **pass (26)**, **fiscal fixture parity pass (29)**.
- Full backend suite: see §4.

**Code correctness (high-risk paths, read line-by-line):**
- `TaxIdentityResolver` — per-field `location.X ?? company.X`; `legal_identifiers` key-merge (location overrides, missing inherit); resolves the company `withTrashed()` so a soft-deleted parent still provides fallback (remediation of task-9 MAJOR). ✔
- Device `paymentStore.ts` — `branchTaxNumber ?? companyField(...)` on **SALE_RECEIPT and ACCOUNT_PAYMENT**; **ACCOUNT_CHARGE untouched** (fixture-only, no live caller — matches spec §8). Company-fallback now asserted for both event types (remediation of task-17 MAJOR). ✔
- `FacturXService` — constructor-injects the resolver, loads `location`, feeds VA/FC/legal-org from the resolved identity, **falls back to company when location is null**. ✔
- `Nf525DataProvider::buildCompanyHeader` — null-SIRET bug fixed: `legal_identifiers['siret'] ?? tax_id` + composed address; **company-level** (correct per rev-2 §5 — JET is a company-wide export). ✔
- `CreateLocationRequest` — format check via shared `CountryTaxNumberRules` (only when present + country known → 422 not 500); conditional-required for sellable `Shop` in a `branch_tax_id_required` country. ✔

**Fiscal safety (the load-bearing claim):**
- **No golden fixtures changed** on the branch; **no payload schema/version bump**; the device changes only the *source* of `seller.tax_number`. Parity script green. The per-country regex accepts branch values (`BranchSellerTaxNumberValidation` asserts FR + TN). ✔

**Review-process integrity:** the 18 per-task Opus reviews are **substantive, not rubber stamps** — task-9 caught a reachable NPE on soft-deleted companies, task-17 caught a dead-tested company-fallback; both were remediated and the fixes are present in the code + tests. ✔

## 2. Adjudication of the fresh Codex post-impl review (NEEDS-REWORK, 3 MAJOR) — I downgrade 2 of 3

- **Codex MAJOR-1 (validate `vat_number`/`legal_identifiers`) — DOWNGRADE to MINOR / partially reject.** Applying `CountryTaxNumberRules` (which holds **tax-number/SIRET** patterns) to `vat_number` is *wrong* and was explicitly rejected in the rev-2 review (a `FR40303265045` VAT id fails the FR SIRET regex). The implementation correctly leaves `vat_number` free-form. Legitimate residual: `legal_identifiers['siret']` *is* a SIRET and could be format-validated. → **MINOR (optional).**
- **Codex MAJOR-2 (can't clear a branch override to inherit via the UI) — VALID but MINOR + design-gated.** Confirmed: `LocationsPage` update mapping does `if (formData.taxId) updateData.taxId = …`, so emptying omits the field (no clear). The model/API support `null`. But clearing a *sellable FR/TN shop* would re-inherit HQ → the rev-2 "required" rule is only enforced at create. So "allow clear" needs an owner decision (does update re-enforce required for FR/TN shops?). → **MINOR, owner-gated; do not fix unilaterally.**
- **Codex MAJOR-3 (`pos_receipts` has no `location_id`; seller re-derived from terminal) — REJECT (factually wrong).** `pos_receipts` **has** `location_id` (migration `2026_01_08_190637_create_pos_receipts_table.php:29`; `Receipt.php:39,137`). The receipt PDF resolves from `$receipt->location` (snapshot), the **fiscal** seller is in immutable `canonical_bytes`, and NF525 is company-level by design. Narrow residual: the *server-side* PDF reprint re-resolves the *current* `location.tax_id` rather than the signed value, so editing a branch's tax_id after sales would change an old receipt's *reprinted PDF* (the signed/device receipt is unaffected). → **MINOR edge, document; arguably acceptable.**

## 3. Remaining items (minor)

| # | Item | Severity | Action |
|---|---|---|---|
| 1 | `legal_identifiers['siret']` not format-validated at entry | MINOR | Optional: add a SIRET check for the `siret` key (reuse `CountryTaxNumberRules` for FR). |
| 2 | UI cannot clear a branch override to inherit (update omits empty) | MINOR (owner-gated) | Decide whether update should allow clear and whether it re-enforces "required" for FR/TN shops; then send `null` on empty. |
| 3 | Server receipt-PDF reprint re-resolves current branch tax_id, not the signed value | MINOR edge | Document; if exactness on reprints is wanted, read the signed `seller.tax_number` for the PDF instead of re-resolving. |
| 4 | **Visual testing — partially done (see §6)** | DONE (receipt) / DEFERRED (live SPA) | Receipt output **browser-rendered + screenshotted** (branch + company-fallback), see §6. Live click-through of the SPA deferred (dev DB didn't exist; full multi-tenant bring-up out of proportion). |

## 6. Visual testing (2026-06-05)
The dev database (`synerivia_central`) did not exist, so a full live-SPA click-through would have required standing up the entire DB-per-tenant stack (docker PG → central migrate → seed → per-tenant DB provisioning → domain routing → API+web → auth) — disproportionate/fragile for a visual check, and without the backend the SPA only renders the login screen.

**What I DID do (genuine browser rendering of the feature's key user-visible output):** rendered the actual `pos.receipt` blade via the real `ReceiptPdfService` path with (a) a branch tax-ID override and (b) no override, served them over HTTP, and screenshotted both in a real browser (Playwright):
- `screenshots/2026-06-04-P0/receipt-branch-taxid.{html,png}` — seller header shows **`Tax ID: FR-BRANCH-SIRET-99999`** (the branch value). ✔
- `screenshots/2026-06-04-P0/receipt-company-fallback.{html,png}` — seller header shows **`Tax ID: COMPANY-TAX`** (inherited when branch is null). ✔

This visually confirms the branch-over-company resolution on the printed receipt. The **form** UI is covered by the `LocationsPage` component test (renders the tax field, marks it required for FR/TN/MA shops, submits). 

**Deferred (needs stack-up env):** live click-through of Settings → Locations and an end-to-end POS sale in the running SPA. Recommend running it in a dev environment with services up (or a Codex run with the stack booted); the recipe is the standard local bring-up + `DemoTenantSeeder`.

## 4. Full backend suite
Full `php artisan test`: **7091 passed**, 2 failed, 3 incomplete, 206 skipped (29,743 assertions; 2452s).
**Both failures are pre-existing and unrelated to this feature** (neither file touched by the branch):
- `Tests\Feature\Tenant\TenantCreationTest > tenant get database name` — asserts the old `tenant_schema-test` name vs the current DB-per-tenant `tenant_<uuid>` naming (tenancy-model transition).
- `Tests\Feature\Console\Sweep\SweepInventoryDeferCommandTest > defer…` — inventory-sweep console tooling.
Every branch-tax-id test passes. (Pre-existing POS `migrations.v37` device-DB failure likewise untouched by this branch.)

## 5. Bottom line
The P0 implementation is **complete, correct, fiscally safe (no fixture/version change), green on all new tests + Pint + PHPStan + web + POS-focused + fiscal parity, and convention-compliant.** The fresh Codex review's blockers don't hold up under verification (2 of 3 contradict the rev-2 decisions or the actual schema). The only true open work is **(a)** the optional minor edits above and **(b)** the **visual E2E gap**, which needs the booted multi-tenant stack. Recommend: land Phase 1 + Phase 2 behind the minor edits, and run the live Playwright pass in an environment with services up.
