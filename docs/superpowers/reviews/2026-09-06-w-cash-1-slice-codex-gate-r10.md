# Codex slice-plan gate r10 — W-CASH-1 rev 10 (gpt-5.6-sol, high, read-only, 2026-09-06) — DISPATCH-READY

Input: rev 10 at 327727bdc. Verbatim.

---
Reviewed read-only at HEAD `327727bdc10290b6a1a366559ab415ddc5975d14`. No edits, tests, or Git writes were performed.

Result: **0 BLOCKER, 0 MAJOR, 0 MINOR.**

## Findings

### BLOCKER

None.

### MAJOR

None.

### MINOR

None.

There are therefore no failure scenarios or minimum corrections to register.

## Prior r9 closure

All five B1 corrections and metadata minor are closed:

| r9 item | Verification |
|---|---|
| B1.1 company-scale seam | [Plan:483](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:483) declares exact NEW exception and factory files, namespaces, constructors, `(tenant_id, company_id)` lookup, concrete container binding, country closure, non-null company override, and first-statement checks for human/system intents. Its dependencies match [Company.php:133](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Company/Domain/Company.php:133), [CurrencyScaleResolver.php:30](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:30), and [AppServiceProvider.php:101](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:101). No nonexistent repository/finder symbol remains. |
| B1.2 exception register | [Plan:381](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:381) now contains the exact file, `\DomainException` base, constant, constructor, readonly fields, and message. |
| B1.3 renderer test | [Plan:385](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:385) and [plan:389](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:389) specify one NEW file, namespace/class, method, first failing assertion, complete-envelope assertion, exact command, and PG lane. |
| B1.4 endpoint contract | [Plan:638](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:638) requires exact translated 422 JSON, exactly three error properties, and unchanged document, movement, repository balance/ordinal, and JE snapshots. |
| B1.5 translation family | [Plan:387](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:387) explicitly excludes precision from `messages.treasury.*`; [plan:385](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:385) assigns it to the three backend `treasury.php` and frontend Treasury locale files. All six files exist. |
| m1 metadata | [Plan:1](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1) and the title now consistently identify rev 10 awaiting gate r10. |

## Full gate verification

- All 132 full `path:line` occurrences—109 unique references—resolve at HEAD and are in bounds. The only change from the plan’s `b8b2b8e…` evidence baseline to current HEAD is this plan itself; governed production sources did not change.
- Existing behavior is represented accurately: the scalar transfer signature, optional draft, two legs, document-less result, tenant/company lookup, movement keys, and scale-three movement storage/casts all match HEAD.
- P0 and T1–T6 have exact production/test files, schema contracts, enum/CHECK contracts, red-first assertions, commands and named lanes. Reviewer gates and rollback instructions are present at [T1/T2:369](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:369), T2 `:456`, T3 `:533`, T4 `:580`, T5 `:666`, and T6 `:706`.
- Convention 09’s second-company, second-location, and actual-mutation rerun requirements are mapped per task, consistent with [convention 09:37](/Users/houssamr/Projects/syneriva/apps/erp/docs/conventions/09-SECOND-OF-EVERYTHING.md:37).
- The convention-10 matrix at [plan:117](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:117) has the required eight columns and covers create, duplicate, edit, cancellation/reversal, rerun, second company, second location, permissions, audit, and document-per-action. Its limited benchmark statements are consistent with the linked [Odoo](https://www.odoo.com/documentation/17.0/applications/finance/accounting/payments/internal_transfers.html), [ERPNext](https://docs.frappe.io/erpnext/payment-entry), and [Dolibarr](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash) documentation.
- Convention 11 is satisfied: Repository remains the canonical Treasury surface, and the two new concepts have table, owner, primary writer, surface, and glossary additions specified at [plan:130](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:130).
- Q11–Q13 at [plan:140](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:140) are text-identical to [owner rulings:152](/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:152). [Plan:144](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:144) explicitly defers reason-code enums/mappings, drawer sessions, historical alignment, shift booking, v2/v3 adapters, W7, and variance activation.
- Deployment uses the manifest’s required sentence at [plan:710](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:710), supplies every variable required by [manifest:273](/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:273), specializes backup/rollback safely, and reproduces §4 verbatim.

## Rejected false positives

- **The older evidence SHA invalidates anchors:** rejected; only the plan changed after its baseline.
- **The company-scale seam still requires nonexistent repository symbols:** rejected; those symbols were removed and replaced by fully declared NEW concrete types using existing HEAD seams.
- **The factory falls through to unbound `CompanyContext`:** rejected; it supplies the existing resolver’s non-null company override before no-argument `getScale()`.
- **The Shared factory creates a Treasury→Company model import:** rejected; Treasury consumes the Shared currency-resolution seam and does not import the Company model.
- **The precision message still belongs under generic messages:** rejected; the exception is expressly excluded from that family.
- **Q11–Q13 are pending or implemented here:** rejected; they are RULED verbatim and explicitly deferred.
- **Existing `MovementReasonCode` implements Q12:** rejected; it remains a pre-existing projected movement field, not the deferred device enum/mapping.
- **P0’s scale-four storage permits four-decimal movement operations:** rejected; the temporary scale-three operational ceiling and verbatim removal condition remain mandatory.

## Preserve during implementation

- P0-a census before P0-b widening, per-tenant evidence, backup, and forward-only rollback.
- Four-column `(15,4)` storage contract and isolated raw `1.0005` proof.
- Temporary three-decimal movement-ledger ceiling and verbatim removal condition.
- One document, one transfer group, exactly two cross-linked legs, and zero-or-one posted JE.
- Human/system authority union and stored-document-first replay authorization.
- Append-only linked reversals, frozen-transfer evidence, and after-commit alerts.
- W1 authorization as the hard HTTP-activation prerequisite.
- Company-scoped ownership, enums, typed JSONB evidence, and generated DTO migration.
- Convention-09 tests per task and the exact convention-10 eight-column matrix.
- Existing Repository operator surface and glossary additions.
- Exact Q11–Q13 wording and all later-slice deferrals.
- Shared-manifest reference, required variables, topology/backup gates, web fingerprint, five pushes, and forward-only rollback.

Owner decisions required: **None.**

VERDICT: DISPATCH-READY