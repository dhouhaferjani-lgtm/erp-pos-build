# ES/SV dispatch packages — brief gate, round 2

**Provenance**

| Field | Value |
|---|---|
| Artefact under gate | `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md` + `docs/handoff/progress/es-wave-a0.progress.yaml`; `docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md` + `docs/handoff/progress/sv-stage1.progress.yaml` |
| Gate type | Brief gate (pre-dispatch), round 2 |
| Tree state | `dev` @ `0d79b7d724d6ad581bbe7913c7039f5901e1448a` |
| Scope | Fix round for the nine round-1 findings only (`2026-08-11-es-briefs-gate-r1.md`) — round 1's "not re-opened" set is unchanged and out of scope here |
| Reviewer | Independent Codex reviewer, HIGH effort |
| Mode | In-band (verdict returned in-session; persisted here by the orchestrator) |
| Verdict | **BRIEF GATE: PASS** |

---

## 1. Verdict

**PASS.** All nine round-1 findings are APPLIED-FAITHFUL — each fix matches what the finding
required, not just its letter. No fresh-eye findings against the fixed regions. Both progress YAMLs
parse. Both dispatch packages (brief + progress YAML) are DISPATCH-READY.

## 2. Finding-by-finding disposition

| # | Finding | Disposition | Evidence |
|---|---|---|---|
| F-1 | Harness `owner_gate` field is an unconditional STOP; three milestones wrongly carried it | **APPLIED-FAITHFUL** | Neither `es-wave-a0.progress.yaml` nor `sv-stage1.progress.yaml` attaches an `owner_gate:` field to any milestone; ES M4, SV M2 and SV M3 carry no such field. The three gates live only as top-level `owner_gates:` entries marked `status: conditional` / `status: open` with `blocks_milestone: none`, each annotated `# informational — conditional logic lives in the milestone text`, and the conditionality itself is written into the milestone `title:` prose (ES M4: "FIRST verify... if it does NOT... STOP"; SV M2: "THE ARABIC GATE NEVER STOPS THIS WAVE... ship en+fr... and PROCEED"; SV M3: "IF ANY touch point genuinely does... STOP; OTHERWISE proceed"). |
| F-2 | ES R-2's "false red" mechanism is disproven by the code; ES-07 is a coverage/mirror defect, not a false-positive risk | **APPLIED-FAITHFUL** | Brief R-2 header now reads "ES-07 IS A COVERAGE / REPORTING DEFECT PLUS A MISSING MIRROR — and the 'false red' story is WRONG" (`CODEX-DISPATCH-es-wave-a0-2026-08-11.md:168`), citing the self-partitioning chain (`ReceiptHashService.php:178-213`, `:234-252`, `:334-398`) that makes a canonical-bytes row unreachable by the pipe recomputation. M2's contract (`:483-491`) drops the false-red mandate and rewrites the ask as (a) counted + per-arm-reported fiscal-era coverage and (b) an explicit `fiscal_hash`↔`current_hash` mirror, leaving the mechanism to the implementer with "no row's hash shape may change" as the guardrail. `es-wave-a0.progress.yaml` M2 title mirrors this exactly (NARROWED ES-07, defects (a)/(b), self-partitioning citation, mechanism choice left open). |
| F-3 | ES M4 conflated ES-09/ES-41/ES-42 under one refusal shape; only ES-42 is a refusal | **APPLIED-FAITHFUL** | `es-wave-a0.progress.yaml` M4 title now states three separate contracts by name: "ES-09 = CORRECTNESS: chain-head resolution scoped by (company_id, chain_context)... before-fix RED demonstrates context-blind head resolution... after-fix GREEN = the two-context append SUCCEEDS"; "ES-41 = TRIGGER-PRESENCE regression scoped to its snapshot row... non-PG driver scope is DOCUMENTED, not 'fixed'"; "ES-42 = the two-sided REFUSAL contract". The brief's M4 review checklist (`:634`) asks the ES-09/ES-41/ES-42 questions separately rather than as one refusal test. |
| F-4 | R-7/M3's straggler-contract approval flow doesn't fit a harness that reviews a committed range once per milestone | **APPLIED-FAITHFUL** | `es-wave-a0.progress.yaml` now has M3 ("the ES-16/ES-17 straggler contract PROPOSALS as a COMMITTED artifact at `docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md`... This milestone's bridge review approves/rejects THAT artifact. NO straggler implementation here.") followed by a distinct M3b ("straggler IMPLEMENTATION, strictly to the contracts approved at M3... Own bridge review."), each with its own `review_lenses`/`status`/`fix_rounds`/`commit`/`verdict` block. The brief's review table (`:632-633`) gates M3 on "Is `M3-straggler-contracts.md` committed in this range and reviewable?... straggler implementation absent" and M3b on "Does the implementation match the contract as approved at M3, clause by clause?". |
| F-5 | R-3's fleet driver left the "authorised actor per tenant" source unstated, forcing an invented identity model | **APPLIED-FAITHFUL** | Brief R-3 (`CODEX-DISPATCH-es-wave-a0-2026-08-11.md:204-219`) is now explicit: "THE DRIVER IS MANIFEST-DRIVEN... accepts an operator-supplied manifest (tenant → actor id) at invocation. For each tenant listed in the manifest, it runs the existing single-tenant verification, preserving the actor gate exactly as designed." M1's milestone text in the YAML repeats the manifest shape, the "missing/unauthorised tenants REPORTED LOUDLY... never silently skipped", the "NO service account or new identity model" prohibition, the terminal/context enumeration source, and the unworkable-manifest → STOP condition C escape hatch. |
| F-6 | ES-42's permission was left open on a live device route | **APPLIED-FAITHFUL** | Brief R-4 (`:232-247`) locks it: "THE PERMISSION IS LOCKED: `pos.operate_terminal`. Do not choose, do not invent, do not reseed", with the seeder grants (`RolesAndPermissionsSeeder.php:515-520`, `:590-597`, `:638-658`) and the sibling `ZReportSyncController.php:61` precedent as basis, plus "M4's FIRST action is to verify the principal, not to assume it." The YAML M4 title carries the same two-sided contract: verify-first, `blocked_owner` only if the fixture principal lacks the permission, unauthorized principal 403s and persists nothing, and "the fixture device sync path still SUCCEEDS end-to-end" — the device-success half is in scope, not dropped. |
| F-7 | SV M5 (whole-lane gate) was missing the `treasury` lens that rode M1 | **APPLIED-FAITHFUL** | `sv-stage1.progress.yaml` M5 `review_lenses:` is `[fiscal-pos, treasury, frontend-conventions, tenancy-authz]`. The brief states it twice: the revision-2 changelog (`:47`, "`treasury` added to M5's lens set (it gated M1)") and the M5 review-table row (`:469`, "`treasury` is re-applied because it gated M1"), plus the whole-lane narrative (`:411`, `:473`). |
| F-8 | The device-Arabic ticket (R-5) had no pinned path or minimum contents | **APPLIED-FAITHFUL** | Brief R-5 (`:213-233`) pins the path `docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md` and spells out four minimum-contents items: (i) no `ar` tree under `apps/pos/src/locales/` at BASE_SHA, (ii) `lib/i18n.ts` registers exactly `en`/`fr`, (iii) the RTL implications that make this a new surface, (iv) an explicit reference to the `sv11-arabic-device-locale` owner gate. `sv-stage1.progress.yaml` M2 title repeats the same path. (The ticket file itself is correctly *not* created yet — it is an M2 execution deliverable, filed when the milestone runs, not at brief-authoring time.) |
| F-9 | SV-11 line 2 ("reserve it structurally") invited shipping a dead-DOM placeholder for out-of-scope SV-12 | **APPLIED-FAITHFUL** | Brief M2 §3 (`:355-360`) now states the Stage-1 rendering contract explicitly: "render ONLY the cash-sales row. No rounding placeholder, no reserved DOM slot, no empty container, no `display:none` sibling... Do not compute or display a rounding component here, and do not pre-build the shape that would hold one." `sv-stage1.progress.yaml` M2 title mirrors it verbatim ("LINE 2 Stage-1 rendering contract: render ONLY the cash-sales row, NO rounding placeholder and NO reserved DOM slot"). |

