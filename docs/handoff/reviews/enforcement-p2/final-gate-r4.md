# FINAL-GATE REGISTER — package p2 M4 round 4 attempt 1
accepted_sha: 0f2c09cf4763726314e83de18d84bcd95c1a2c80
base_sha: a4a8c2293dcf7556045f44d35cfa5f734be8022b   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml
snapshot: detached-worktree @ 0f2c09cf4763726314e83de18d84bcd95c1a2c80 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r4.md=a77e1dc6f1cee0aa79b6ec3c9a93bdb25a4b7e2aec3fb285084ec76a71ea53f6   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7d53797b1849b6adeb2fcb39789935a48fc32a6f4804a9833d4bb8a78f1aa2b7
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:frontend-conventions=278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385
control_sha256: lens:tenancy-authz=9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345
max_fix_rounds: 5
---
I have everything I need. Writing the register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement-p2, M4, round 4

**Snapshot** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.ZIBqHI7wrl/snap` · detached `HEAD = 0f2c09cf4763726314e83de18d84bcd95c1a2c80` (verified) · `git status --porcelain` **empty** (verified) · range `a4a8c2293..0f2c09cf4` = **63 commits / 84 files**; `a4a8c2293` verified **strict ancestor** of A, `BASE != A`.
**Handback reviewed** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r4.md` · `sha256 = a77e1dc6f1cee0aa79b6ec3c9a93bdb25a4b7e2aec3fb285084ec76a71ea53f6` · **359 lines**.
**Lens contracts applied in full:** `frontend-conventions`, `tenancy-authz`.

---

## A. Required content-derived table evidence (gate-r8 R8-H-2)

| handback table | lines | data rows | FIRST row key | LAST row key |
|---|---|---|---|---|
| §1 Header | 21–27 | **7** | `**base SHA**` | `**pushed?**` |
| §3a i18n provenance | 77–79 | **3** | `en` | `**ar**` |
| §3a basis (entries vs key-level) | 91–92 | **2** | `**baseline entries**` | `**key-level gaps**` |
| §3b Baseline composition | 107–111 | **5** | `namespaces` | `structural failures` |
| §3c Feature-lane census | 117–119 | **3** | ``all of `tests/Feature` (74 groups)`` | `minus the 111 allowlisted names inside them` |
| §5e Scope allowlist | 262–274 | **13** | `1` → `` `.github/workflows/ci.yml` — the CI wiring deliverable `` | `**84**` → `**total — OUTSIDE THE ALLOWLIST: NONE ✓**` |

Boundary cells as shipped — §3a first `| en | 55 | 0 | 0 |`, last `| **ar** | **21** | **22** | **12** |`; §3b first `| namespaces | 55 |`, last `| structural failures | 0 |`; §3c first `| all of tests/Feature (74 groups) | 1 348 | 1 335 |`, last `| minus the 111 allowlisted names inside them | 1 020 | **1 008** … |`.

**Independent re-derivation at A — every §3 census cell reproduces exactly.** Baseline JSON: **2 924** entries, first `ar|adminCountryDefaults|aliased|*`, last `fr|workshop-bundles|plural|interval.months_many`, composition `ar aliased 22 · ar missing 2 851 · ar plural 9 · fr plural 34 · en plural 8`. Scanner at A (authority exported): `55 namespaces, en=9228, fr=9244, ar=4697 (1986 behind aliases), 2924 known gap(s)`, `--json` → findings 2924 / covered 2924 / **fresh 0 / stale 0**. Provenance re-derived through the tool's own `classifyAssignment`: `en {own:55}`, `fr {own:55}`, `ar {own:21, en-aliased:22, english-spread:12}`. Locale files en 56 / fr 56 / **ar 34**. §3a basis re-derived over exactly those 34 namespaces: ar = 22 `aliased` + **2 613** `missing` + **5** `plural` = **2 640**; key-level 2 618 + **1 986** = **4 604**. §3c re-derived from disk: **1 348** files / **1 335** distinct; deferred+excluded 71 groups → **1 131 / 1 119** (`feature-lane-manifest.json` `debt_ceiling: 1131`, sum of the 71 ceilings = 1131, **zero slack**); allowlists 112 + 16 = **124** distinct, **111** inside debt groups → **1 020 / 1 008**.

