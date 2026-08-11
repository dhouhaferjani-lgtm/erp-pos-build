# Codex A→Z dispatch — OpenAPI contract truthfulness fix round (2026-08-10)

**Model/effort (owner directive):** Codex SOL, HIGH effort. Workhorse mode: implement
end-to-end on-branch, red-first at the *auditor* layer, then hand back for the
orchestrator-side gates below. **Handback-only lane: NOT merged, NOT pushed.**

**Lane:** `project_openapi_contract_lane` · branch `codex/openapi-contract-a-to-z` ·
worktree `/Users/houssamr/Projects/syneriva/apps/erp.openapi`
**Original A→Z brief:** `docs/superpowers/plans/2026-08-06-codex-dispatch-openapi-mcp-layer-a-to-z.md`
**Full handover (judgment, not just files):** `docs/handoff/HANDOVER-openapi-lane-orchestration-2026-08-07.md`

---

## §0 — STATE CORRECTION: read this before dispatching

The lane ledger's headline ("dual-gate r1 = truthfulness FAIL → fix-round dispatch to
Codex OWED") is **stale**. Branch reality at the time of writing (verified in-tree):

| Fact | Value | Verified how |
|---|---|---|
| Lane branch tip | `5ae9e1351` "Phase 10.1.37: Close OpenAPI truthfulness round two" | `git log -1 codex/openapi-contract-a-to-z` |
| Worktree state | **clean**, no uncommitted work | `git status --short` in `erp.openapi` |
| Fix round 1 | delivered `e1f3867f8` (+ hermetic closure `f77d0e03f`) | on-branch |
| Truthfulness gate round 1 | FAIL — verdict `94db998dd` | `2026-08-07-openapi-dual-gate-verdict-round1.md` |
| Truthfulness gate round 2 | FAIL (converging) — verdict `e30f4c5bf` | `2026-08-08-openapi-dual-gate-verdict-round2.md` |
| **Fix round 2** | **ALREADY DELIVERED at `5ae9e1351`** (2026-08-08 22:38), internal verdict claims all round-2 findings closed | `2026-08-08-openapi-fix-round2-internal-adversarial-verdict.md` |
| Truthfulness gate **round 3** | **NOT RUN** — no verdict file exists in either tree | `ls docs/superpowers/reviews/ \| grep round3` → empty |

**Therefore the next OWED action is the ORCHESTRATOR's independent truthfulness gate
round 3 against `5ae9e1351` — not a Codex fix round.** This brief is the pre-loaded
dispatch for the fix round that follows that gate, plus the one block of Codex work
(§4, dev-drift rebase + regeneration) that is owed unconditionally regardless of the
gate outcome.

**Dispatch order (recommended, matches the lane ledger):**
1. Orchestrator runs independent truthfulness gate **round 3** at `5ae9e1351`
   (independent Codex reviewer, verdict to file — never inline).
2. If FAIL → dispatch this brief with §3-B filled from the round-3 verdict.
3. If PASS → dispatch this brief with **§3-B empty and §4 only** (the rebase/regen block).
4. Promotion still waits on the DPA-track completion gate (owner sequencing ruling).

> **DISPATCH DECISION (orchestrator, at dispatch time):** the alternative sequencing —
> rebase onto `origin/dev` FIRST, then gate the rebased head — saves one gate cycle but
> re-targets the gate at a much larger diff and invalidates every SHA-256 in the
> round-2 handback. The ledger's sequencing (gate first, rebase after) is the
> recommendation. Pick one explicitly; do not let the lane pick.

---

## §1 — Scope: what is ALREADY DONE / OUT (do NOT re-implement, do NOT expand into)

- **Fix round 1 (all four round-1 blocker/major classes)** — landed `e1f3867f8` and
  **independently re-verified PASS in round 2**: auto-save `additionalProperties: true`,
  the 23 bare `{}` nodes, auditor position coverage, admin plan-usage core repairs,
  fiscal envelope/500/`exception_class`, TTC `unit_price` semantics. The exhaustive
  round-2 traversal found **zero** permissive nodes across 20,842/1,076/336 schema
  nodes. Do not re-open. (Two round-1 items *recurred* in round 2 under a different
  defect class — split-validation request and admin plan nullable fields — and are
  listed in §3-A as R2-6 and R2-7.)
