# FINAL-GATE REGISTER — package p2 M4 round 1 attempt 2
accepted_sha: f91bba9cb56d92fffa8c64fc39445bab416f4655
base_sha: c97e0d1ada0ff73c7beb12dca478fa106df23128   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml
snapshot: detached-worktree @ f91bba9cb56d92fffa8c64fc39445bab416f4655 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-19-r1.md=6ae871bb9c81decf6518c02cb9b78b0feafdc40c542e07f29039a6694b229820   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:frontend-conventions=278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385
control_sha256: lens:tenancy-authz=9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345
max_fix_rounds: 5
---
I have completed the review. Writing the register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement p2, milestone M4, round 1

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.PVjHjAp45i/snap` at `f91bba9cb56d92fffa8c64fc39445bab416f4655` (A)
**Range:** `c97e0d1ada0ff73c7beb12dca478fa106df23128..f91bba9cb56d92fffa8c64fc39445bab416f4655` — base confirmed a strict ancestor of A; 55 commits; `git status --porcelain` empty in the sealed worktree.
**Handback evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-19-r1.md`
**Lenses applied in full:** `frontend-conventions`, `tenancy-authz`.
**Control-file preflight:** clean — `git diff --name-only base..A` contains no `scripts/adversarial-review*.sh`, no brief, no `SELF-REVIEW-HARNESS.md`, no `enforcement-control-manifest.yaml`, no `.claude/agents/*-reviewer.md`, and no added `*control-manifest*` surrogate.

## Content-derived evidence of inspection (required tables)

| Handback table | Rows | First row key | Last row key | Independently reproduced? |
|---|---|---|---|---|
| **§3a** i18n provenance | **3** (locale rows) | `en` (own 56 / en-aliased 0 / english-spread 0) | `ar` (own 21 / en-aliased **23** / english-spread 12) | Yes — `--json` `stats.aliasedNamespaces.ar = 23`, `stats.namespaces = 56`; 21+23+12 = 56 |
| **§3b** baseline composition | **5** (metric rows) | `namespaces` = 56 | `structural failures` = 0 | Yes — see below |
| **§3b** baseline entry file (`i18n-completeness-baseline.json`) | **2 917** | `ar\|adminCountryDefaults\|aliased\|*` | `fr\|workshop-bundles\|plural\|interval.months_many` | Yes — parsed; breakdown exactly `ar aliased 23 · ar missing 2848 · ar plural 9 · fr plural 29 · en plural 8` = 2917 |
| **§3c** feature-lane census | **3** (measure rows) | `all of tests/Feature (74 groups)` = 1 329 files | `minus the 111 allowlisted names inside them` = 1 003 / **991** | Yes — `find tests/Feature -name '*Test.php'` = **1 329**; manifest `groups` = **74**; `debt_ceiling` = **1 114** |
| **§3c** lane manifest (`feature-lane-manifest.json` groups) | **74** | `(root files)` (deferred, 1 class) | `Workshop` (deferred, 46 classes) | Yes — keys sorted; 3 `lane` groups (215 classes) + 71 `deferred` (1 114) = 1 329 |
| **§3d** F-2 measurement | **1** (measurement row) | `6.24 s/class mean` (40-class random 6.18, `Accounting` 6.29, SQLite) | same row (whole suite ≈ 138 min, 71 laneless groups ≈ 116 min) | Not reproducible in the sealed snapshot (no `vendor/`); accepted as disclosed local measurement |

## What I verified by execution, not by reading the handback

The i18n audit runs dependency-free, so I re-ran it rather than trusting the pasted evidence:

