# Opus adversarial review — api.service cluster (round 2)

Review date: 2026-05-04
Branch reviewed: feat/tenant-isolation-sweep-execution
Branch tip at review time (start of round-2): 189a56c84e2f81b3ebe8eca2da5a140bcf19ae9b
Branch tip at review time (post stash/pop dance — concurrent push): e4906c34be312ce8540e2c3277c0b2d4f7854318
Commit reviewed: 77185828
(Note: Service-portion of 77185828 only — that portion was reverted by f08a6ba2 and restored verbatim at HEAD via 67dcbf5a; see Round-1 regression status section below for verification chain.)
Forward-fix commit under primary scrutiny: 67dcbf5a (fix(service): re-apply tenant-isolation cluster fix lost in multi-session conflict + claim api.workshop)
Original Service-portion commit: 77185828 (Service-module slice only)
Reviewer: opus (second-layer adversarial review; recorded as actor=claude per sweep:inventory:review enum mapping)

Verdict: APPROVE-WITH-MINOR-EDITS-APPLIED

Round-1 BLOCKING regression is closed; ServiceTenantIsolationTest is 13/28/0 green and full tests/Feature/Service/ is 66/224/0 green at the current HEAD; the byte-identical re-application claim in commit 67dcbf5a's message is verified for all four touched files; no new blockers introduced; Findings 3 / 4 / 5 from round-1 remain (NICE / NICE / INFO) and are intentionally not blocking.

## Round-1 regression status

**CLOSED.** The round-1 BLOCKING finding (branch tip f08a6ba2 silently reverting the api.service Service-module fix while masquerading as an api.workshop claim+start commit) has been fully remediated by commit 67dcbf5a. Verification chain:

1. Fix-commit identity check: `diff <(git show 77185828:<file>) <(git show 67dcbf5a:<file>)` for every Service file in the 67dcbf5a commit returns empty for all four:
   - apps/api/app/Modules/Service/Application/Services/ServiceCatalogService.php — byte-identical
   - apps/api/app/Modules/Service/Presentation/Controllers/ServiceCategoryController.php — byte-identical
   - apps/api/app/Modules/Service/Presentation/Controllers/ServiceController.php — byte-identical
   - apps/api/tests/Feature/Service/ServiceCatalogServiceTest.php — byte-identical
   The 67dcbf5a commit message claims "byte-identical to the original 77185828 Service portion" — verified true.
2. HEAD identity check: `diff <(git show 67dcbf5a:<file>) <(git show HEAD:<file>)` for the same four files plus ServiceTenantIsolationTest.php returns empty. No subsequent commit on the branch (e8713644 / 148d2703 / 189a56c8 / e4906c34) has drifted these files.
3. Honesty check (pre-fix rollback): with HEAD checked out, `git checkout 516c6f61 -- apps/api/app/Modules/Service apps/api/tests/Feature/Service` then `vendor/bin/phpunit tests/Feature/Service/ServiceTenantIsolationTest.php` reproduces **7 failures / 13 tests / 25 assertions** with the exact same failure shapes the round-1 review reported:
   - 3 service-tier `Failed asserting that exception of type "ModelNotFoundException" is thrown` (api.service.001/002/003).
   - 1 drift `Failed asserting that exception of type "InvalidArgumentException" is thrown` (assertCompanyMatchesContext absent).
   - 1 service show structural-SQL: `Got SQL: select * from "services" where "company_id" = ? and "id" = ?` — missing `"tenant_id"` predicate.
   - 1 category show structural-SQL: same — missing `"tenant_id"`.
   - 1 update path scoped-lookup count: `Got 0` services lookups carrying both predicates (expected ≥2).
4. Restore + re-run: `git checkout HEAD -- apps/api/app/Modules/Service apps/api/tests/Feature/Service` + `vendor/bin/phpunit tests/Feature/Service/ServiceTenantIsolationTest.php` → **OK (13 tests, 28 assertions)**. Full `tests/Feature/Service/` → **OK (66 tests, 224 assertions)**. Matches brief.

## Summary

Commit 67dcbf5a re-applies, verbatim, the four Service-module file changes that 77185828 originally bundled. The diff is byte-identical (verified by direct `git show <sha>:<path>` per-file comparison; the commit-metadata-only delta in the diff headers is the only difference). The accompanying inventory.yml change in 67dcbf5a flips the api.workshop cluster's 4 callsites to claimed/in_progress (claim+start state), correctly satisfying the "claim commits should be YAML-only" rule that round-1 Finding 2 surfaced — that is, the workshop claim work is now properly partitioned: the YAML transition lives in 67dcbf5a alongside the Service re-application (since both were needed to undo f08a6ba2's damage), not in a misleadingly-named commit that secretly reverted Service code. Commits e8713644 (loyalty round-2 fix), 148d2703 (workshop fix), and 189a56c8 (workshop submit) followed without any further drift to Service files.

