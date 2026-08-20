---
# Round-4 independent orchestrator truthfulness gate — verdict of record
# Gate run 2026-08-12 by the orchestrator (read-only) against codex/openapi-contract-a-to-z @ 6bfb18c5f.
# The implementer explicitly did not self-certify. This document decides PASS/FAIL.
# Method: every handback claim re-derived from the tree; no lane verdict, manifest, or internal
# adversarial document was accepted as evidence.
---

# OpenAPI independent truthfulness gate — round 4

- **Branch:** `codex/openapi-contract-a-to-z`
- **Target SHA:** `6bfb18c5fbb77e67c7f6830442d901657e46bb84` (`6bfb18c5f`) — "Phase 10.1.39: Rebase and regenerate OpenAPI contracts"
- **Rebase base:** `7d85232cc54abd6a6b2135f476205ab434e71a66`
- **Target worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.openapi`
- **Gate date:** 2026-08-12
- **Round:** 4
- **Review mode:** Read-only on all source. Regeneration and test runs left the worktree clean
  (`git status --short` empty before and after every command).

---

## 1. Gate verdict — **FAIL**

The engineering quality of this round is materially higher than round 3, and the mechanical
infrastructure the round-3 verdict demanded genuinely exists: the bound-parity auditor at
`StrictSchemaTruthAuditor.php:136-201` is a real rule invoked over the full documents during
generation, its four live mutation tests really doctor the committed `tenant-full.json` in memory
and assert rejection at exact JSON pointers, and the nullability auditor now fails closed on a
disappearing registered pointer. R2-1, R2-2, R2-4, R2-5, R2-7 remain closed with no regression;
R2-3-residual and R2-6-residual are **CLOSED**; determinism, zero-behavior, route coverage, surface
partition and every headline count in the handback reproduced **exactly** under my own runs. The
rebase onto `7d85232cc` is real and the contract regenerates clean against merged dev.

The gate nevertheless fails, for two independent reasons.

**First, R3-1 is not closed at the cause.** The new auditor rule only compares the *number branch*
against the *string branch* of the same union. When the generator derives **no** bounds at all, the
rule takes an explicit early `continue` (`StrictSchemaTruthAuditor.php:159-161`) and the node is
never audited — the identical fail-open shape that round 3 rejected in the nullability auditor. And
the generator's bound extractor understands only `min:` and `max:` (`FullSurfaceRefiner.php:689`),
so every other Laravel bound rule is silently dropped. This is not hypothetical: four live nodes
(`StoreCouponRequest`, `UpdateCouponRequest`, `StorePromotionRequest`, `UpdatePromotionRequest` →
`discount_value`) carry runtime `gt:0` that the spec loses entirely, and their string branches are
*signed* — `-5.000` and `0.000` are schema-valid and runtime-rejected, which is verbatim the R3-1
witness shape. Worse, the string-branch precision pattern is chosen from an assumed `kind`
(money=3dp / quantity=4dp / percent=2dp) and never compared against the field's actual runtime
`regex:` rule: **30 of the 57 nodes where a runtime regex exists to compare against carry a pattern
that contradicts it**, in both directions.

**Second, a fresh 15-operation regression sample across ten modules — chosen at random, not from any
known-defect list — surfaced three further material falsehoods in a single pass**, including one
schema (`VerifyManagerPinRequest.company_id`) that is *unsatisfiable* because a generation-time
empty CompanyContext was baked into the contract as `enum: [""]`. A sample that small hitting that
much means the document has not converged, and these are response/inline-`$request->validate()`
type-inference defects — a class the round-3 remediation never touched.

One further claim is refuted on the facts: the handback's `OK (120 tests, 7414 assertions)` does not
reproduce here. I get **120 tests, 7401 assertions, 1 error**, deterministically (2/2, including a
fully isolated single-test rerun): `PilotVerificationTest` caps `verify.php` at a 120-second
`Symfony\Component\Process` timeout, and `verify.php` takes **97.07 s** standalone on this machine —
a 23-second margin that PHPUnit's own overhead consumes. That is a CI-flake defect in the lane's own
gate, not a truthfulness defect, but it means the green claim is machine-specific.

**Escalation note for the owner.** The brief (§3-B) and the round-3 verdict both state: if round 4
surfaces *another new unrelated systematic class*, escalate with the thin-contract restart
recommendation before any further fix round. My reading: the R3-1 residual is the **same** family
(numeric request-input truthfulness) recurring for a third consecutive round, so it does **not** by
itself trip that clause. But **D-2 (precision pattern assumed rather than derived from the runtime
regex — 30 nodes) and D-4/D-5 (response and inline-validate type inference) are new classes**, and
D-4/D-5 are unrelated to everything rounds 1–3 addressed. **The escalation clause is therefore
tripped.** This verdict does not itself recommend a restart — that is the owner's ruling — but the
parent must put the thin-contract restart option in front of the owner before authorising fix round 4.

---

## 2. Claim-verification table

| # | Area | Claim | Result | Evidence |
|---|---|---|---|---|
| **1** | **Git claims** | HEAD == `6bfb18c5…`; rebased onto `7d85232cc`; worktree clean; zero runtime code change | **CONFIRMED** | `git log -1 --format='%H'` → `6bfb18c5fbb77e67c7f6830442d901657e46bb84`. `git merge-base --is-ancestor 7d85232cc HEAD` → exit 0. `git status --short` → empty (before and after all runs). `git diff --stat 7d85232cc..HEAD -- apps/api/app` → **no output**. 41 commits ahead, 139 files changed; `git diff --name-only 7d85232cc..HEAD \| grep -vE '(openapi\|OpenApi\|^docs/\|^\.github/workflows/ci\.yml)'` → **empty** (every changed file is OpenAPI tooling/artifacts, OpenAPI tests/fixtures, docs, or the added `backend-openapi-contract` CI job). |
| **2** | **Verification scripts** | verify.php + verify-full.php report 711/711 closure, 1,031 routes, 1,035 operations, zero gaps/orphans | **CONFIRMED** | I read both scripts first: `verify-full.php:129-154` regenerates twice into fresh temp dirs and compares to committed bytes; `:161-182` hardcodes tenant=965/admin=52/external=18 and 1,035 disjoint ops; `:196-203` hardcodes the 711/711/0 manifest triple; `:249-252` hardcodes 1,031 routes / 1,035 ops / 38 exclusions / 0 gaps. None of it can self-pass. **My run, exit 0:** `Full artifacts are deterministic and match the committed bytes.` / `Full schema closure: 711/711; deviations=109 nodes across 89 operations.` / `Full route coverage: 1,031/1,031 client routes, 1,035 operations, zero gaps, zero orphans.` — verbatim identical to the handback. **My `verify.php` run, exit 0:** `Same-path raw route proof: 1057 routes at merge base and HEAD, SHA-256 e5c5b1e6.` / `Route matrix: default=1057 enabled=1069 union=1069 delta=12.` / `Route category partition: covered-routes=71 in-scope=1031 exclusions=38.` `verify.php:17` pins `ROUTE_INVARIANCE_BASE_COMMIT = 7d85232cc…` — correctly re-targeted to the new base, not the stale `ea1a35bca…` constant. **Independent sanity check:** `php artisan route:list --json` under the hermetic env → **1057** (`MARKETPLACE_ENABLED=false`) and **1069** (`=true`), reproducing both counts outside the lane's tooling. |
| **3** | **Determinism** | Dual fresh-process regeneration byte-identical; committed artifacts match regeneration | **CONFIRMED** | `verify-full.php:129-154` performs exactly this and I ran it myself: two `generate-feasibility-baseline.php` runs into `sys_get_temp_dir()` dirs under the fail-closed env, then `$first !== $second` and `$first !== $committed` throws for all five artifacts. Both comparisons passed. `git status --short` clean afterwards — the committed bytes are the regeneration output. |
| **4** | **R3-1 (67 schemas)** | Mechanical bound-parity auditor + live exact-pointer mutation tests close the class | **REFUTED (partial closure)** | **Auditor exists and is wired:** `StrictSchemaTruthAuditor.php:136-201`, invoked over the full spec at `generate-feasibility-baseline.php:210`. **Mutation tests are genuine** (I read them, not just their names): `StrictSchemaTruthAuditorTest.php:263-320` loads the real committed `openapi/feasibility/tenant-full.json`, asserts it passes, then mutates it in memory (`remove-string-bound-evidence`, `allow-negative-string`, `drop-number-minimum`) at four exact pointers and asserts rejection. All pass in my run. **But the rule fails open:** `StrictSchemaTruthAuditor.php:159-161` — `if ($bounds === [] && ! is_array($deviation)) { continue; }` — a node whose number branch carries no bounds is never audited. **And the generator drops most bound rules:** `FullSurfaceRefiner.php:689` matches only `/^(min\|max):(-?\d+(?:\.\d+)?)$/`. Mechanical scan of all 88 number\|string unions in `tenant-full.json`: 72 carry the deviation, **16 have neither bounds nor deviation** and are silently skipped. **Live defects in that unaudited set** (see §3 D-1). Separately, spot-verification of the 57 nodes whose source has a comparable runtime `regex:` found **30 mismatches** (§3 D-2) — the auditor never compares the spec pattern to the runtime pattern at all. Spot-verified ≥10 schemas directly at their FormRequest sources: `StoreCouponRequest.php:47`, `UpdateCouponRequest.php:48`, `StorePromotionRequest.php:52`, `UpdatePromotionRequest.php:52`, `AdjustPointsRequest.php:22`, `AddLineRequest.php:35`, `UpdateLineRequest.php:28`, `CreateRewardRequest.php:32`, `EnrollMemberRequest.php:40`, `CreateWithholdingRuleRequest.php:32`, `StoreModifierRequest.php:36`, `StoreVariantRequest.php:27`, `ConfirmBankStatementRequest.php:24-25`. |
| **5a** | **R2-3 residual** | Doc-ingestion fields registered; auditor fails closed on a disappearing pointer | **CONFIRMED** | `SerializedFieldNullabilityAuditor.php:63-66` now **throws** (`Registered serialized field schema is missing at …`) where round 3 proved a `continue`. `:82-90` expands `extraction`/`confidence_summary`/`suggestions`/`error` across the five document-ingestion response bases (20 pointers). All R2-3-named fields present in `FIELDS` (`:30-52`), incl. `Channel.metadata` `:30`, `OrderLineResource.modifiers` `:43`, `LocationResource.legal_identifiers` `:37`, `OpeningBalanceImportRow.mapped_data` `:42`, `TaxConfigurationResource.applicable_document_types` `:46` (correctly flagged `normalized => false`), `VatPeriodResource.special_items`/`declaration_data` `:47-48`, admin `Plan` ×3 `:50-52`. Red tests exist at `SerializedFieldNullabilityAuditorTest.php:57-89` and pass. |
| **5b** | **R2-6 residual** | `min:0.01` restored on both branches; non-negative regex | **CONFIRMED** (one cosmetic residual) | `tenant-full.json:55762-55809` (`total_required`) and `:55811-55844` (`splits[].amount`): number branch `"minimum": 0.01`, string branch `"pattern": "^\\d+(\\.\\d{1,3})?$"` (unsigned) with a matching `x-autoerp-runtime-bounds-deviation.bounds.minimum = 0.01`. Matches `MultiPaymentController.php:561-583`. **Cosmetic residual:** the same node's `x-precision-contract-deviation.contract_target.pattern` still records the *signed* `^-?\\d+(\\.\\d{1,3})?$` (`tenant-full.json:55793-55796`), contradicting the branch it purports to describe. Not gate-blocking on its own. |
| **6** | **Fresh regression sample** | Round 4 must not pass on fixed-known-defects alone | **REFUTED — 3 new material falsehoods** | 15 operations drawn at random (seed 20260812) across Inventory/Batch, Import, Vehicle, Treasury, Loyalty, POS, PurchaseHub, Workshop, SupportAccess, Catalog. Six deep-checked against controllers. Clean: `DELETE /v1/loyalty/earning-rules/{id}` 200 + const message (`EarningRuleController.php:135-137`), `GET /v1/products/{product}/images` field-for-field incl. nullable `url`/`alt`/`caption` (`ProductMediaController.php:352-362`), `POST /v1/purchase-hub/orders` 502/201/`notes` nullable (`PurchaseHubOrderController.php:23-40`). Defective: **D-3, D-4, D-5** in §3. |
| **7** | **PHPUnit path-only** | `OK (120 tests, 7414 assertions)` | **REFUTED as stated** | `./vendor/bin/phpunit tests/Unit/OpenApi tests/Feature/OpenApi` → `Tests: 120, Assertions: 7401, Errors: 1`. Failure: `PilotVerificationTest::it_verifies_fresh_process_determinism_route_invariance_and_the_feature_flag_union` — `ProcessTimedOutException: … verify.php exceeded the timeout of 120 seconds` at `PilotVerificationTest.php:30`. **Reproduced in full isolation** (single test path, nothing else running): same error, so it is not contention from my parallel runs. Root cause quantified: `/usr/bin/time -p php tools/openapi/verify.php` → `real 97.07` standalone, against a hard 120 s cap — a 23 s margin that PHPUnit's own overhead exceeds. The full suite was never run (house rule respected). The other 119 tests pass, including every auditor and mutation test cited above. |
| **8** | **F-12** | Two PHPStan errors are inherited, registered, unmasked | **CONFIRMED** | `git diff --name-only 7d85232cc..HEAD -- apps/api/phpstan.neon apps/api/phpstan-baseline.neon apps/api/composer.json apps/api/composer.lock` → **empty** (no config, baseline, or dependency change could mask them). `git show 7d85232cc:apps/api/app/…/CopiesDocumentData.php` lines 309-310 contain `bccomp((string) $line->tax_rate, '0', 4)` and `bcdiv((string) $line->tax_rate, '100', 4)` — the hardcoded literal scales, **present at the base commit**. Combined with the empty `apps/api/app` diff (area 1), the two errors are necessarily inherited and no runtime code was touched. Registered at `docs/superpowers/audits/2026-08-07-openapi-lane-codebase-findings.md:107` (F-12 section) with append-log row at `:130`. |

---

## 3. Defects found

### D-1 — R3-1 residual: runtime bounds still lost, auditor fails open — **High / SYSTEMATIC**

`StrictSchemaTruthAuditor.php:159-161` skips any number|string union whose number branch carries no
bounds and whose string branch carries no deviation. `FullSurfaceRefiner.php:689` only parses
`min:`/`max:`, so `gt:`, `gte:`, `lt:`, `lte:`, `between:` and `not_in:` are dropped before the
auditor ever sees them. 16 of the 88 unions land in that unaudited set. Live witnesses:

| Spec node | Runtime rule | Spec-valid but runtime-rejected |
|---|---|---|
| `tenant-full.json:129772` `StoreCouponRequest.discount_value` | `StoreCouponRequest.php:47` `['required','numeric','gt:0','regex:/^\d+(\.\d{1,4})?$/']` | `0`, `-5`, `"-5.000"`, `"0.000"` |
| `UpdateCouponRequest.discount_value` | `UpdateCouponRequest.php:48` same `gt:0` | idem |
| `StorePromotionRequest.discount_value` | `StorePromotionRequest.php:52` same `gt:0` | idem |
| `UpdatePromotionRequest.discount_value` | `UpdatePromotionRequest.php:52` same `gt:0` | idem |
| `tenant-full.json:115517` `AdjustPointsRequest.points` | `AdjustPointsRequest.php:22` `['required','numeric','not_in:0','regex:/^-?\d+(\.\d{1,2})?$/']` | `0`, `"1.2345"` |

All five emit `"runtime_bounds": []` inside `x-precision-contract-deviation` — the document
positively asserts there are no runtime bounds when there are.

**Required closure:** the parity rule must not have a no-bounds escape hatch; extend the extractor to
the full Laravel comparison-rule vocabulary, and make an unparseable numeric rule a hard failure
rather than a silent `[]`.

### D-2 — String-branch precision pattern is assumed, never derived from the runtime regex — **High / SYSTEMATIC / NEW CLASS**

The string branch pattern comes from `precisionPattern($kind)` (money=3dp, quantity=4dp, percent=2dp)
with no reference to the field's actual `regex:` rule. Of the 57 union nodes whose source carries a
comparable runtime regex, **30 contradict it**. Over-wide examples (spec admits what runtime rejects
— truthfulness compromise):

- `tenant-full.json:115315` `AddLineRequest.labor_hours_estimated` spec `^\d+(\.\d{1,4})?$` vs runtime `AddLineRequest.php:35` `^\d+(\.\d{1,2})?$` → `"1.2345"` spec-valid, runtime-rejected.
- `tenant-full.json:120235` `CreateRewardRequest.points_cost` spec 4dp vs `CreateRewardRequest.php:32` 2dp.
- `UpdateLineRequest.labor_hours_actual` (`UpdateLineRequest.php:28`), `EnrollMemberRequest.welcome_bonus` (`EnrollMemberRequest.php:40`), `StoreBundleRequest`/`UpdateBundleRequest.estimated_labor_hours`, `UpdateRewardRequest.points_cost` — all spec 4dp vs runtime 2dp.
- `CreateEarningRuleRequest`/`UpdateEarningRuleRequest.max_earn_per_transaction`/`max_earn_per_day` — spec 3dp vs runtime 2dp.

Under-wide examples (spec rejects what runtime accepts):

- `tenant-full.json:121718` `CreateWithholdingRuleRequest.rate` spec `^\d+(\.\d{1,2})?$` vs runtime `CreateWithholdingRuleRequest.php:32` `^\d+(\.\d{1,4})?$` — and this node *passes* the new parity auditor (it has `min:0`/`max:1`), proving the auditor is orthogonal to the defect.
- `StoreModifierRequest.price_adjustment` / `StoreVariantRequest.price_adjustment` / `StoreCompositeItemRequest.manual_cost` / `CreateEarningRuleRequest.reward_value` — spec 3dp vs runtime 4dp.

**Required closure:** derive the string pattern from the field's runtime `regex:` rule when one
exists; a red-first auditor rule comparing the two, with the assumed-`kind` fallback permitted only
where no runtime regex exists.

### D-3 — `POST /v1/purchase-hub/orders` `items[].quantity` materially mistyped — **High**

Spec (`tenant-full.json`, `/v1/purchase-hub/orders` post requestBody): `{"type":"string","pattern":"^-?\\d+(\\.\\d{1,4})?$","x-autoerp-precision-classification":"quantity"}`.
Runtime (`PurchaseHubOrderController.php:26`): `'items.*.quantity' => ['required', 'integer', 'min:1']`.
Three falsehoods in one node: (a) a JSON **number** `5` — the natural and accepted wire form — is
spec-invalid because the branch is string-only; (b) `"-3.5000"` is spec-valid and runtime-rejected
(`integer`, `min:1`); (c) `min:1` is absent from the document entirely. This is an inline
`$request->validate()` site the `numericUnionFromSchema` machinery never reaches — evidence the
FormRequest-only cause fix does not cover the inline-validate surface.

### D-4 — `GET /v1/imports/{id}/preview` response `data.rows` typed `string` — **High**

`tenant-full.json:34969` → `.responses.200 … properties.data.properties.rows` = `{"type": "string"}`.
Runtime (`ImportController.php:279-288`) returns `$sampleRows->map(fn ($row) => ['row_number' => …,
'data' => …, 'is_valid' => …, 'errors' => …])` — a JSON **array of objects**. The sibling `headers`
is correctly typed `array of string` in the same node, so this is not a blanket policy. No deviation
marker, no `x-` classification. (I do **not** call the sibling `summary.total_rows`/`valid_rows`/
`invalid_rows` `"string"` typings a defect: `ImportJob::casts()` at `ImportJob.php:83-93` does not
cast them, so a PG driver-level string is a defensible wire observation — this claim is withdrawn
before it is made.)

### D-5 — `VerifyManagerPinRequest.company_id` is unsatisfiable: generation-context leak — **High**

`#/components/schemas/VerifyManagerPinRequest/properties/company_id` =
`{"type":"string","format":"uuid","enum":[""]}`. Runtime (`VerifyManagerPinRequest.php:46`):
`['required','uuid', Rule::in([$companyId])]` where `$companyId = $this->companyContext->requireCompany()->id`.
The generator ran without a company context and baked the resulting empty string in as the sole
permitted value. The schema simultaneously requires `format: uuid` and the empty string — **every
real request is spec-invalid, and no request can ever be spec-valid.** A mechanical sweep finds
13 `enum: [""]` nodes in `tenant-full.json`; 12 are the degenerate `anyOf[{string},{string,enum:[""]}]`
shape (constrains nothing, cosmetic), and this is the **one** standalone harmful instance. Zero in
`admin-full.json` / `external-full.json`. The generator must fail closed when an `In` rule resolves
to an empty or context-derived value rather than freezing it into the contract.