- **Hermetic determinism closure** — landed `f77d0e03f` under ruling `950e68784`
  (warm-cache proofs invalid; inventory superseded to 767/526/505-14-7). Generation is
  fail-closed on process-start `CACHE_STORE=array`, `DB_CONNECTION=central`,
  `TENANCY_DB_PER_TENANT=true`. Do not relax.
- **F-7 (BatchExpiry FEFO float quantities)** — **OUT OF SCOPE, SEPARATE SESSION,
  ALREADY FIXED AND MERGED** to local `dev` at `d338b5fa4` via branch
  `fix/f7-fefo-quantity-precision`. Residuals (incl. the `BatchStockService` float-guard
  sibling) live in `docs/superpowers/tickets/2026-08-08-f7-fefo-residuals.md` and belong
  to the post-DPA re-triage lane. **Do not touch FEFO/BatchExpiry code in this lane.**
- **All other codebase findings F-1..F-11** —
  `docs/superpowers/audits/2026-08-07-openapi-lane-codebase-findings.md`. **OWNER RULING
  2026-08-07: codebase findings go to a SEPARATE dedicated session, never fixed in this
  lane** (zero-behavior-change is the lane's defining constraint + CLAUDE.md rule 4).
  New discoveries are *reported to the orchestrator register*, never fixed here. Note
  the register is F-1..**F-11**, not F-1..F-7 as some summaries say; F-10 (13 red
  pre-existing module test paths) is separately gate-blocking for lane final acceptance.
- **CLI / MCP server stretch** — **correctly deferred as a future lane.** The lane's own
  NO-GO (module reds F-10 + the 3-JSON-tests-per-module evidence requirement) was the
  right stop. Do not start it, do not scaffold it, do not "prepare" for it.
- **Promotion / publication** — blocked. No push to `origin/dev`, no
  `docs/04-API-CONTRACTS/` publication, no REALIGNMENT-LOG commit. The draft
  REALIGNMENT-LOG entry stays a draft inside the handback doc.

---

## §2 — Zero-behavior constraint (unchanged, still absolute)

Every rule from the original A→Z brief remains in force:
- **Zero production-file diff.** The branch diff from merge-base must stay confined to
  OpenAPI tooling / specs / OpenAPI tests / fixtures / docs / CI.
- **`route:list` byte-identity.** Raw route list stays exactly 1,037 routes at SHA-256
  `ea1a35bca3627b739e1be460c9da83ae8b843356127900c110ae70e3c3552b4c` (this is the
  merge-base value; after the §4 rebase it MUST be recomputed against the new base and
  proven byte-identical to that new base, not to this constant — see §4).
- **No curation.** Never delete or reclassify a route to make a gate pass.
- **Determinism.** Dual fresh-process regeneration, byte-identical, hermetic cache.
- **Standing ruling (`b5e4bda00`):** pre-measurement numeric ceilings are REPORTED
  MEASURES, not admission gates. Record, note, CONTINUE. Mandatory stops only for:
  behavior change, determinism failure, truthfulness compromise, or a value that needs
  implementer judgment. **A count breaching a pre-frozen limit is NOT a stop.**
- **Calibration rule (round-2 verdict, still binding):** if a NEW systematic defect
  class is found, its closure MUST include a mechanical auditor rule with red-by-default
  tests. **Hand-sweeps are no longer accepted as closure evidence for systematic
  defects.** Fix CAUSES, not instances.

---

## §3 — The truthfulness failures

### §3-A — Round-2 gate findings (the standing enumerated work list)

Source of truth: `docs/superpowers/reviews/2026-08-08-openapi-independent-truthfulness-gate-round2-codex.md`
(independent Codex, target `f77d0e03f`; carried on-branch and in the main repo).
Orchestrator spot-verified the critical finding and one nullability claim at source
before accepting — both confirmed.

All seven are **claimed closed** by the lane at `5ae9e1351`. Their status here is
`CLAIMED-CLOSED — UNVERIFIED INDEPENDENTLY`. The fix round must supply **fresh
per-finding evidence** (spec `path:line` + runtime source `path:line` at the *current*
head), not a citation of the lane's own internal verdict. Anything the round-3 gate
re-opens is a fix-round item.

| # | Sev | Finding | Evidence (round-2 gate) | Required shape of closure |
|---|---|---|---|---|
| **R2-1** | **Critical** | **Fiscal SALE_RECEIPT contract attached to the WRONG runtime location.** Spec conditionally requires raw `payload.line_items` when `payload.event_type == SALE_RECEIPT`; runtime never reads the raw sibling — `OutboxIngestor` derives and validates line items from `canonical_bytes`. A valid canonical SALE_RECEIPT omitting raw `line_items` is accepted at runtime but rejected by the spec. Also `sequence_number` minimum is **1**, not 0. | spec `tenant-full.json:156015-156018,156078-156136`; `Fiscal/Presentation/Requests/IngestFiscalEventsRequest.php:49-55`; `Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:67-79,144-164`; `Fiscal/Application/DTOs/FiscalEventEnvelope.php:120-180,201-237`; `Fiscal/Application/Services/OutboxIngestor.php:143-167` | Drop the false conditional requirement; attach the (already correct) TTC line-item semantics to the **canonical-bytes** carrier; `minimum: 1`. |
| **R2-2** | **High/SYSTEMATIC** | **Inert `pattern` on number branches — 62+ tenant instances.** In OpenAPI 3.1, `pattern` constrains strings only; a regex on a number branch enforces nothing. The registered witnesses prove `1.0` keeps a number wire type; they do NOT prove rejection of an over-precision JSON number. Plus: `walk_in_buffer_hours_per_day` is an unmarked 2-dp numeric input, and three loyalty-tier fields document 4 decimals where runtime allows 2. | spec `tenant-full.json:1-6` (dialect), `:116129-116166`, `:119981-120113`, `:134306-134314`; `Scheduling/Presentation/Requests/UpdateScheduleConfigRequest.php:24-30`; `Loyalty/Presentation/Requests/CreateTierRequest.php:26-43`; also `CloseShiftRequest.php:27-31`, `ConfirmBankStatementRequest.php:20-27` | **Auditor rule** rejecting `pattern` on any node/branch whose type includes number/integer, red tests first; regex ceiling stays on the string branch; number branch removed or explicitly marked scale-unbounded. |
| **R2-3** | **High/SYSTEMATIC** | **Nullability erased or invented on the 136 new identities.** Enumerated: `Channel.metadata`; document-ingestion `extraction`; document-ingestion `error` (a nullable **map**, not a string); `OrderLineResource.modifiers`; `LocationResource.legal_identifiers` (null = inherit-company, a legal wire state); opening-balance `mapped_data`; tax `metadata`; tax `applicable_document_types` (spec **invents** null — the resource normalizes null → `[]`); VAT `special_items` + `declaration_data`; statement-profile `column_map` empty-`[]` case. | spec `tenant-full.json:26801-26824`, `:115798-115829`, `:124029-124078`, `:124668-124669`, `:124794-124836`, `:129717-129773`, `:135561-135566`, `:94326-94328`; sources `Channel/Domain/Models/Channel.php:15-25,49-56`, `DocumentIngestion/Domain/DocumentIngestion.php:22-33,113-122`, `POS/Domain/OrderLine.php:25-35,80-93`, `Company/Domain/Location.php:28-36,105-116`, `Accounting/Domain/OpeningBalanceImportRow.php:15-24,63-70`, `Taxation/Presentation/Resources/TaxConfigurationResource.php:19-39`, `Taxation/Domain/Entities/VatPeriod.php:25-38,78-90`, `Treasury/Presentation/Requests/StatementProfileRequest.php:24-38` | **Mechanical auditor** comparing spec nullability against model cast+docblock (+resource normalization) for directly-serialized fields, red tests for BOTH classes (erased nullable, invented null), THEN sweep the remaining new identities. |
| **R2-4** | High | **Admin monitoring disk response materially mistyped.** Byte counts declared as 3-dp money strings; `*_human` (e.g. `"123.45 GB"`) constrained by a decimals-only regex. | spec `admin-full.json:1446-1491,2602-2647`; `Admin/Application/Services/MonitoringService.php:645-660,728-739` | Byte counts = NUMBERS; humanized = unit-suffixed STRINGS; both classified non-precision. |
| **R2-5** | Medium | **Variant-label format vocabulary invented.** Spec documents `id`/`name`/`page_width_mm`/`columns`/`horizontal_gap_mm`; runtime emits `key`/`label`/`page_size`/`cols`/`gutter_x_mm` and has no `page_width_mm` at all. | spec `tenant-full.json:138525-138575`; `Catalog/Domain/Support/LabelSheetFormat.php:18-29,101-115`; `Catalog/Presentation/Controllers/VariantLabelController.php:43-50` | Exact `LabelSheetFormat::toArray()` vocabulary. |
| **R2-6** | Medium | **Split-validation request excludes accepted JSON numbers** (round-1 closure that was under-wide, not permissive). Schema says string-only for `total_required` / `splits[].amount`; runtime uses Laravel `numeric`, so it accepts numbers too, and echoes either wire type — the response's own witness already acknowledges both. | spec `tenant-full.json:55464-55493` vs response witness `:55514-55561`; `Treasury/Presentation/Controllers/MultiPaymentController.php:561-584` (3-dp rejection at `:565-570`) | number\|string union per the `numeric` rule, with the 3-dp ceiling truthfully placed. |
| **R2-7** | Medium | **Admin plan summary suppresses nullable plan fields.** `plan.description`, `price_monthly`, `price_yearly` documented as required strings; all three nullable at runtime and returned unnormalized. | spec `admin-full.json:4302-4329`; `Billing/Domain/Plan.php:18-25,64-70`; `Billing/Application/Services/PlanEnforcementService.php:448-456` | Nullable, matching the model. (Covered by R2-3's mechanical auditor if it reaches the admin surface — verify it does.) |

**Round-2 items recorded as PASS (do not regress, do not re-litigate):** exhaustive
permissive traversal (20,842/1,076/336 nodes clean); hardened `StrictSchemaTruthAuditor`
position coverage incl. live mutation rejection at exact JSON pointers
(`tools/openapi/src/StrictSchemaTruthAuditor.php:12-127`,
`tests/Unit/OpenApi/StrictSchemaTruthAuditorTest.php:16-124`); `unit_price` TTC-vs-HT
semantics (`tenant-full.json:155896-155900` vs
`Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2171-2183`); surface
separation 954/43/18 pairwise disjoint; admin plan-usage integer counters / nullable
period-end / boolean `is_on_trial` / documented 404; fiscal envelope-of-envelopes,
nullable `exception_class`, documented 500.

### §3-B — Round-3 gate findings — **FILL AT DISPATCH**

**Round 3 RUN 2026-08-11 — GATE VERDICT: FAIL.** Verdict of record:
`docs/superpowers/reviews/2026-08-11-openapi-truthfulness-gate-verdict-round3.md`.
Closed: R2-1/R2-2/R2-4/R2-5/R2-7. The work list below is R2-3 (partial), R2-6
(partial), R3-1 (new systematic).

| # | Sev | Finding | Evidence | Required shape of closure |
|---|---|---|---|---|
| **R2-3-residual** | **High** | Nullability auditor incomplete: register document-ingestion `extraction` + `error` and every directly-serialized field named in R2-3; make unresolved registered pointers FAIL CLOSED at `SerializedFieldNullabilityAuditor.php:51-54` (currently fails OPEN when a registered node disappears — proven by live admin `plan.description` removal probe). | `SerializedFieldNullabilityAuditor.php:51-54`; document-ingestion `extraction`/`error` fields unregistered | Red tests for both defect classes: (a) missing registration for directly-serialized fields, (b) fail-open on a disappearing registered pointer. Then register the outstanding R2-3 fields. |
| **R2-6-residual** | **Medium** | Restore `min:0.01` on both split-validation number branches + non-negative string regex, per `MultiPaymentController.php:563-570`. Currently both branches admit -1, "-1.000", 0, "0.000" (schema-valid, runtime-rejected). | `tenant-full.json:55633-55708`; `MultiPaymentController.php:563-570` | Part of the R3-1 sweep — same auditor rule and remedy shape apply. |
| **R3-1** | **High / SYSTEMATIC** | Runtime numeric bounds disappear on numeric-string branches — 67 composed schemas (all tenant surface). A JSON Schema `minimum`/`maximum` on the numeric branch does not constrain the alternate string branch; current string patterns admit numeric values runtime rejects (signed patterns on non-negative fields, no range). The split-validation replacement additionally drops `min:0.01` from the numeric branch itself. Affected set spans money, quantity, percentage, multiplier, duration, and threshold inputs — a systematic contract-generation defect, not isolated schema errors. Witnesses: `walk_in_buffer_hours_per_day` "-1.00"/"25.00" schema-valid/runtime-rejected; `earning_multiplier` "0.50" schema-valid/runtime-rejected; split-validation -1/"-1.000"/0/"0.000" schema-valid/runtime-rejected. | `tenant-full.json:120304-120338,120349-120383,134704-134730,55633-55708`; `CreateTierRequest.php:34-42`; `UpdateScheduleConfigRequest.php:24-30`; `MultiPaymentController.php:563-570`; generator cause `FullSurfaceRefiner.php:275-296,355-381`; auditor gap `StrictSchemaTruthAuditor.php:80-85` (no cross-branch bound-parity rule) | Red-first mechanical auditor for runtime-bound parity on number\|string inputs. Restore numeric-branch bounds incl. split `minimum: 0.01`. Encode equivalent string restrictions where representable; otherwise an explicit source-backed runtime-bounds deviation stating the unenforced bounds. Sweep all 67 affected request nodes + the 2 split fields; live current-document mutation tests at exact pointers. **Calibration rule: closure REQUIRES a red-first mechanical bound-parity auditor + live mutation tests at exact pointers.** |

Escalation check performed: handover escalation rule is numeric-ceiling-specific;
truthfulness findings do not count toward that counter. Fix round authorized. If a
ROUND-4 gate surfaces another new unrelated systematic class, escalate to the owner
with the thin-contract restart recommendation before any further fix round.

---

## §4 — Unconditional work: dev-drift rebase + regeneration

Owed regardless of the round-3 outcome. The lane ledger states promotion waits on
"DPA-track completion + dev drift regeneration (the contract must regenerate against
merged dev before it lands)".

Measured state (verified):

- `origin/dev` = `7d85232cc`; local `dev` = `7d85232cc` (0 ahead — the "~147 ahead" in
  the DPA memory line refers to a different snapshot and is stale here).
- Lane branch is **347 behind / 40 ahead** of `origin/dev`; merge-base `46fd7decde`.

Required:
1. Bring `codex/openapi-contract-a-to-z` onto `7d85232cc` (merge or rebase — the lane's
   choice, but record which and why; a merge keeps the ruling-chain commits intact and
   is the safer default for a 40-commit ruling-bearing branch).
2. **Regenerate hermetically against merged dev** and re-freeze every canonical SHA-256.
   347 commits of dev include the DPA accounting/treasury/POS lanes — expect real route
   and schema movement. New routes are contract work, **not** baseline-gap entries
   (README: "Do not add a new route to the gap baseline to make CI pass").
3. Re-prove zero behavior **against the new base**: `route:list` byte-identity vs
   `7d85232cc`'s own route hash (recompute it; the `ea1a35bca…` constant belongs to the
   old merge-base), and an empty non-OpenAPI branch diff vs the new merge-base.
