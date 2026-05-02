# Verdict (one line)

APPROVE-WITH-MINOR-EDITS-APPLIED

# Top 3 remaining risks

1. The plan is operationally sound only if the inventory commands enforce the state machine exactly. Today the text still says workers "resolve" callsites before adversarial review (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:369-371`, `:387-389`), while the schema says `fixed` means review approved (`:193-201`, `:237-241`). If execution starts with that ambiguity, YAML can mark unsafe or unreviewed code fixed, and later clusters will unblock against paperwork rather than code plus review.

2. POS is declared blocked, but not fully blocked by the schema. The catalogue assigns `tauri.sqlite-cache` and `tauri.sync-envelope` to the POS orchestrator and blocks them on the POS branch (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:331-351`), and the checkpoint says one orchestrator owns all `apps/pos` plus `apps/api/app/Modules/POS` work with no parallel POS sessions (`docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md:1-10`). But Section 4 still gives Codex `can_claim: "tauri.*"` (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:167-187`), and the certification SOT keeps POS-001 as P0/proposed with no `blocked_by` (`docs/superpowers/plans/2026-05-02-tenant-isolation-certification-sot.yaml:163-183`). That creates an avoidable branch conflict with fiscal-chain work.

3. Strategic compliance timing is not current enough for a final execution plan. The master still treats 2026-08-31 / 2026-09-01 as NF525 self-cert/accredited hard deadlines (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:38-49`, `:543`), but the official economie.gouv page published 2026-02-24 says the 2026 finance law restored editor self-attestation and cancelled the accredited-certificate-only switch. E-invoicing remains real: impots.gouv says all companies must receive from 2026-09-01, large/ETI issue from then, SME/micro issue from 2027. If the legal basis stays stale, the team may over-prioritize accredited NF525 paperwork while under-scheduling PDP onboarding and receipt readiness.

# Question-by-question findings (R1-R9, N1-N7, P1-P3, O1-O2)

## R1. Coverage completeness

Finding: verified-fixed, with two additions required.

The required presentation grep returned 117 matches by module: Accounting 3, BatchExpiry 5, Catalog 3, Compliance 1, Contact 2, Document 8, Inventory 19, Loyalty 3, POS 25, Pricing 8, Taxation 4, Treasury 36. The required `find`/`findOrFail` grep returned 89 matches by module: Accounting 5, BatchExpiry 1, Cart 2, Company 1, Compliance 2, Document 30, Identity 3, Inventory 5, POS 19, Pricing 2, Service 2, Taxation 3, Treasury 13, Workshop 1. These map to Section 6 clusters (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:306-350`) with no orphan among the two required greps.

Two catalogue edits are still needed: line 306 says "API surface (16 clusters)" but the table has 20 API rows (`:306-329`), and console commands are wider than `api.scheduled-jobs`. The repo has many non-scheduled application/maintenance commands (`find apps/api/app -path "*Commands*"`) and routes/console schedules only a subset (`apps/api/routes/console.php:15-50`). Add `api.console-commands` or expand `api.scheduled-jobs` to include all Artisan commands, not only scheduled ones.

## R2. YAML schema corrections

Finding: still-open but bounded.

The inline schema fixes most Y1-Y4 issues: canonical ids are shown (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:203-216`), eight statuses are documented (`:193-201`), line numbers are display-only (`:218-229`), stable identity is an AST hash (`:262-270`), and optimistic concurrency is documented (`:271-273`). Gaps: the example file is not updated despite Section 4 claiming it is (`:142` vs `docs/superpowers/plans/tenant-isolation-sweep-inventory.example.yml:25-58`); direct-hand-edit rejection is stated but not implementable as written (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:275`); rename/move/orphan behavior is underspecified (`:269` only handles path+symbol+resource).

## R3. Tauri paths

Finding: still-open trivial edit.

The files exist at the corrected paths: `apps/pos/src/lib/db.ts`, `apps/pos/src/lib/db/migrations.ts`, and `apps/pos/src/lib/sync/syncService.ts`. The plan still references `apps/desktop/...` once in its change table (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:63`). That reference is historical text, but the question explicitly required no residual `apps/desktop`; remove it.