---

## B. Findings

### CRITICAL-1 — ANNOUNCE §8 item 0 carries a `✅ DISCHARGED` stamp on top of an unmodified pre-discharge body whose bolded "Required post-merge step" sets `debt_ceiling` **below** the shipped value; executing it reds `backend-architecture` on `dev` for every lane

`docs/handoff/ANNOUNCE-…-2026-08-19.md:236-241` now reads "✅ **DISCHARGED IN-CANDIDATE (2026-08-21 stale-A rebase, Phase 5.5.1)** … ceilings REGENERATED against that merged tree (`CountryDefaults` 28 / `Document` 74 / `Fiscal` 79 / `Inventory` 106, `debt_ceiling` **1131**)". Verified true at A — `feature-lane-manifest.json` holds exactly those values, and my own enumeration confirms them with zero slack.

The body below it was not touched:

- **`:275-278`** — "**Required post-merge step, in the first commit on `dev` after the merge:** set `Inventory` → 106, `CountryDefaults` → 28, **`debt_ceiling` → 1116**". At A the first two are no-ops; the third is a **lowering** from 1131 by 15.
- **`:280-282`** — "*(Deliberately not pre-raised in the candidate: a ceiling set from another checkout's `dev` would introduce slack…)*". Flatly false at A, and the direct negation of the stamp eight lines above.
- **`:266-273`** — "**RE-BASELINE THE CEILINGS AFTER THE MERGE — otherwise P2 breaks `dev` for every lane** … the next PR→dev from *any* lane fails `backend-architecture` with `✗ COVERAGE DEBT GREW: group "Inventory" now holds 106 class(es), ceiling is 105.`" Unstamped, and false: the Inventory ceiling at A **is** 106.
- **`:59-61`**, inside the "⚠️ READ THIS FIRST" section addressed to ten lanes — "§8 item 0 re-baselines `Inventory` → 106, `CountryDefaults` → 28 and `debt_ceiling` → **1116** in the first commit on `dev` after the merge".

**Failure scenario, end to end.** The parent completes §5 steps 0–5 and merges A. Working the "Owed at promotion" list, it reaches the bolded `:275` directive and applies it: `debt_ceiling` → 1116 in `apps/api/tests/feature-lane-manifest.json`. The laneless class count on the merged tree is 1131. `apps/api/tools/feature-lane-manifest-check.php:802-808` compares the total against the declared ceiling and emits `TOTAL COVERAGE DEBT GREW: 1131 class(es) now sit in groups no lane runs, ceiling is 1116`, exiting non-zero. `backend-architecture` carries **no `if:`** and is in `all-checks-pass` `needs` (both verified at `ci.yml`), so every subsequent PR→dev from all ten in-flight lanes reds — the exact repo-wide breakage the section exists to prevent, produced by the section's own instruction. The pre-promotion `workflow_dispatch` cannot catch it: it runs on A, where the manifest is self-consistent at 1131 — as `:272-273` itself states.

The `:239-241` sentence "the instruction below stays as written for the residual case" does not rescue this. For the residual case (`dev` moves again) the correct value is whatever regeneration yields — never 1116 — and `:280-282` asserts a fact about the candidate that is false under **every** case.

This is the seventh firing of the series' documented **append-vs-substitute** class (rulings R5-H-3, r10, r12, r14, r18, r21), and the second consecutive firing inside this same artifact: round 3's CRITICAL-2 required "**replace** — not append". The replacement was performed in the handback's own tables and in `DECISION-…` §(2) — verified genuine — and skipped here.

### MAJOR-1 — the §3a census table asks the parent to adjudicate a discrepancy between two figures that both fail to reproduce at A, while the table immediately above states the correct one

`HANDBACK…:99-101` — "*Discrepancy for the parent to adjudicate:* the round-2 register cites **4 608** for the key-level figure; **I derive 4 613 from the shipped scanner (2 610 + 5 + 1 998)**. The decomposition is written out above so the arithmetic is checkable rather than asserted."