4. Refresh: `full-closure-manifest.json`, `full-inventory.json`, `inventory.json`,
   `route-coverage-baseline.json`, the derived-constant reconciliation, the handback
   headline numbers, and the internal adversarial verdict. **Every count in the handback
   is a reported measure — if it moves, report the new number and continue; do not stop
   on a ceiling breach.**
5. Report every newly discovered undocumented/orphan route to the **orchestrator
   register (F-4)** — never delete one.

---

## §5 — Re-verification the fix round MUST run (exact commands)

From `apps/api` in the `erp.openapi` worktree. Tooling is AutoERP-owned (there is **no
Spectral** in this lane — the external validator is pinned **Redocly CLI `@redocly/cli@2.45.0`**,
invoked from inside `verify-full.php`).

```bash
# Generation / all proof subprocesses — hermetic env is FAIL-CLOSED, never relax:
APP_ENV=testing APP_DEBUG=false APP_URL=https://api.autoerp.test \
AUTOERP_API_VERSION=1.0.0 CACHE_STORE=array DB_CONNECTION=central \
TENANCY_DB_PER_TENANT=true LC_ALL=C TZ=UTC \
php tools/openapi/generate-feasibility-baseline.php

# Gate 1 — pilot artifacts + route-coverage ratchet (shrink-only baseline)
php tools/openapi/verify.php

# Gate 2 — THE gate: dual fresh-process regeneration + committed-byte equality,
# OAS 3.1 in-process (Opis 2.6.0) + pinned Redocly validation with ZERO warnings,
# 1,011/1,011 client routes → 1,015 operations, zero gaps/orphans,
# pairwise-disjoint surfaces, middleware-derived auth/permission/module audit,
# corrected free-object predicate, both truth auditors.
php -d memory_limit=768M tools/openapi/verify-full.php

# Gate 3 — tests BY PATH ONLY (full PHPUnit suite is FORBIDDEN — it crashes the machine)
./vendor/bin/phpunit tests/Unit/OpenApi tests/Feature/OpenApi

# Gate 4 — static analysis (2 GiB worker limit was needed at round 2)
./vendor/bin/phpstan
```