The cluster regression suite is now honestly green at HEAD: pre-fix rollback reproduces 7 failures with the exact predicted shapes; HEAD-restore returns to 13/28/0. PHPStan and Pint behavior matches round-1 (clean against both pre-fix and post-fix code, since the regression is runtime-only). POS surface diff dev..HEAD is empty for all runtime paths (only the audit-pos-local-cache static-analysis tooling under apps/web/tools/__fixtures__/audit-pos-local-cache/ + apps/web/tools/audit-pos-local-cache.mjs differs — these are scanner fixtures and the scanner itself, not POS runtime surface).

The primary residual concern from round-1 — that the recorded `fix_commit=77185828` for api.service.001/002/003 in the inventory.yml is now stale because that commit's Service portion was reverted by f08a6ba2 and re-applied by 67dcbf5a — is acknowledged in the 67dcbf5a commit message, which explicitly states "A separate commit will re-pin the api.service.001/002/003 fix_commit fields once review settles." This is appropriate: the reviewed fix is what's at HEAD (which is byte-identical to 77185828's Service portion), and the fix_commit re-pin can be done as part of the review-settlement transition rather than blocking the verdict.

## Findings

1. **Severity: CLOSED** — Round-1 Finding 1 (BLOCKING regression). The api.service Service-module fix is now restored at HEAD via 67dcbf5a (byte-identical to 77185828's Service portion). ServiceTenantIsolationTest.php is 13/28/0 green; full tests/Feature/Service/ is 66/224/0 green; honesty rollback reproduces the 7-failure pre-fix baseline. Closed.

2. **Severity: INFORMATIONAL (process gap, unchanged from round-1)** — The verify-history gate still does not cross-check that the code state at the latest event's commit matches the recorded `to_status`. A claim/submit/approve event recorded against a commit whose code state has since been reverted remains invisible to verify-history. The fact that f08a6ba2's silent revert happened at all is the demonstration. No code change requested in this round; tracked as an open process limitation. The forward fix here was a manual catch by Claude during a parallel-session conflict, not a CI catch.
   - Suggested fix (orchestrator-level, deferable): add a structural CI gate that fails when a `:claim` or `:start` action's commit modifies files outside `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`; or add a "code-state vs latest-event-commit" diff check to verify-history.

3. **Severity: NICE-TO-HAVE (unchanged from round-1)** — `EloquentServiceRepository.php:14` still has `Service::query()->find($id)` (tenant-blind). Re-confirmed no production callers via `grep -rn "ServiceRepositoryInterface" app/Modules/Service/ app/` — only the interface declaration, the binding in `ServiceModuleServiceProvider`, and the Eloquent implementation itself. The `findById` matches outside the Service module belong to `LoyaltyProgramRepositoryInterface`, a different interface. The `findByIdForTenant($id, $tenantId)` method on the same class is also still single-tier (tenant_id only, no company_id). Not blocking; defensive hardening only.
   - File: `apps/api/app/Modules/Service/Infrastructure/Persistence/EloquentServiceRepository.php:12-22`
   - Suggested fix (deferable): add `->where('company_id', $companyId)` predicate, rename `findByIdForTenant` to `findByIdForTenantAndCompany($id, $tenantId, $companyId)`. Or annotate the file `@deprecated dead-code; add tenant+company predicates before wiring`.

4. **Severity: NICE-TO-HAVE (unchanged from round-1)** — Validator-tier `category_id` / `parent_id` exists rules still lack `ScopedExists::tenantAndCompany`:
   - `apps/api/app/Modules/Service/Presentation/Requests/CreateServiceRequest.php:38` — `'exists:service_categories,id'`
   - `apps/api/app/Modules/Service/Presentation/Requests/UpdateServiceRequest.php:40` — `'exists:service_categories,id'`
   - `apps/api/app/Modules/Service/Presentation/Requests/CreateServiceCategoryRequest.php:34` — `'exists:service_categories,id'` (parent_id)
   - `apps/api/app/Modules/Service/Presentation/Requests/UpdateServiceCategoryRequest.php:36` — `'exists:service_categories,id'` (parent_id)
   Not exploitable on the inventoried callsites (api.service.001/002/003 are all on the Service entity, not Category) but allows tenant-A users to persist a service row with a tenant-B `category_id`. Worth tracking as a follow-up validator-tier sweep.

5. **Severity: INFORMATIONAL (out-of-scope, unchanged from round-1)** — Cross-module Service::find usages remain in:
   - `app/Modules/Workshop/Bundle/Infrastructure/Resolvers/EloquentServiceResolver.php` — `Service::query()->find($serviceId)` (Workshop cluster — note: api.workshop cluster is now under_review per 189a56c8; this resolver may or may not be in its scope, and that is the workshop cluster's review's concern, not this one).
   - `app/Modules/Document/Domain/Services/DraftPersistenceService.php`, plus several Document controllers (QuoteController, InvoiceController, DeliveryNoteController, SalesOrderController, PurchaseOrderController) — all `Service::find` / `Service::whereIn` patterns belonging to api.document.
   Tracked only; out-of-scope for api.service.

## Audit exhaustiveness

- **Round-1 finding re-verification**: All 5 round-1 findings re-checked against current HEAD (e4906c34 after concurrent push; 189a56c8 at start of round-2). Finding 1 closed; Findings 2–5 unchanged. No new findings surfaced.
- **Byte-identical re-application verification**: Per-file `diff <(git show 77185828:<path>) <(git show 67dcbf5a:<path>)` for all four touched Service-module files returned empty. The commit-message claim of "byte-identical to the original 77185828 Service portion" is confirmed true. HEAD-vs-67dcbf5a per-file diffs also empty for all five Service files (4 modified + ServiceTenantIsolationTest.php) — no drift in subsequent commits.
- **Honesty rollback re-confirmation**: Stash → `git checkout 516c6f61 -- apps/api/app/Modules/Service apps/api/tests/Feature/Service` → run regression test → reproduced exactly 7 failures with the round-1-predicted shapes. Restore HEAD → re-run → 13/28/0. Stash pop. Working tree restored without conflict (despite concurrent session push of e4906c34 mid-review). The pre-fix-rollback note: `ServiceTenantIsolationTest.php` does not exist at 516c6f61; checking out 516c6f61 for the tests/Feature/Service path is a no-op for that file (git correctly leaves the existing HEAD copy alone), so the rollback test runs the new isolation test against the old service code — which is exactly the intended honesty check.
- **Hostile grep re-run on current HEAD**:
  - `grep -rn "Service::find(" app/Modules/Service/` — 0 matches.
  - `grep -rn "ServiceCategory::find(" app/Modules/Service/` — 0 matches.
  - `grep -rn "Service \$service" app/Modules/Service/Presentation/` — 0 matches (no implicit-binding signatures).
  - `grep -rn "ServiceCategory \$category" app/Modules/Service/Presentation/` — 0 matches.
  - `grep -rn "::find(" app/Modules/Service/` — 0 matches.
  - `grep -rn "::findOrFail(" app/Modules/Service/` — 0 matches.
  - `grep -rn "Service::" app/Modules/Service/` — 13 matches, all `Service::query()` chains carrying tenant+company predicates or `Service::create([...])` (writes that fold tenant/company into the payload), plus the dead-code `EloquentServiceRepository::findById` (Finding 3) and one Domain hasMany relation declaration.
  - `grep -rn "ServiceCategory::" app/Modules/Service/` — 14 matches, same pattern: all `ServiceCategory::query()` chains scoped, plus `ServiceCategory::create([...])`, plus Domain belongsTo/hasMany relation declarations.
- **Test verification**:
  - `vendor/bin/phpunit tests/Feature/Service/ServiceTenantIsolationTest.php` at HEAD → **OK (13 tests, 28 assertions)** in 13.6s.
  - `vendor/bin/phpunit tests/Feature/Service/` at HEAD → **OK (66 tests, 224 assertions)** in 27.3s.
- **POS surface diff dev..HEAD**: empty for all runtime paths. The only POS-string matches in `git diff --name-only dev..HEAD` are auditor tooling: `apps/web/tools/__fixtures__/audit-pos-local-cache/edge/*.ts`, `apps/web/tools/__fixtures__/audit-pos-local-cache/negative/*.ts`, `apps/web/tools/__fixtures__/audit-pos-local-cache/positive/*.ts`, `apps/web/tools/__tests__/audit-pos-local-cache.test.mjs`, `apps/web/tools/audit-pos-local-cache.mjs`. These are static-analysis scanner fixtures and the scanner script itself — not POS runtime surface (no apps/web/src/features/pos*, no apps/web/src/pages/POS*, no apps/web/src/store/pos*, no apps/api/app/Modules/POS*).
- **Concurrent-session activity in working tree**: Branch tip advanced from 189a56c8 → e4906c34 mid-review (a concurrent session pushed `chore(tenant-isolation): claim + start api.accounting cluster (7 callsites)`). The post-pull HEAD at the start of round-2 was 189a56c8; by the time `git stash` ran the working tree had picked up e4906c34. This did NOT affect Service files (verified by re-running the 67dcbf5a-vs-HEAD per-file diff after the push — all empty). No code-state collision, no stash-pop conflict. The working tree's many untracked `docs/superpowers/{audits,plans,reviews,specs}/*.md` files (listed in `git status`) are unrelated session artifacts from other parallel sessions; they did not interact with this review and were preserved through the stash/pop cycle. Per brief, I did not touch them.
- **Inventory state for api.service**: api.service.001 / 002 / 003 all status=under_review, owner=codex, fix_commit=77185828 (stale per 67dcbf5a's commit message — re-pin deferred to a separate commit per intentional sequencing). Sweep:inventory:verify-history was not re-run in this round (out-of-scope for the per-cluster review; the round-1 review already confirmed it returns 0 problems and that it does not catch this class of regression).

## Confidence

High confidence on Finding 1 closure. Reproducible deterministically: `diff <(git show 77185828:apps/api/app/Modules/Service/...) <(git show 67dcbf5a:...)` empty for all four files; `vendor/bin/phpunit tests/Feature/Service/ServiceTenantIsolationTest.php` at HEAD returns 13/28/0; pre-fix rollback returns the same 7 failures the round-1 review documented. The byte-identical re-application claim in 67dcbf5a's commit message is materially true.

High confidence that no new blockers were introduced. The four touched files match 77185828 exactly; commits between 67dcbf5a and HEAD (e8713644 / 148d2703 / 189a56c8 / e4906c34) do not modify any Service-module file (HEAD-vs-67dcbf5a per-file diff empty).

Medium confidence on the inventory `fix_commit` re-pin discipline. The stale `fix_commit=77185828` value is acknowledged in the 67dcbf5a commit message but not yet corrected in YAML. If the orchestrator's review-settlement step does not re-pin to a current commit (either 67dcbf5a or a later sweep:inventory:review verdict commit), the YAML will continue to point at a commit whose Service portion no longer represents the canonical fix history of the branch (it represents a snapshot from before the f08a6ba2 revert). This is a documentation-fidelity issue, not a regression risk — but it is the kind of audit-trail soft spot that contributed to the round-1 problem in the first place. The verdict-recording step in this round (sweep:inventory:review with --review-commit pinned to HEAD) partially addresses this by pinning the verdict to a current SHA.

Medium confidence on the residual NICE findings (Findings 3, 4) being safely deferable. They were NICE in round-1 and remain NICE — neither is exploitable on the inventoried callsites — but Finding 4 (validator-tier scoped exists) is a recurring pattern across multiple clusters (api.cart called out the same shape) and may warrant a dedicated cross-cutting hardening pass rather than per-cluster deferral.

What I could have missed:
- I did not re-run the full `tests/Feature/` suite — only `tests/Feature/Service/`. A regression introduced by other parallel sessions (the api.accounting claim push, the api.workshop fix in 148d2703, the api.loyalty round-2 fix in e8713644) could be hiding outside the Service module. Per protocol, those are their respective clusters' gates, not this one.
- I did not verify that the Workshop fix in 148d2703 leaves Workshop's `EloquentServiceResolver` (Finding 5 reference) tenant-scoped — that's the workshop cluster's review's concern, not this one.
- I did not exhaustively re-grep for late-bound DI usage of `EloquentServiceRepository` outside `app/Modules/Service/`. The Service `ServiceRepositoryInterface` binding is only declared in the Service module's own provider, but a late-bound usage via `App::make` or `app()->bind` override elsewhere would be invisible to my grep. Round-1 had the same caveat; nothing has changed.

## Recommendation

APPROVE-WITH-MINOR-EDITS-APPLIED for api.service.001/002/003.

Recording verdict via `php artisan sweep:inventory:review --actor=claude --verdict=APPROVE-WITH-MINOR-EDITS-APPLIED --review-file=docs/superpowers/reviews/2026-05-04-api-service-cluster-opus-round2-review.md --review-commit=<HEAD-at-verdict-time>` per cluster cadence. Actor=claude per the sweep:inventory:review enum mapping (opus → claude).

Suggested follow-ups (non-blocking):
- Re-pin `fix_commit` for api.service.001/002/003 from `77185828` to a current SHA (67dcbf5a or the verdict-recording commit) so the audit trail reflects the actual canonical fix on the branch.
- Defer Findings 3 (EloquentServiceRepository dead-code hardening) and 4 (validator-tier ScopedExists for category_id/parent_id) to either a dedicated follow-up cluster or a cross-cutting validator-tier sweep — the latter is preferable since the same shape recurs across api.cart and likely other clusters.
- Treat Finding 2 (process gap on verify-history not catching code-state-vs-event-commit drift) as an orchestrator-level CI improvement; the manual catch in 67dcbf5a is not a sustainable mitigation.