- Green run reproduces the handback verbatim: `56 namespaces, en=9242, fr=9258, ar=4702 authored (1998 behind aliases); 2917 known gap(s)`, `fresh: []`. **Exit 0.**
- `env -u I18N_BASELINE_PROTECTED_BLOB` → `FAIL CLOSED: … is unset`. **Exit 1.**
- Wrong variable → `FAIL CLOSED: MIRROR DRIFT`. **Exit 1.**
- **Matched-growth tamper executed by me** (planted `ar|zzz-planted-namespace|missing|planted.key` into a copy of the baseline, real pinned blob): `RATCHET GROWTH: 1 baseline entry added relative to the pinned protected baseline (da151bbc5…). The baseline is REMOVAL-ONLY.` **Exit 1.** This is the R2-C-1/R3-C-1 mechanism and it genuinely fires.
- Pin integrity: `git rev-parse cb618c12c:apps/web/tools/i18n-completeness-baseline.json` = `da151bbc51edcd066d2153b51a1df8ae1ab5bd32` = YAML mirror = blob at A. Two-commit topology (seed `cb618c12c` → metadata `29b6043bd`) matches R4-H-3.
- Anchoring safety: the new filter form `/\\(A|B)::/` requires a literal `\` before the class name, so `AnalyticsTest` can no longer select `ExpenseAnalyticsTest`. I confirmed **0 of 1 329** `tests/Feature` classes lack a `namespace` declaration, so the anchor cannot silently drop a class.

PHPUnit and Vitest suites could not be executed — the sealed snapshot carries no `apps/api/vendor` or `apps/web/node_modules`. I therefore verified those suites structurally (47 checker liveness cases including self-application, debt-relabelling, and host-job gating; 50+ i18n cases including matched growth, mirror drift, and an explicit "no `--no-ratchet` opt-out" assertion) and state the limitation rather than certifying their green.

Deliverable coverage is otherwise strong and I want that on the record: `test:tools` is exactly `vitest run tools/__tests__`; the CI step runs exactly `pnpm test:eslint-rules && pnpm test:tools`; the i18n gate is a discrete `frontend-lint` step (never the `lint` chain) with `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` mapped and the pin tag fetched explicitly; `security-regression` is a new no-`if:` job added to `all-checks-pass` `needs` (13 members); all three untested ESLint rules gained RuleTesters; the C6 case was correctly left to UI Wave 0 T7 under the ownership rule instead of duplicated.

---

## Findings

### [BLOCKER / P1] — `packages/shared/types/generated.d.ts` was changed, and three separate artifacts in the accepted candidate explicitly state it was not

Commit `48a7101bb` ("Phase 5.4.1: M4 — whole-package evidence") modifies `packages/shared/types/generated.d.ts` (+5/−1: adds `MovementGlKind`, `DeliveryComplianceCode`, `PostingContext`, `PreDeliveryInvoicingPolicy`, and `inventory_shrinkage_expense`/`inventory_gain_income` to `SystemAccountPurpose`). The **same commit** writes the denial:

- `docs/handoff/progress/enforcement-p2.progress.yaml` `blockers[0].detail` — *"…and P2 touched neither a PHP enum nor the generated file."*
- `docs/handoff/ANNOUNCE-enforcement-p2-ci-contract-change-2026-08-19.md:294-295` — same sentence, in the document that becomes the parent's **outward-facing announcement to ten in-flight lanes**.
- The commit message of `48a7101bb` itself, plus *"Scope proof: 84 files, no control file touched."*

The handback compounds it: §4 Deviations discloses only `statements/api.ts`, and §5e asserts *"Only production source touched: `apps/web/src/features/treasury/statements/api.ts`."*

I confirmed the change is **content-correct** — all six added types pre-exist at `base_sha` in PHP (`MovementGlKind` 11 files, `DeliveryComplianceCode` 6, `PostingContext` 6, `PreDeliveryInvoicingPolicy` 12, both purposes 2 each), and P2 changed no DTO PHP (only `tests/Architecture/FeatureLaneManifestCheckerTest.php` and `tools/feature-lane-manifest-check.php`). So this is a faithful catch-up regeneration of pre-existing base drift, not a fabrication. That is precisely why it should have been declared as a deviation rather than denied. Brief §7 item 4 is unambiguous: *"A silent deviation found at gate time invalidates the handback."*

**Fix:** either revert `generated.d.ts` to its base state (leaving `types-drift` genuinely inherited-red), or keep the regeneration and correct all four locations to state that P2 regenerates `generated.d.ts`, with the change listed in handback §4 Deviations.

### [MAJOR / P2] — the M4 scope proof is a stale paste that does not reproduce at A, and it is stale in exactly the way that hides finding 1

Handback §5e pastes:

```
$ git diff --stat c97e0d1ad..HEAD | tail -1
 84 files changed, 12022 insertions(+), 42 deletions(-)