Baselines to beat, from the round-2 fix handback (`5ae9e1351`) — these are **reported
measures**, restate them, do not treat them as admission gates:

- OpenAPI unit tests 97 (485 assertions); generator/pilot feature tests 13 (6,500 assertions).
- Hermetic admission 767 targets / 526 operations (505 tenant / 14 admin / 7 external);
  canonical identity SHA-256 `c50ae0d5a26c1743e721bee20085be180823501fb4dc4c43e1e6bd08b3589870`.
- Surfaces 954 / 43 / 18, pairwise disjoint. Coverage 1,011/1,011 routes → 1,015 ops.
- Wire-deviation register 112 nodes / 89 operations.
- Artifact SHA-256s (`tenant-full` `12e32833…`, `admin-full` `6fd01624…`, `external-full`
  `bdcb13b9…`, `inventory` `75073a3c…`, `full-closure-manifest` `af0f2c7f…`).
  **All five are invalidated by the §4 rebase — re-freeze and republish them.**

**Red-first discipline for this lane means auditor-first:** for every systematic class,
write the auditor rule's failing test (and, where possible, a live in-memory mutation of
the real documents proving rejection at the exact JSON pointer) **before** the sweep that
fixes the instances. That is the only accepted closure evidence for a systematic class.

---

## §6 — House rules (binding)

