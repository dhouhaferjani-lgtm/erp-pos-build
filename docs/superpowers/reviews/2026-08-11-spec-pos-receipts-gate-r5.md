# POS receipts/reporting spec adversarial gate — round 5

## 1. Gate verdict — PASS

r5 closes both round-4 findings. The six omitted legal non-TRAINING mixed cases are now explicit, follow the frozen training-axis rules, and are matched by binding BT-1 data-provider coverage. The revision history now names the r4 partial result and the five-versus-six miscount honestly. The exact r5 edit record is confined to the claimed table, BT-1, history/header, §9.4, and appendix-pointer changes; no new defect was found.

## 2. Verification

| Check | Verdict | Evidence and conclusion |
|---|---|---|
| **R4-1 — six table cases present** | **PASS** | §3.a contains exactly six r5 rows: `SALE+REFUND`, `SALE+VOID`, and `SALE+REFUND+VOID`, each with absent/false and true `include_training` (`SPEC:188-193`). All return 200 and preserve exactly the explicit code set. |
| **R4-1 — semantics under frozen rules** | **PASS** | None of the three subsets contains `TRAINING`, so the cross-field 422 rule cannot fire; all members satisfy the enum domain and `min:1`; explicit arrays are honoured verbatim (`SPEC:168-171`). N-03 enforces `(invoice_type_code === 'TRAINING') ⟺ training_flag` (`FiscalPayloadConstraintValidator.php:901-914`), so `training_flag=false` excludes no SALE/REFUND/VOID row. Dropping that vacuous predicate under `include_training=true` leaves the same bounded row set and therefore the same response body. The migration and projector still support the invariant's physical representation (`2026_05_20_120000_add_invoice_type_code_and_training_flag_to_pos_receipts.php:15-45`; `PosCoreReceiptProjection.php:395,405-406`). |
| **R4-1 — achieved coverage claim** | **PASS** | The table header now states all 15 non-empty subsets of the four-code domain under both toggle states, plus absent, empty, and out-of-domain cases (`SPEC:174`). The explicit/grouped rows at `SPEC:178-197` cover those categories without an uncovered subset. |
| **R4-1 — BT-1 consistency** | **PASS** | BT-1 now requires all three mixed subsets twice, asserts 200 and the exact requested code set for every case, and compares each true response body byte-for-byte with its absent/false twin (`SPEC:670`). This matches the six table rows and the §3.a enforcement pointer (`SPEC:199`). |
| **R4-2 and revision-history honesty** | **PASS** | §9.2 marks N-1/R3-1 “partially at r4” and “completed at r5” (`SPEC:847`); §9.3 withdraws the premature “complete combination table” claim, records gate r4's PARTIAL result, corrects the count to **six**, names the former “five” miscount, and replaces the stale not-re-gated sentence with the actual gate-r4 outcome (`SPEC:872,877`). New §9.4 accurately records R4-1/R4-2 and the r5 scope (`SPEC:879-888`); the header/status and appendix pointer are also current (`SPEC:3-5,905`). |
| **r4 → r5 scope confinement** | **PASS** | The spec is untracked, so Git has no r4 blob. The actual r5 fix-session edit record supplies the concrete delta: 11 `Edit` operations, all targeting this spec, covering only the table header/six rows, §3.a enforcement line, BT-1, revision header/status, §9.2/§9.3 honesty markers, new §9.4, and the gate-r4 appendix pointer. Its only shell calls were read-only checks. Those operations map exactly to the four scope groups declared at `SPEC:888`; R3-2, R3-3, OI-17, the open-items register, and the r3-PASS sections were not edited. |
| **New-defect scan** | **PASS** | The new rows introduce no new validation rule, screen behavior, permission decision, or source claim. Their outcomes are direct consequences of rules 1-4 and N-03, and the table, BT-1, enforcement pointer, history, and scope statement agree. |

## 3. New defects

None.

## 4. Build readiness

**PASS — the spec is build-ready pending owner OI-1, OI-3, OI-13, OI-14 (sidebar), OI-16, and OI-17.**