## 3. Fresh-eye pass

Re-read both dispatch briefs end to end and both progress YAMLs in full, independent of the round-1
finding list, looking specifically at the regions the fix round touched (harness-gate wiring, R-2/R-3
rewrites, ES M4's three contracts, the M3/M3b split, R-4's permission lock, SV M5's lens set, the
Arabic ticket, SV-11 line 2) plus their immediate neighbors for collateral damage.

**Fresh-eye result: NONE.** No new findings. The round-1 "not re-opened" set (scope fences, the
nine-row/four-row scope tables, evidence contracts, the A0 exit rule, red-run obligations, R-1's Z-arm
ruling, R-5's Arabic finding, R-6's sequencing resolution, the SV-1 rider set) is untouched by the fix
commit and remains sound.

## 4. Mechanical checks

- `docs/handoff/progress/es-wave-a0.progress.yaml` — parses as valid YAML; 8 milestones (M0, M1, M2,
  M3, M3b, M4, M5 present as documented); no milestone-level `owner_gate:` field.
- `docs/handoff/progress/sv-stage1.progress.yaml` — parses as valid YAML; 6 milestones (M0-M5); no
  milestone-level `owner_gate:` field; M5 `review_lenses` includes `treasury`.
- Both `base_sha:` fields remain `null` (pin-at-dispatch, unchanged by this gate — the single open item
  the round-1 disposition flagged).

## 5. Disposition

Both packages are **DISPATCH-READY**. The only remaining open item before Codex Desktop dispatch is
pinning `base_sha` in each progress YAML to the dev tip at dispatch time, per each file's existing
pin-at-dispatch instructions.

**BRIEF GATE ROUND 2: PASS**