- **Tests BY PATH only.** Running the full PHPUnit suite is forbidden — it crashes the
  machine. No exceptions, no "just this once".
- **Never `git stash`.** The stash stack is repo-global and shared across ~60 worktrees.
- **Never push. Never merge. Never touch `dev`.** No `git push` of any kind; the
  `dev-push-guard` hook blocks it and this lane is handback-only anyway.
- **Work only in `/Users/houssamr/Projects/syneriva/apps/erp.openapi` on
  `codex/openapi-contract-a-to-z`.** Do not create a second worktree; do not edit the
  main `apps/erp` checkout.
- **Rule 19 — monetary & quantity precision contract.** Currency `decimal(N,3)`,
  quantity `decimal(N,4)`, percent 2-dp and NOT currency-scaled. Never a float on money
  or quantity. In this lane the rule mostly governs what the spec is allowed to *claim*:
  a regex ceiling is truthful only where runtime actually enforces it, and OpenAPI 3.1
  cannot enforce scale on a JSON number — say so explicitly in the deviation marker
  rather than implying enforcement.
- **`unit_price` is context-overloaded.** TTC/tax-inclusive in the B2C POS and fiscal
  SALE_RECEIPT canonical bytes; net/HT in B2B/Document and goods-receipt contexts. Never
  document one as the other. Never assert `line_subtotal == unit_price × qty − discount`
  on a POS line.
