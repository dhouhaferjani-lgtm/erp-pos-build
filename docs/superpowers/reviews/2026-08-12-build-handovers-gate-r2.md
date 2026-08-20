# Build handovers final gate — round 2

## Verdicts

### `CODEX-DISPATCH-receipts-build-2026-08-12.md` — FIX-FIRST

The r3 brief genuinely resolves R-1, R-2 and R-3, and its autonomous-wave banner, milestone/lens mapping, three harness STOP classes, terminal-audit ownership and never-merge/never-push contract remain intact. The dispatch package is not ready, however, because `progress/receipts-build.progress.yaml:36` retains the pre-R-1 blanket prohibition on reading `product.unitOfMeasure`. That YAML entry is an operative owner-gate/STOP instruction and directly conflicts with the brief's required S-4 `lines.product.unitOfMeasure` eager-load and `decimal_places`-only enrichment (`brief:121`). An executor reading the YAML first could stop on work the brief requires.

### `CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md` — FIX-FIRST

The r3 brief genuinely resolves D-1 through D-4, and the DN YAML's operative OI-8 strings now correctly say conditions 1–4 are ratified while 5–7 are proposals (`progress/dn-consolidation-build.progress.yaml:51-52,66,98`). The package is still not dispatch-ready because the brief twice asserts that the YAML retains the obsolete “1–7 binding” wording and tells the executor not to align it (`brief:151-156,340-343`), contradicting the actual YAML. The M0 review contract is also internally inconsistent: the banner requires one bridge call per milestone and the YAML assigns M0 a review lens, while the milestone table says M0 receives “no review” (`brief:9-20,108`; YAML `:57-64`).

## Round-1 finding verification

| ID | Result | Verification |
|---|---|---|
| **R-1** | **RESOLVED IN R3 TEXT** | Receipts §3 now scopes the mutable-master prohibition to deriving/rendering the unit symbol or code, expressly requires S-4's `lines.product.unitOfMeasure` eager-load, reads only `decimal_places`, preserves fallback 4, and requires the relation to be unset before serialization (`brief:121`). This matches the gated spec's deliberate `quantity_decimals` enrichment and S-4 (`SPEC-pos-receipts-reporting…:327,433`). The receipts-YAML regression is a new package-consistency defect, N-1 below. |
| **R-2** | **RESOLVED** | The receipts dossier guard expressly excludes ES-31 and names the DN-consolidation build as owner (`receipts brief:94`). The DN brief claims ES-31 and excludes every other event-register row (`DN brief:70,95`). The cited handover independently assigns ES-31 to DN at `HANDOVER-event-sourcing-remediation…:68,94`. |
| **R-3** | **RESOLVED** | The Lane A0 row now uses repo-valid paths and cites the supporting A0 lane membership, exit deliverables and ES-07 entry (`receipts brief:92`; handover `:58,75,161`). Both findings paths are fully qualified (`receipts brief:94,134`), and all cited files exist. |
| **D-1** | **RESOLVED IN R3 TEXT AND OPERATIVE YAML** | DN §3 limits the binding set to conditions 1–4 and labels 5–7 “PROPOSED, NOT RATIFIED” (`brief:129-149`); M5 and report item 4 preserve that split (`:113,288`). The owner decision at `OWNER-DECISIONS…:90` names only conditions 1–4, while research 17 remains “owner rulings owed.” The actual YAML is aligned at `:51-52,66,98`. The brief's stale description of that YAML is new defect N-2. |
| **D-2** | **RESOLVED** | Condition 6 is explicitly outside this build; the executor is forbidden to design a trace, must stop if the build appears to require one, and the parent must reissue a brief freezing the mechanism, exact write/read/test contract and scoped exceptions before implementation (`DN brief:144`). |
| **D-3** | **RESOLVED** | DN §6 supplies one paste-ready repo-root invocation with both path variables populated (`brief:236-242`), maps those paths to §6.1–§6.3 including `DeliveryNoteConsolidationConcurrencyTest` (`:244-246`), fixes View B placement under `src/features/documents/delivery-notes/`, and requires extending the invocation when tests land elsewhere (`:247-248`). The named paths exist; `scripts/preflight.sh:79-82,197-203` confirms the variables drive scoped PHPUnit and Vitest runs. `PREFLIGHT_SCOPE=full` remains prohibited. |
| **D-4** | **RESOLVED** | The OP-13 scope row now cites `docs/handoff/FINDINGS-other-problems-2026-08-11.md` (`DN brief:90`), and the path exists. |

## New defects

| ID | Brief | Defect | Required correction |
|---|---|---|---|
| **N-1** | Receipts + YAML | `receipts-build.progress.yaml:36` says not to read `product.unitOfMeasure` and makes any apparent need a STOP. The r3 brief instead requires reading `product.unitOfMeasure.decimal_places` for S-4 (`brief:121`). Because the YAML is the executor's first-read resume/owner-gate record, this revives R-1 operationally even though the brief text is fixed. | Align the YAML gate with §3: prohibit only deriving/rendering unit symbol/code and reading `pos_receipt_lines.unit`; expressly allow and require the `decimal_places`-only `quantity_decimals` enrichment with fallback 4. |
| **N-2** | DN | The brief's “Precedence over the YAML” block and revision-log residual say the YAML still marks conditions 1–7 binding and instruct the executor not to correct it (`brief:151-156,340-343`). The actual YAML already has the intended 1–4/5–7 split. This makes a now-correct brief/YAML pair describe itself as inconsistent and undermines the resume source of truth. | Remove the obsolete precedence/residual text or replace it with an affirmative statement that the YAML is aligned; retain §3 as the ruling of record without claiming a nonexistent mismatch. |
| **N-3** | DN + YAML | The execution banner says the bridge runs once per milestone and includes M0 in the milestone map (`brief:9-20`); the YAML gives M0 `review_lenses: [general]` (`YAML:57-64`); but the milestone table says M0 has “No implementation, no review” (`brief:108`). An executor cannot tell whether M0 must produce an adversarial register before M1. | Choose and state one M0 contract consistently. If M0 is setup-only, add an explicit banner/harness exception and remove its review lens; if it is self-gated, remove “no review” and specify the M0 register/ACCEPT transition. |

## Cross-brief and self-containment checks

- **Seeder ownership:** consistent. Receipts owns the single three-key accountant edit and generated permission map; DN forbids editing that block and stops if `deliveries.view` is unavailable or the branches collide (`receipts brief:122,290`; DN brief `:96,185,311`; both YAMLs agree).
- **ES-31 ownership:** consistent. Receipts excludes it; DN owns it; the event-sourcing handover corroborates that assignment.
- **Phase series:** consistent and non-overlapping. Receipts uses `Phase 1.<wave>.<seq>` and its YAML matches; DN uses `Phase 2.<milestone>.<seq>` and its YAML matches (`receipts brief:187`, YAML `:20-21`; DN brief `:206`, YAML `:20-21`).
- **Autonomous-wave core:** milestone lens sets M1 onward match their YAMLs; scoped self-gates, max five fix rounds, fail-closed tool errors, the three harness STOP classes, resume state, parent terminal audit, and executor never-merge/never-push rules are otherwise preserved.
- **Self-contained execution:** the read orders, normative-spec precedence, scope boundaries, commands, evidence contracts and ownership rules are sufficient without conversation context. N-1 through N-3 are the remaining contradictions that prevent that self-containment from being reliable.