```

At A the true value is `85 files changed, 12044 insertions(+), 45 deletions(-)`. I scanned every commit in the range: that exact string reproduces only at `6774489be`/`3f05c5b4a` — the **M3-era** commits, two commits before A. The 85th file, present at A and absent from the pasted count, is `packages/shared/types/generated.d.ts`. The scope proof is a named M4 acceptance criterion (`git diff --stat <base_sha>..HEAD` + allowlist), and it is the specific control that exists to catch an undeclared path.

**Fix:** re-run the scope proof at A and paste the current output, with the allowlist check applied to all 85 paths.

### [MAJOR / P2] — the "FOUR inherited red gates" blocker overstates the parent's burden by one, and the ANNOUNCE will propagate it

`types-drift` regenerates types and fails only if `git diff --quiet -- packages/shared/types/generated.d.ts` is dirty (`.github/workflows/ci.yml`, job `types-drift`, step *"Fail if generated.d.ts is out of sync"*). By committing the regenerated file, the candidate removed the cited defect: `MovementGlKind` is present in `generated.d.ts` at A. Yet the YAML blocker and ANNOUNCE §8.3 both tell the parent all four gates *"run on the pre-promotion dispatch and block promotion until remediated, re-baselined, or explicitly waived."*

The other three (`backend-analyse` / LEDGER C-3, `backend-architecture` deptrac 99→174, `frontend-lint`'s `lint:ratchet` pos 40→84) I did not disturb and have no reason to doubt. Only the fourth is affected. I cannot confirm locally that the committed regen is *complete* (no `vendor/`, cannot run `php artisan typescript:transform`), so the honest statement is: the cited defect is no longer reproducible at A, and completeness is for the S-14 dispatch to confirm.

**Fix:** restate the blocker and ANNOUNCE §8.3 as three inherited gates plus a fourth that P2 closed by regeneration (or, if reverting per finding 1, keep four and drop the regen).

### [MINOR] — mid-wave `max_fix_rounds` overrides are asserted from inside the candidate

`M1` carries `max_fix_rounds_override: 6` with `fix_rounds: 5`; `M2` carries `max_fix_rounds_override: 7` with `fix_rounds: 7`; top-level `max_fix_rounds` stays 5. Both are backed by parent rulings quoted verbatim in the YAML, but those quotations were written by the executor into its own candidate and are not verifiable from within it. No action required and no bearing on this gate: R8-C-1 already sources the final gate's `max_fix_rounds` from the control manifest, the executor deliberately left the top-level value at 5 so the field-check holds, and deviation 7 discloses the arrangement. Recorded for the parent's awareness only.

---

## Assessment

The engineering is good and in several places better than the brief asked for — the authored-provenance i18n scanner defeats the merged-`resources` blindness by construction, the anti-growth ratchet fires under my own tamper test, the lane manifest applies its gating analysis to its own host job, and the `security-regression` job closes a real rule-12 blind spot on PR→dev. None of the findings above touch that work.

What fails is the evidence contract this gate exists to enforce. A file changed; the candidate says in three places that it did not; the scope proof that would have surfaced it was pasted from two commits earlier; and one of those three places is the announcement the parent is about to send to ten lanes. Under the R8-H-2 handback binding and brief §7 item 4, that is disqualifying regardless of the change being benign. The remedy is four text corrections and one re-run command — a short fix round, not a rework.

VERDICT: CHANGES-REQUIRED