- **Strict typing.** No `mixed` in PHP; PHPStan level 8 clean on touched files; Pint clean.
- **Constructor injection only** in any tooling class (no `app()` helper).
- **A real PostgreSQL run before ANY green claim.** `DB_CONNECTION=central` against real
  PG — the `phpunit.xml` SQLite default changes Scramble's admin inference and produces
  a wrong inventory. **SQLite-run proofs and warm-cache proofs are both REJECTED.**
- **Determinism proof is dual-process, fresh, byte-identical.** Warm/ambient Redis cache
  invalidates the run (ruling `950e68784`).
- **Zero behavior change.** If a truthful fix would require touching production code,
  **STOP and report to the orchestrator register** — do not fix it here (owner ruling).
- **No new numeric-ceiling stalls.** Counts are reported measures (`b5e4bda00`). Stopping
  on a count breach while correctness holds is a standing-rule violation.
- **Every fix commit revert-replayable:** prove the covering auditor test goes red when
  the fix is reverted.

---

## §7 — Quality gates (orchestrator-side, after handback)

1. **Independent truthfulness gate round 4** — a fresh independent Codex reviewer, no
   access to the lane's verdicts/manifests as proof, verdict **to a file** in
   `docs/superpowers/reviews/`, never inline. Same six-property structure as rounds 1–3
   (permissive schemas · precision · new identities · material falsehoods · auditor
   coverage delta · `unit_price` semantics).