The shipped scanner at A derives **4 604** = 2 613 + 5 + **1 986** — which is exactly what the table at `:92` states, and what I reproduced independently. `1 998` and `2 610` are the pre-rebase figures at the discarded seed `cb618c12c` (23 aliases, not 22); they are quoted in the M1 round-7 register as round-time values. Neither 4 608 nor 4 613 reproduces at any revision in this candidate.

**Failure scenario:** the parent, told a live discrepancy is outstanding in a table the brief designates *the review target*, adjudicates between 4 608 and 4 613 — and the "decomposition … written out above", which the sentence points at as the check, contradicts both. The paragraph also directly undercuts its own table's authority two lines after it was correctly re-derived. `DECISION-…:192-199`, the equivalent operative section, carries the corrected basis table with **no** such paragraph, confirming this is a handback-local residue of the same append-vs-substitute pass.

### MINOR-1 — `DECISION-…` §(40) census table mixes three measurement epochs, only one stamped

`docs/handoff/DECISION-…-2026-08-19.md:995` "### (40) The census — the defect is much larger than the substring bug", table at `:1002-1008`: row `` `tests/Feature` classes | **1 329** `` (pre-rebase; **1 348** files / **1 335** distinct at A) sits directly above the correctly-updated `Classes living in groups no lane runs | **1 131** (re-derived at the accepted tip 2026-08-21; 1 114 pre-rebase)`. `Distinct classes reachable by NO CI job, ever | **990**` is base-time and unstamped here, though `ci.yml:176-177` self-labels its twin as "At this base" and handback §3c discloses it. One row of six carries the epoch stamp; the rest do not.

### MINOR-2 — false job count in the workflow-validity evidence layer

`HANDBACK…:217` — "`yaml.safe_load` parses `ci.yml`, **16 jobs**." Actual at A: **18** (17 at base; P2 adds exactly one, `security-regression`). The layer-1 conclusion is unaffected — the file does parse — but the figure is wrong in the section that stands in for the absent `actionlint`.

---

## C. What passes — recorded so the fix round stays tightly scoped

The implementation is sound and a fix round must not disturb it. Everything below I verified by execution or independent re-derivation, not by reading the handback.