## R4. POS orchestrator boundary

Finding: partially fixed.

The plan no longer asks Codex to sweep POS in parallel; POS and Tauri clusters are explicitly blocked on the POS orchestrator (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:331-351`, `:473-481`). This respects the checkpoint's ownership rule (`docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md:1-10`). Enforcement is incomplete because Codex can still claim `tauri.*` in the schema (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:167-187`). Remove `tauri.*` from `agents.codex.can_claim` until the orchestrator explicitly hands it off.

## R5. Code-as-source-of-truth

Finding: verified-fixed, with drift reporting needed.

The principle is correct: architecture gates inspect code and YAML is only failure metadata (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:124-136`). Running a gate before accepting `sweep:inventory:resolve` preserves code-as-truth rather than creating a YAML trust cycle. Failure modes: unsafe code plus YAML fixed still fails; fixed code plus YAML pending should pass but produce an inventory drift warning; new fingerprints after partial edits should create pending/needs_recheck rows. Add an explicit `sweep:inventory:status --drift` requirement before final verification.

## R6. Cross-tenant route attribute

Finding: partially fixed.

Route attributes are implementable: Section 9 proposes route-map enumeration plus Reflection on controller methods (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:399-411`). Non-route files are not robust enough: Gate B says a local `// @cross-tenant-by-design <reason>` annotation skips AST findings (`:130`), but there is no parser rule, required reason format, or ticket/audit id. Add a strict annotation grammar and require it on the nearest class or method node only.

## R7. Module-gating narrowing

Finding: verified-fixed.

`updateExtras` is super-admin-only: the route is inside `auth:sanctum-admin`, `super_admin`, and admin throttle middleware (`apps/api/routes/api.php:53-67`), and `EnsureSuperAdmin` rejects non-`SuperAdmin` users (`apps/api/app/Http/Middleware/EnsureSuperAdmin.php:41-49`). `CompanyConfigService::getConfigForTenant()` caches as `tenant_config:{$tenant->id}` (`apps/api/app/Services/CompanyConfigService.php:34-38`). The real bug is the raw header pattern: progression controllers read `X-Company-Id` directly (`apps/api/app/Modules/Progression/Presentation/Controllers/ModuleReadinessController.php:18-39`, `CompanyProgressionController.php:18-49`, `RecommendationController.php:18-57`), and `GrowthAdvisorHttpClient` puts that value into outbound URLs (`apps/api/app/Modules/Progression/Infrastructure/Http/GrowthAdvisorHttpClient.php:31-68`). Section 10 now targets this (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:415-435`).

## R8. Cross-agent review

Finding: still-open.

The plan requires cross-agent review by convention (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:375-392`) but does not make `sweep:inventory:resolve` require an approving review file before transition to `fixed`. Worse, the workflow resolves before review (`:369-371`, `:387-389`). This must become: resolve to `under_review`, then a separate `sweep:inventory:review --verdict APPROVE...` transitions to `fixed`.

## R9. Treasury hard gate

Finding: partially fixed.

The gate has the right ingredients: review file exists, verdict line is approved, YAML status is fixed, and `sweep:inventory:claim` enforces it (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:353-360`). The gap is again state semantics: if `APPROVE-WITH-MINOR-EDITS-APPLIED` is accepted, the command must verify the post-edit commit/hash and the final gate status, not just parse a text verdict. The review file path and verdict are not enough without a `fix_commit`/`review_commit` linkage.

## N1. Tactical/strategic split coherence

Finding: verified-fixed on elapsed time, new legal caveat.

The math fits the calendar. Tactical 1.5-2 weeks (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:21-34`) plus Sections 16-19 totals about 4.5-6.5 more weeks (`:499-543`), leaving buffer before 2026-08-31. But the NF525 accredited-certificate deadline assumption is outdated per economie.gouv; keep the work, change the reason from "hard statutory deadline" to "go-to-market/commercial assurance".

