# Codex slice-plan gate r9 — W-CASH-1 rev 9 (gpt-5.6-sol, high, read-only, 2026-09-06)

Input: rev 9 at da3ec1bef. Verbatim.

---
Reviewed read-only at HEAD `da3ec1bef00625467e562cb8021e70fc9b5acb7c`. No edits, tests, or Git writes performed.

Result: **1 BLOCKER, 0 MAJOR, 1 MINOR.**

## BLOCKER

### B1 — The r8 five-point correction remains incomplete and is not executable as written

**Plan lines:** [11–14](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:11>), [364](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:364>), [374](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:374>), [376](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:376>), [472](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:472>), [591](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:591>), [627](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:627>).

Three defects remain:

1. **The company-scale factory cannot be implemented from the declared production set.** Line 472 injects `CompanyRepositoryInterface`, calls `findInTenant()`, and throws `CompanyNotFoundException`, but none of those symbols exists anywhere under `apps/api/app`, and the plan does not declare their namespace, exact new files, signatures, implementations, or bindings. The only shipped resolver accepts a `Company` override at [CurrencyScaleResolver.php:30](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:30>); its normal no-argument path reads `CompanyContext` at [CurrencyScaleResolver.php:43](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:43>). The existing container binding explicitly supplies the otherwise non-autowirable country-finder closure at [AppServiceProvider.php:102](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Providers/AppServiceProvider.php:102>). “Bound as a singleton next to” that binding does not specify how the new factory’s `Closure` and nonexistent repository dependency are constructed.

2. **The exception is not in the register that says it contains it.** Line 472 gives the correct `\DomainException` base and full constructor, but the task-owned table at lines 364–374 has no T3 row for `RepositoryTransferPrecisionCeilingException`. Both line 11 and line 472 claim that row exists.

3. **The complete-envelope test contract is not exact.** Line 374 names `RepositoryTransferErrorRendererTest::test_precision_ceiling_renders_422_with_code_amount_and_effective_scale()` but supplies no exact test file, command, lane, or first failing assertion. The registered class at line 378 is instead `RepositoryTransferErrorContractTest`. Line 374 says the endpoint test asserts the complete envelope, while line 472 specifies only `error.code`. The generic status provider at line 627 does not explicitly bind its 422 case to the precision exception. Line 376 also says every code receives `messages.treasury.<code>` translations, while the normative precision renderer uses `treasury.transfer.precision_exceeds_ledger_scale`.

**Failure scenario:** T3 cannot compile or resolve the company lookup without an implementer inventing a cross-module repository contract, exception, and container wiring—or falling back to the forbidden unbound `CompanyContext`. Separately, implementations can satisfy the named endpoint test while omitting `message`, `amount`, `effective_scale`, or allowing extra properties.

**Minimum correction:**

1. Make the company-bound lookup fully executable: either define exact files/namespaces/signatures for the repository contract, implementation, not-found exception, and all container bindings—including the supplied country-finder closure—or replace them with an exact, existing/autowirable company public-service seam. Preserve `(tenant_id, company_id)` lookup and no-`CompanyContext` operation.
2. Add an explicit T3 exception-register row with the exact file, `extends \DomainException`, constant, and constructor already stated at line 472.
3. Give the renderer test one exact file/class/method/first assertion/command/lane.
4. Require the precision endpoint test itself to assert the exact translated `422` JSON containing only `code`, `amount`, and `effective_scale`, plus unchanged snapshots.
5. State explicitly that the precision message uses the three `treasury.php` files and is excluded from the generic `messages.treasury.*` instruction, or standardize on one backend key family.

## MAJOR

None.

## MINOR

### m1 — Revision metadata still identifies the document as rev 8

**Plan line:** [1](</Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md:1>).

The title says rev 9, but the leading metadata comment begins `W-CASH-1 rev 8`.

**Failure scenario:** handbacks or later gates cite the wrong reviewed revision.

**Minimum correction:** change the leading revision label to `W-CASH-1 rev 9`; retain `Status: awaiting gate r9`.

## Rejected false positives

- **T3 return-type conflict remains:** rejected. Lines 451, 453, 458, 472, and 539 consistently use `DocumentedRepositoryTransferResult` before the atomic T5 rename.
- **Exception inheritance/constructor remain undefined:** rejected as a functional-contract claim. Line 472 now states both exactly; the remaining defect is its absent register row.
- **Human/system snapshot assertions remain unreachable:** rejected. Both tests now use captured exceptions and execute typed-field and unchanged-snapshot assertions.
- **The response still uses `error.details`:** rejected. Renderer and status table now consistently specify flat `error.amount` and `error.effective_scale`; only the test-registration contradiction remains.
- **Translation destinations do not exist:** rejected. All six requested files exist at HEAD: `apps/api/lang/{en,fr,ar}/treasury.php` and `apps/web/src/locales/{en,fr,ar}/treasury.json`.
- **Q11–Q13 differ from the owner record:** rejected. Plan lines 130–132 are text-identical to owner-ruling lines 152–154.
- **This slice ships Q11–Q13:** rejected. No drawer-session model, device reason-code enum/mapping, historical alignment, shift-event booking, v2/v3 adapter, W7 work, or variance activation is included.
- **Existing `MovementReasonCode` is Q12 implementation:** rejected. It remains a pre-existing projected movement field.
- **Movement storage should already accept four-decimal operations:** rejected. `repository_movements.amount` and `balance_after` remain scale three at [migration:20](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:20>) with scale-three casts at [RepositoryMovement.php:53](</Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:53>); the temporary ceiling remains necessary.
- **Older evidence SHA invalidates the anchors:** rejected. HEAD advanced to `da3ec1b`, but the cited production source set has no diff from `93e9ab1`; the anchors still resolve and match.

## Preserve during correction

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

Owner decisions required: **None.** All corrections are engineering-contract/document precision.

VERDICT: CHANGES-REQUIRED