- **Round-3 findings 1, 3 and MINOR-1 are genuinely closed.** `ANNOUNCE…:226` now publishes `I18N_BASELINE_PROTECTED_BLOB = 26a9ae1688d80e0f450215326b19ccd1701c9a8f` (seed `6a0c1cd72`) — matching `git rev-parse HEAD:…baseline.json`, `git rev-parse 6a0c1cd72:…`, and the YAML mirror at `:69`; `cb618c12c` confirmed a non-ancestor of A. §5e is re-run at A with a **true** HEAD-equality assertion. §5c reads 15 aggregate members, matching `ci.yml`.
- **Scope proof reproduces cell-for-cell.** 84 paths bucketing exactly as `:262-274` claims — 46 `apps/web/tools`, 25 `docs/handoff`, 3 `eslint-rules`, 2 `scripts`, 2 `docs/conventions`, 1 each ci.yml / `apps/api/tools` / `apps/api/tests/Architecture` / manifest JSON / `package.json` / `statements/api.ts`. Nothing outside the allowlist; no forbidden tree; **control-file preflight clean** (no `adversarial-review*.sh`, brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*`, or `*control-manifest*` surrogate in the range). `packages/shared/types/generated.d.ts` is **absent from the range and byte-identical to base** — deviation 8's revert holds.
- **Ratchet authority verified by execution, not inspection.** Unset variable → `EXIT=1`; variable ≠ YAML mirror → `EXIT=1` naming it a tamper signal; correct authority → `EXIT=0`, `fresh 0 / stale 0`.
- **Two-commit seed topology exact (R3-H-4 / R4-H-3).** `6a0c1cd72` touches **only** the baseline JSON; `bdfc3ea7f` touches **only** the progress YAML. **Baseline honesty holds** under the frontend-conventions lens: the delta is +101 / −94 (net +7), added entirely in `sales` (100) + `finance` (1), removed in `pos`/`finance`/`sales`/`inventory`/`marketing` — and P2 touches **no** locale file and **no** `i18n.ts`, so every added key is inherited merged-lane debt, not a `--write-baseline` absorption of the diff's own violations.
- **CI wiring correct and non-chain.** Event graph exactly `push→main`, `PR→main|dev`, `workflow_dispatch` (no `push→dev`). `ci.yml:1158` explicit `git fetch origin tag ci-pin/enforcement-p2-r1`; `:1161-1176` discrete `frontend-lint` step `pnpm audit:i18n` with `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` mapped in; `:1187` the exact string `pnpm test:eslint-rules && pnpm test:tools`; `:191` the manifest checker in `backend-architecture`. `"test:tools": "vitest run tools/__tests__"` verbatim (R2-H-3). None of the three carrying jobs has an `if:`; H-9 satisfied — the one new job joins `all-checks-pass` `needs`.
- **tenancy-authz lens:** `security-regression` moves the whole `tests/Feature/Security` directory onto PR→dev, whole-directory and therefore anchor-safe. No tenancy boundary, permission catalog, route middleware, or `can:` guard is touched anywhere in the range; no money/quantity path; no `app()` resolution in either new PHP file.
- **frontend-conventions lens:** exactly one production file — `apps/web/src/features/treasury/statements/api.ts:110-119`, `Omit<>` → `Pick<OffsetPaginationMeta, 'current_page'|'last_page'|'per_page'|'total'>`, type-only, narrowing, with the controller cited at the change site. No token, i18n, RHF, or query-key surface.
- **Phase 5.6.2 is behaviour-preserving.** Replacing `assertSame(1, preg_match(...))` with an explicit `self::fail()` guard satisfies level 8 without weakening the assertion — the test still fails when the debt line is absent.
- **Harness state correct.** YAML `base_sha` == the dispatch base, M4 `status: review`, `fix_rounds: 3` ≤ top-level `max_fix_rounds: 5`, pins non-null, `control_manifest` structured. Deviations 1–9 are disclosed prominently, including the executor substitution and the `max_fix_rounds` override provenance — deviation 7's admission that a candidate cannot authorise its own override is the right call.

---

## D. Required to clear this gate

1. **`ANNOUNCE-…:266-282`** — replace, do not stamp. Delete the `:280-282` "deliberately not pre-raised" parenthetical (false at A), rewrite `:275-278` so the only post-merge instruction is *regenerate against the merged tree and confirm the checker exits 0 on `dev`* with **no** literal ceiling values, and reconcile `:266-273`'s "P2 breaks `dev` / ceiling is 105" prediction with the discharge. Correct `:59-61` so it no longer tells ten lanes that item 0 re-baselines to 1116 post-merge.
2. **`HANDBACK…:99-101`** — remove or re-derive the "Discrepancy for the parent to adjudicate" paragraph; at A the shipped scanner yields 4 604 = 2 613 + 5 + 1 986, which `:92` already states correctly.
3. **`DECISION-…:1002-1008`** — bring the §(40) census table to one epoch (`1 348` / `1 335` at A) or stamp every row as `:1008` is stamped.
4. **`HANDBACK…:217`** — 16 jobs → 18.
5. Per gate-r7 R7-H-1 / gate-r8 R8-H-3 this is a **substantive** CHANGES-REQUIRED: the **executor alone** fixes, commits, increments `fix_rounds` to **4**, resets M4 to `status: review`, and hands over with `git status --porcelain` showing exactly `?? <handback-source>`. This register is not to be committed into the candidate. One fix round remains before `max_fix_rounds`.

**Note for the parent.** Nothing in §C is in question — the guards, the ratchet, the CI wiring, the scope and the census arithmetic all verify independently, and the census tables reproduce cell-for-cell from the shipped artifacts. What blocks is confined to four documentation reconciliations, one of which (CRITICAL-1) is a live promotion-time footgun in the artifact the parent executes.

VERDICT: CHANGES-REQUIRED