2. **Zero-behavior gate, re-run at the new head** — non-OpenAPI branch diff empty vs the
   new merge-base; `route:list` hash independently reproduced by the orchestrator against
   `7d85232cc`.
3. **fiscal-pos-reviewer** on the SALE_RECEIPT / canonical-bytes contract text (R2-1 and
   anything round 3 adds there) — the sealed-bytes carrier is the highest-blast-radius
   claim in the document.
4. **Spot-verification at source** of at least the critical finding and one nullability
   finding, by the orchestrator, before accepting any PASS.
5. Promotion remains BLOCKED until: truthfulness gate PASSES **and** the DPA track has
   handed back **and** the contract regenerates clean against merged dev.

---

## §8 — Handback contract (deliverable)

- **Branch `codex/openapi-contract-a-to-z` — NOT merged, NOT pushed.** Commit on-branch
  in the existing `Phase 10.1.x` numbering (next: `Phase 10.1.38`).
- **Report file:** `docs/sessions/codex-openapi-truthfulness-fixround-report.md`
  (session artifacts go in `docs/sessions/`, never the repo root).
- **Per-finding evidence, mandatory shape** — for **each** §3-A item and each §3-B item:
  1. the finding id and its original evidence citation;
  2. the **fix at the CAUSE** (auditor rule / generator rule), with the red test path and
     the assertion that reproduces the defect class;
  3. the resulting spec node **at the current head**, cited `file:line`;
  4. the runtime source that proves it, cited `file:line`;
  5. the revert-replay record (fix reverted ⇒ named test red).
  Citing the lane's own internal adversarial verdict is **not** evidence.
- **Updated on-branch documents:** `2026-08-07-openapi-contract-handback.md` (headline
  numbers + acceptance status), a new
  `2026-08-08-openapi-fix-round3-internal-adversarial-verdict.md`, and the derived-constant
  reconciliation. **If a previous internal claim turns out false, withdraw it explicitly**
  — the round-2 verdict's withdrawal paragraph is the model to follow.
- **Gate output pasted verbatim:** `verify.php`, `verify-full.php`, the two phpunit paths,
  PHPStan — with the command line and the environment variables shown.
- **New codebase defects → orchestrator register only** (append to
  `docs/superpowers/audits/2026-08-07-openapi-lane-codebase-findings.md` with an append-log
  row). Never fixed in this lane.
- **Explicit statement** of whether any mandatory-stop condition was hit (behavior /
  determinism / truthfulness / needs-implementer-judgment) and, if so, the exact question.

---

## §9 — VERIFY-AT-DISPATCH (unresolved from the sources — confirm before sending)

1. **Round-3 gate has not run.** §3-B is empty by necessity. Do not dispatch a "fix
   round" with no findings to fix; run the gate first, or dispatch §4 alone.
2. **Codex CLI cannot WRITE to `apps/erp.*` worktrees** (per
   `reference_codex_cli_sandbox_worktrees.md` — read-only review is fine). This lane has
   been driven by **Codex desktop**, which has been writing to `erp.openapi` throughout.
   Confirm the dispatch channel is desktop, not CLI, or the fix round cannot commit.
3. **PostgreSQL availability.** Generation requires `DB_CONNECTION=central` on real PG.
   Memory records local PG on 5432 and **Docker PG 5433 broken**. Confirm a working PG
   before dispatch — every proof in this brief is invalid without it.
4. **Redocly CLI `@redocly/cli@2.45.0` requires npm network access** on first use to
   populate the cache. Confirm the sandbox can reach npm, or pre-warm the cache.
5. **DPA-track completion** is a promotion precondition and was still in flight
   (Wave-3 3A/3B/3E implementing, 3C/3D queued). Confirm status before promising any
   promotion date.
6. **Vendor directory must be worktree-local**, never a symlink to the main checkout —
   Composer's optimized classmap records the other checkout's paths and makes generation
   observe unrelated edits (README explicitly warns; this is also a known
   worktree-backend gotcha in this repo).
7. **Rebase-vs-gate ordering** (§0) is an orchestrator decision, not the lane's.
8. **Findings register letter range** is F-1..**F-11**, not F-1..F-7; F-8/F-9/F-10/F-11
   were added after the register was first summarized. F-10 (13 red module test paths) is
   independently gate-blocking for lane final acceptance and the CLI/MCP stretch.
