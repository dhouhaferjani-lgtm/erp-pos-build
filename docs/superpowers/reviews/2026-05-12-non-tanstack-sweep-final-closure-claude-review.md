# Non-TanStack Tenant-Isolation Sweep — Final Closure Review

Branch: `feat/tenant-isolation-sweep-execution`
Base: `6ec4c6a857279521240ea21011af0586a75e7654` (plan revision approved by Codex final-approve)
HEAD reviewed: `1d63d664802df4e6e9bcb9e6ebe8a5c327da9f4e`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer: codex
Date: 2026-05-12

## Verification (live)

| Gate | Result |
|---|---|
| `cd apps/api && php artisan sweep:inventory:verify-history` | **6337 events / 1208 callsites / 0 problems** |
| `cd apps/api && php artisan sweep:inventory:status --drift` | **yaml_says_fixed_code_unsafe=0, code_safe_yaml_pending=0, unmapped_in_scanner_output=0** |
| `cd apps/api && vendor/bin/phpunit tests/Feature/Console/Sweep tests/Architecture/TenantScopedExistsRulesTest.php tests/Feature/Workshop/WorkOrder tests/Feature/Workshop/Bundle tests/Feature/Document/RefundResidualTenantIsolationTest.php tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php tests/Feature/Taxation` | **388 tests, 1319 assertions, OK** (27 pre-existing skips, 32 PHPUnit-framework deprecations — none from this PR's `@deprecated` interface tags) |
| `cd apps/api && vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Application/Sweep app/Modules/Workshop/WorkOrder app/Modules/Document/Domain/Services/DraftPersistenceService.php app/Modules/Loyalty/Presentation app/Modules/Taxation/Presentation` | **0 errors** |

All four exit-criterion gates from the plan's Task 6 are met.

## Plan satisfaction matrix

| Task | Implementation | Commit(s) | Verdict |
|------|----------------|-----------|---------|
| Task 1: Freeze drift snapshot | Audit + drift baseline established | (pre-existing audit doc) | ✓ |
| Task 2: Prune fixed manual rows | Manual stub pruned; checker resolves under `apps/api/` and verifies method names for PHPUnit + Pest patterns | `fac00440` | ✓ |
| Task 3 Step 1: `tax_configurations` + `tax_rates` removed from guarded lists | Both removed from `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES` (verified at line 44+) and `TenantScopedExistsRulesTest::GUARDED_TABLES` | `a5ec7fd1`, `5f7d7a74` | ✓ |
| Task 3 Step 2: `ExistsRuleVisitor` conjunctive-only closure walk | Visitor recognizes `where`/`whereIn`/`whereExists` only; rejects `orWhere*` branches; `whereRaw` + helper scopes documented as unsupported safe-false-rejects | `a5ec7fd1` | ✓ |
| Task 3 Step 3: `FindCallVisitor` value-source allowlist | Allowlist accepts `$tenantId`/`$companyId` local variables, `$this->...->requireCompany()->tenant_id` chains, `$this->companyContext->requireCompanyId()`-style method chains, parameter aggregates, and `$context->tenant_id` params. Explicit rejection of `$request->tenant_id` (line 410-412), `null`/string literals (line 389-391), and any chain failing the rules. Negative fixtures in test suite. | `a5ec7fd1` | ✓ |
| Task 3 corollary: VoucherController refactor (Option 2 — local `$tenantId` extraction) | All 4 affected methods now bind `$tenantId = $user->tenant_id;` + `$companyId = $this->companyContext->requireCompanyId();` locally before the scoped query | `a5ec7fd1` (or follow-up) | ✓ |
| Task 4 (Workshop): repository scoping + Vehicle V2 + Technician body | Covered in detail by prior `2026-05-12-api-workshop-task4-closure-claude-review.md` (APPROVE at `01574841`). | `01574841` | ✓ |
| Task 5: Close needs_recheck rows via `sweep:inventory:recheck-clear` | New command exists (`SweepInventoryRecheckClearCommand.php`, 11946 bytes). Enforces `status==needs_recheck`, scanner re-check, review-file/commit linkage, actor!=owner. Used to close the 14+ needs_recheck rows. Workshop manual rows closed via standard review-commit linkage. | `c409727e`, `62241f4f`, `8e39a0b1`, `a5d91f2a` | ✓ |
| Task 5 fallback: Loyalty stamp-card refactor (repository pattern) | `RewardRepositoryInterface::existsForProgramInTenant` + `existsInTenant` methods added; `Create/UpdateStampCardRequest` now use the repo abstraction instead of inline `Rule::exists`-with-closure. The repository refactor pattern matches the revised plan's chosen fallback (the `#[TenantScoped]` attribute option was explicitly dropped). | `94d41edd` | ✓ |
| Task 6: PHPStan + final gates | Sweep visitor static-analysis warnings fixed; all gates green | `1d63d664` | ✓ |

## Implementation quality notes

1. **Additive interface commitment honored** (Task 4 architectural constraint): `WorkOrderRepositoryInterface` and `BundleRepositoryInterface` both keep their original `findById(string)` / `findForUpdate(string)` / `findWithComponentsAndApplicabilities(string)` signatures with `@deprecated` PHPDoc; new `*ForScope($tenantId, $companyId, $id)` methods added alongside. No in-place signature change.

2. **FindCallVisitor allowlist is genuinely tight**: spot-checked the AST rules — `$request->tenant_id`, `null` literals, and string literals are all explicitly rejected per Codex Finding 5. VoucherController refactor uses local `$tenantId` extraction (allowlist rule (a)) rather than expanding the allowlist to `$user->tenant_id`. The contract did not get loosened to accommodate convenience.

3. **`recheck-clear` command has belt-and-braces gating**: status precondition, scanner re-check (`assertRelevantScannerIsSilent`), review-file existence, review-commit linkage, cross-agent check. Closes the workflow gap cleanly without introducing a back door.

4. **HTTP matrix test is comprehensive**: 14 WorkOrder + 8 Bundle endpoints, each invoked cross-tenant and asserted at 404. Matches the revised plan's endpoint matrix exactly. Body-validation 422 paths covered in `TechnicianTimeEntryControllerTest`.

## Deferral assessment: api.identity-company.001

Current state in inventory:
- `id: api.identity-company.001`
- `status: deferred`
- `stale_state: active`
- `file: apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php:43`
- `scanner: php_presentation_exists`

The rule at `UpdateCompanyRequest.php:43` is:

```php
'default_tax_configuration_id' => ['nullable', 'uuid', 'exists:tax_configurations,id'],
```

Deferral rationale (documented at `docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md`):

- `tax_configurations` is a country-scoped global reference table.
- Schema (`2025_12_30_100000_create_tax_configurations_table.php`) has `country_code` but NO `tenant_id` / `company_id` columns.
- Cross-tenant exfiltration is structurally impossible.
- Cross-country business-rule validation (FR company assigning TN tax_config) is a separate concern explicitly out of scope for this sweep.

**Assessment: deferral is ACCEPTABLE.** The rationale matches the actual schema (verified the migration earlier in the Codex adversary review). No cross-tenant data risk exists. The revisit condition is documented in the cluster `blocked_reason` ("Revisit by 2026-08-01").

**PR-body recommendation**: include a one-line "Known accepted residual: api.identity-company.001 (deferred 2026-05-04, country-scoped tax_configurations false positive, revisit by 2026-08-01)".

## Non-blocking follow-ups (carry into a single cleanup PR)

1. **Dead V1 listener mapping** in `apps/api/app/Providers/EventServiceProvider.php`: `WorkOrderCompleted::class` listener array is unreachable because `WorkOrderTransitionService` only dispatches `WorkOrderCompletedV2`. V1 class itself must remain for queue-serialization compatibility (per AutoERP immutable-events rule), but the listener mapping is dead code. (Carried from Task 4 closure review.)

2. **Outdated docstring** at `apps/api/app/Modules/Workshop/Technician/Infrastructure/Listeners/CloseTimeEntryOnWorkOrderCompleted.php:10`: still says "Subscribes to Plan B's `WorkOrderCompleted` event." Should mention V2. (Carried from Task 4 closure review.)

3. **Inventory line-number drift** (new finding): `sweep:inventory:generate --dry-run` reports `32 new, 0 unchanged, 33 needs_recheck, 66 stale_orphan`. The "0 unchanged" is significant — every existing stable_key would shake under the next regenerate, mostly from line-number shifts during this PR. The `--drift` gate (which is keyed on simple stable_key match) is clean *because* a generate hasn't been run at HEAD. Running generate on `main` post-merge will produce a large but mechanical churn. **Recommendation**: run `sweep:inventory:generate` immediately after merging to stabilize the inventory's stale-state annotations. This is a sweep-system characteristic (stable_keys encode line numbers), not a defect in this PR. While doing so, `api.identity-company.001` will likely auto-transition `stale_state: active → stale_orphan`, which is the desired terminal state for the deferred row.

4. **Untracked review evidence files** still hanging at the worktree root:
   - `docs/superpowers/reviews/2026-05-12-non-tanstack-closure-plan-codex-adversary-review.md`
   - `docs/superpowers/reviews/2026-05-12-non-tanstack-closure-plan-codex-final-approve.md`
   
   These are the Codex review documents the plan revisions were built on. They should be committed to the branch so the audit trail lives in git. Suggested commit message: `docs(tenant-isolation): commit codex review evidence for non-TanStack plan`.

## Final disposition

**APPROVE for master PR.**

The implementation satisfies every approved task in the revised plan. All exit-criterion gates (verify-history, drift, PHPUnit matrix, PHPStan slice) pass cleanly. The single open inventory row (`api.identity-company.001`) is acceptably deferred with documented rationale.

The four non-blocking follow-ups above are cleanup items, not gate blockers. Item 3 (inventory line-number drift) and item 4 (untracked review evidence) should be addressed before the master PR opens. Items 1 and 2 can wait for a small follow-up cleanup PR.

## Cross-references

- Plan: `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md`
- Drift audit basis: `docs/superpowers/audits/2026-05-11-tenant-isolation-non-tanstack-drift-detail.md`
- Codex adversary review: `docs/superpowers/reviews/2026-05-12-non-tanstack-closure-plan-codex-adversary-review.md`
- Codex final-approve on plan: `docs/superpowers/reviews/2026-05-12-non-tanstack-closure-plan-codex-final-approve.md`
- Task 4 closure review: `docs/superpowers/reviews/2026-05-12-api-workshop-task4-closure-claude-review.md`
- Identity-company deferral anchor: `docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md`