## N2. Strategic phase ownership ambiguity

Finding: new-gap.

Section 16-21 ownership is "human-driven decisions, Claude/Codex execute" (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:493-497`, `:574`). That is too vague for self-attestation vs accredited certification, PDP partner selection, and tenancy topology. Add a decision table with DRI, due date, inputs, and blocking consequence.

## N3. DB-per-tenant migration trigger

Finding: new-gap.

"Cut over before signing the first French/regulated B2B customer" is not enforceable by engineering (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:568`). Add a sales/legal gate: regulated-country opportunities cannot move to signed/activated unless Section 21 is migration-ready or the human DRI signs a dated exception.

## N4. Two YAMLs maintenance burden

Finding: partially fixed.

Keeping tactical inventory and certification SOT separate is fine because they serve different cadences. The sync risk is real: POS-001 says P0/proposed and blocks certification (`docs/superpowers/plans/2026-05-02-tenant-isolation-certification-sot.yaml:163-183`), while master Section 6 blocks POS/Tauri on the orchestrator (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:331-351`). Add `linked_cluster`, `blocked_by_cluster`, and a SOT consistency check.

## N5. Inventory generation correctness

Finding: partially fixed.

The five scanners are implementable (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:277-299`), but test cases are missing. `php_presentation_exists` must catch inline strings and `Rule::exists(...)->where(...)`; `php_ast_find` must distinguish direct `Model::find()` from scoped `$query->find()` chains; `ts_query_key` must default-deny unknown key factories; `pos_sqlite_cache` needs SQL-string parsing for DDL in TS files; `manual` rows need explicit stable keys such as `manual:<cluster>:<slug>` so regeneration does not delete them.

## N6. Stable callsite identity edge cases

Finding: partially fixed.

The hash input is good for line shifts (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:262-270`). It is not enough for renames/moves. A method rename changes `symbol_fqn`; a namespace/path move changes both path and symbol; these should create a new pending row and mark the old row `needs_recheck` or `stale_orphan`, not silently disappear. Document this exact behavior.

## N7. CI enforcement of YAML hand-edits

Finding: still-open.

Line 275 says CI rejects direct hand edits, but not how (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:275`). Use a command-generated history event with `command`, `actor`, `previous_yaml_sha256`, `new_yaml_sha256`, `target_ids`, and `git_commit` fields, then make CI reject status/owner/review mutations without a matching event.

## P1. NF525 evidence pack scope

Finding: still-open.

Section 19 covers many right artifacts: schema, triggers, verification, golden fixtures, operations, change control (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:530-543`). Official economie.gouv requirements are inalterability, securisation, conservation, and archiving, including archive integrity over time and date certainty. The evidence pack needs explicit archive procedure tests, daily/monthly/yearly closing evidence, certificate/self-attestation output, customer-facing compliance attestation, and version/change-control dossier. Source: https://www.economie.gouv.fr/cedef/les-fiches-pratiques/en-quoi-consiste-la-certification-des-logiciels-de-caisse

## P2. E-invoicing French timeline

Finding: still-open.

The plan's 2026-09-01 receive deadline is correct but incomplete (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:545-556`). impots.gouv says all companies must be able to receive from 2026-09-01; large/ETI issue from 2026-09-01; SME/micro issue from 2027; companies must choose an approved platform for receipt. PDP partner selection cannot wait until a 3-4 week implementation window near the deadline. Sources: https://www.impots.gouv.fr/professionnel/je-decouvre-la-facturation-electronique and https://www.impots.gouv.fr/facturation-electronique-et-plateformes-partenaires

## P3. DB-per-tenant migration risk to running customers

Finding: new-gap.

Section 21 names a 6-8 week migration including soak (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:558-574`), but not a cutover strategy. The mechanics doc explains per-tenant Stancl operation and cross-tenant command patterns (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:51-61`, `:246-260`) but does not define downtime. Add rolling per-tenant cutover, rehearsal, rollback, dual-write prohibition, and maintenance-window policy.

## O1. Other surfaces still missing

Finding: new-gap.