### D-6 — Lane gate test is CI-flaky by construction — **Medium**

`PilotVerificationTest.php:30` caps `verify.php` at 120 s. `verify.php` takes **97.07 s** standalone
on this machine (two `npx @redocly/cli@2.45.0` spawns, two `route:list` runs, a disposable merge-base
worktree). The 23 s margin is consumed by PHPUnit overhead, so the test errors deterministically here
(2/2, including isolated). It will flake in CI on any slower runner or cold npx cache. Raise the
timeout or hoist the heavy proof out of the PHPUnit wrapper.

### D-7 — House-rule deviation: `app()` helper in tooling — **Low**

`FullSurfaceRefiner.php:631` uses `app()->build($class)`. Brief §6 and CLAUDE.md rule 13 forbid the
`app()` helper (constructor injection only). Non-blocking, noted for the fix round.

---

## 4. Merge-readiness

Not applicable — the gate FAILS. The lane is **not** merge-ready.

For the parent's sequencing, recorded now so it is not re-derived later: local `dev` is **58 ahead of
`origin/dev`** (`git rev-list --left-right --count origin/dev...dev` → `0 58`). The lane is rebased
onto `7d85232cc`, which was `origin/dev` **and** local `dev` at rebase time but is now 58 commits
behind local `dev`. That gap is a **merge-time** concern, not a gate concern: it does not affect this
verdict, but the §4 regeneration will have to be repeated against whatever base the lane finally lands
on, and every SHA-256 in the handback re-frozen again at that point. Do not treat the current artifact
hashes as final.

Blocking before any round-5 gate:
1. D-1 and D-2 closed at the cause (extractor + pattern derivation), red-first, with the fail-open
   escape hatch at `StrictSchemaTruthAuditor.php:159-161` removed.
2. D-3, D-4, D-5 closed, with a mechanical rule covering the inline-`$request->validate()` surface and
   a fail-closed guard on context-derived `In` values.
3. D-6 fixed so the lane's own gate is reproducible off the implementer's machine.
4. **Owner decision on the escalation clause** (§1) before fix round 4 is dispatched.