The local repo has only `apps/api`, `apps/web`, and `apps/pos` under `apps/`, so there is no in-repo `apps/platform`, `apps/erp-ml`, or `apps/platform-ml` surface to sweep here. However, API console commands are broader than scheduled jobs: the command tree includes compliance, POS, tenant reset, platform enrichment, scheduling, vehicle, workshop, and generated maintenance commands, while Section 6 only says scheduled jobs (`docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md:324-327`). Add `api.console-commands` or redefine `api.scheduled-jobs`.

## O2. Authentication/authorization layer

Finding: new-gap.

The plan audits scoped resource lookups but not auth binding. Sanctum login/register issue `createToken($tokenName, ['*'])` without tenant-bound abilities or tenant-status checks (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:90-98`, `:230-238`). Spatie teams are enabled with `tenant_id` as team key (`apps/api/config/permission.php:95-100`) and middleware sets `setPermissionsTeamId($user->tenant_id)` (`apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22-31`), but the sweep lacks a route/middleware architecture test for every protected route. Add `api.auth-permissions` to verify token lifecycle, tenant suspension/deletion behavior, and universal `SetPermissionsTeam` coverage.

# Diff to the master plan (if APPROVE-WITH-MINOR-EDITS-APPLIED)

Required edits before execution starts:

1. Line 81: replace `457af457` with current reviewed tip `68c4cf23`, or make the line say "tip equals the commit named in this review".
2. Line 63: remove the residual `apps/desktop/...` text or rewrite it as "prior desktop paths corrected".
3. Line 142: either update `tenant-isolation-sweep-inventory.example.yml` to schema v2 before execution, or change the line to say the inline schema is authoritative until the example is regenerated.
4. Lines 167-187: remove `tauri.*` from Codex `can_claim` until the POS orchestrator unblocks it. Add `api.console-commands` and `api.auth-permissions` to the cluster catalogue and claim rules.
5. Lines 193-201 and workflows at 369-371 / 387-389: change workflow to `resolve -> under_review`; only `sweep:inventory:review --approve` can set `fixed`.
6. Line 275: replace the hand-edit rule with the command-event hash schema described in this review.
7. Lines 306-329: fix "API surface (16 clusters)" and include the new auth/console clusters.
8. Lines 353-360: Treasury hard gate must parse review file verdict and verify review/fix commit linkage, not only YAML status.
9. Lines 493-574: add a strategic decision table: NF525 path, PDP partner, tenancy topology, sales gate, each with DRI and date.
10. Lines 530-543: update NF525 wording to reflect 2026 self-attestation restoration; keep accredited certification as commercial/assurance track.
11. Lines 545-556: split e-invoicing into receive-by-2026-09-01, issue-by-company-size, PDP selection, and integration testing gates.
12. Lines 558-574: add rolling per-tenant cutover/runbook and sales/legal regulated-customer gate.
13. Certification SOT POS-001: add `blocked_by_cluster: tauri.sqlite-cache` / `api.pos-stabilization` and `blocked_reason: POS orchestrator branch`.

# Diff to the inventory YAML schema (if needed)

Add these schema fields:

- `clusters[].blocked_by_external`: string enum for non-YAML blockers such as `pos_orchestrator_branch`.
- `clusters[].review_gate`: `{ required: true, reviewer_must_differ_from_owner: true, review_file: ..., accepted_verdicts: [...] }`.
- `callsites[].review.verdict`: allow only `APPROVE`, `APPROVE-WITH-MINOR-EDITS-APPLIED`, `REQUEST-CHANGES`, `BLOCK`.
- `callsites[].history[]`: add `command`, `previous_yaml_sha256`, `new_yaml_sha256`, `target_ids`, `review_file`, `review_commit`.
- `callsites[].stale_state`: `active | stale_orphan | superseded`.
- `callsites[].stable_key`: allow `sha256:<...>` and `manual:<cluster>:<slug>`.
- Status transition rule: `pending -> claimed -> in_progress -> under_review -> fixed`; `blocked`, `deferred`, and `needs_recheck` can branch from any pre-fixed state with a reason.

# Confidence gradient

| Section | Confidence | Question that would change rating |
|---|---:|---|
| 1 Scoped validation helper | 4/5 | Does the implementation avoid `app()`/static service location and preserve module boundaries? |
| 2 Cross-tenant route attribute | 3/5 | Is the annotation parser for non-routes strict and tested? |
| 3 CI gates | 4/5 | Do gates derive pass/fail only from code and default-deny unknown patterns? |
| 4 YAML schema | 3/5 | Is review-before-fixed enforced by commands and schema? |
| 5 Inventory scanners | 3/5 | Are scanner fixtures added for dynamic rules, query chains, TS factories, SQL strings, and manual rows? |
| 6 Cluster catalogue | 4/5 | Are `api.console-commands` and `api.auth-permissions` added? |
| 7 Treasury gate | 4/5 | Does claim parse approved review plus commit linkage? |
| 8 Codex API clusters | 3/5 | Does resolve go to `under_review`, not `fixed`? |
| 9 Super-admin context | 4/5 | Are all route skips backed by middleware or attributes? |
| 10 Module gating | 5/5 | Would drop only if middleware order changes without tests. |
| 11 Web query keys | 3/5 | Does the TS scanner default-deny new key factories? |
| 12 Form selectors/stores | 3/5 | Are logout/company-switch tests included? |
| 13 Super-admin frontend | 3/5 | Are admin query keys isolated from tenant query cache? |
| 14 POS blocked | 3/5 | Is `can_claim` actually blocked for POS/Tauri? |
| 15 Final verification | 4/5 | Does verification include drift and auth/console gates? |
| 16 DB audit | 4/5 | Does output include FK scope and RLS policy evidence per table? |
| 17 Composite FKs | 4/5 | Are same-tenant/cross-company negative fixtures added? |
| 18 RLS pilot | 3/5 | Is transaction-local context set safely for queues/console? |
| 19 NF525 pack | 3/5 | Is the legal basis updated and archive/date-certainty evidence explicit? |
| 20 E-invoicing | 3/5 | Is PDP selection scheduled immediately and tested before September? |
| 21 DB-per-tenant | 3/5 | Is rolling cutover/rollback defined for existing customers? |

# Recommended execution order

1. Apply the bounded plan/schema edits above.
2. Build Section 3-5 foundation first: helper/attributes, hard gates, schema v2, scanner fixtures, command-enforced state transitions.
3. Run inventory generation and verify no orphan clusters; add `api.auth-permissions` and `api.console-commands`.
4. Execute Treasury as the reference cluster, review it, and only then unblock other API clusters.
5. Sweep non-POS API clusters one at a time with `under_review -> fixed` review transitions.
6. Run web cache/form/store clusters after API gates are hard.
7. Keep POS/Tauri blocked until the POS orchestrator merges or explicitly transfers ownership.
8. Start strategic legal/business decisions in parallel with tactical work: PDP partner, self-attestation vs accredited certification, tenancy topology, regulated-customer sales gate.
9. After tactical gates are hard, execute DB audit, composite FKs, RLS pilot, NF525 evidence pack, e-invoicing, then DB-per-tenant migration.

# Anything still missed

- The master plan still has documentation drift around "schema-based multi-tenancy" in `CLAUDE.md` tech stack (`CLAUDE.md:106-115`) while the implementation is row-level today; this should be fixed outside the sweep.
- Auth token invalidation on tenant suspension/deletion is not proven. The plan should not claim "no leakage across the entire app" until `api.auth-permissions` audits it.
- Public unauthenticated routes such as scheduling storefront and webhooks need a separate signed-context inventory, even if they do not appear in the two required greps.
- I did not run the future scanner implementations because they do not exist yet; findings are based on implementability of the spec and current repo scans.
- Legal sources used for P1/P2: economie.gouv NF525 page (2026-02-24), impots.gouv e-invoicing preparation page, impots.gouv approved platforms page, and impots.gouv "from when am I concerned" page.
