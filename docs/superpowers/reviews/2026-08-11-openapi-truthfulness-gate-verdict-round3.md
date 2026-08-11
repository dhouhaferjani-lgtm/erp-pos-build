---
# Round-3 independent truthfulness gate — verdict of record
# Gate run 2026-08-11 by independent Codex reviewer (read-only) against codex/openapi-contract-a-to-z @ 5ae9e1351.
# Recovered from the Codex thread after the sandbox blocked the reviewer's own file write (registered job task-msoupouf-n1fok7).
# Escalation check (orchestrator): brief §3-B fourth-systematic-class clause consulted; handover escalation rule (HANDOVER-openapi-lane-orchestration-2026-08-07.md §2/§3) applies to NUMERIC-CEILING stalls only — truthfulness findings explicitly do not count toward that counter. Fix round authorized with the calibration rule: R3-1 closure REQUIRES a mechanical auditor rule + red tests.
---

# OpenAPI independent truthfulness gate — round 3

- **Branch:** `codex/openapi-contract-a-to-z`
- **Target SHA:** `5ae9e135162550b02b09773239951e6bd35beab5` (`5ae9e1351`)
- **Target worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.openapi`
- **Gate date:** 2026-08-11
- **Round:** 3
- **Review mode:** Read-only. No file in the target worktree was modified.

## Round-2 closure summary

| ID | Verdict | Evidence at branch HEAD |
|---|---|---|
| R2-1 | **CLOSED** | `FiscalEventPayload` has `sequence_number.minimum: 1`, no raw `line_items` requirement, and documents decoded `canonical_bytes` as the authoritative line-item carrier at `tenant-full.json:156430-156599`; runtime constructs the DTO without reading a raw `line_items` sibling and parses `canonicalBytes` in `OutboxIngestor`. |
| R2-2 | **CLOSED** | Numeric branches no longer carry inert `pattern`; the auditor rule is at `StrictSchemaTruthAuditor.php:80-85`, regression tests at `StrictSchemaTruthAuditorTest.php:126-179`, all focused tests pass (15 tests, 26 assertions), and removing the rule in memory makes all three negative cases stop throwing (counterfactual red-proof). |
| R2-3 | **PARTIAL** | The named current spec nodes match runtime nullability, but the required mechanical guard omits directly serialized document-ingestion fields (`extraction`/`error` regressions NOT rejected by either auditor) and silently skips unresolved registered pointers at `SerializedFieldNullabilityAuditor.php:51-54` (fails OPEN when a registered node disappears — proven by live admin `plan.description` removal probe). |
| R2-4 | **CLOSED** | Both admin disk responses use numeric byte fields and unit-suffixed strings at `admin-full.json:1446-1488,2599-2641`, matching `MonitoringService.php:645-660,728-739`. |
| R2-5 | **CLOSED** | `LabelSheetFormatRow` uses the runtime vocabulary at `tenant-full.json:138970-139026`, matching `LabelSheetFormat::toArray()` at `LabelSheetFormat.php:101-115`. |
| R2-6 | **PARTIAL** | The request now admits number and string, but both branches omit runtime `min:0.01` and the string regex admits negatives at `tenant-full.json:55633-55708`, contrary to `MultiPaymentController.php:563-570`. Probes: -1, "-1.000", 0, "0.000" all spec-valid / runtime-rejected. Generator cause: `FullSurfaceRefiner.php:275-296,355-381` uses runtime rules only to validate a positive witness then emits an unbounded number branch + signed precision pattern. |
| R2-7 | **CLOSED** | The three plan fields are nullable at `admin-full.json:4303-4323`, matching `Plan.php:18-25,64-70` and `PlanEnforcementService.php:448-456`; the nullability auditor registers all three admin pointers, is invoked in the all-surface generation loop, and rejected a live non-null mutation at the exact pointer. |

## New round-3 findings

| ID | Severity | Finding | Evidence file:line | Required remedy |
|---|---|---|---|---|
| **R3-1** | **High / SYSTEMATIC** | **Runtime numeric bounds disappear on numeric-string branches — 67 composed schemas (all tenant surface).** A JSON Schema `minimum`/`maximum` on the numeric branch does not constrain the alternate string branch; current string patterns admit numeric values runtime rejects (signed patterns on non-negative fields, no range). The split-validation replacement additionally drops `min:0.01` from the numeric branch itself. Affected set spans money, quantity, percentage, multiplier, duration, and threshold inputs — a systematic contract-generation defect, not isolated schema errors. | `tenant-full.json:120304-120338,120349-120383,134704-134730,55633-55708`; `CreateTierRequest.php:34-42`; `UpdateScheduleConfigRequest.php:24-30`; `MultiPaymentController.php:563-570`; generator cause `FullSurfaceRefiner.php:275-296,355-381`; auditor gap `StrictSchemaTruthAuditor.php:80-85` (no cross-branch bound-parity rule) | Red-first mechanical auditor for runtime-bound parity on number\|string inputs. Restore numeric-branch bounds incl. split `minimum: 0.01`. Encode equivalent string restrictions where representable; otherwise an explicit source-backed runtime-bounds deviation stating the unenforced bounds. Sweep all 67 affected request nodes + the 2 split fields; live current-document mutation tests at exact pointers. |

### R3-1 witnesses (executed probes)
- Schedule: `walk_in_buffer_hours_per_day` — `"-1.00"`/`"25.00"` schema-valid, runtime-rejected (`min:0|max:24` only on number branch).
- Loyalty: `earning_multiplier` — `"0.50"` schema-valid, runtime-rejected (`min:1` only on number branch).
- Split validation: `total_required`/`splits[].amount` — -1, "-1.000", 0, "0.000" all schema-valid, runtime-rejected (`min:0.01` dropped entirely).
- Representative affected nodes (of 67): categories margin overrides, reservation-settings thresholds, variants recipe_multiplier, payment-methods fee_fixed, AddLineRequest labor_hours_estimated, CloseShiftRequest actual_cash, CommitDocumentIngestionRequest lines (freeQuantity/unitPrice/vatRate), Create/UpdatePartnerRequest credit_limit, Create/UpdateProductRequest, Create/UpdateTierRequest, Create/UpdateWithholdingRuleRequest rate, OpenShiftRequest opening_cash, UpdateScheduleConfigRequest.

## Zero-behavior verification — PASS
Fix-round-2 range `e30f4c5bf..5ae9e1351` = single commit `5ae9e1351`. `git diff --name-status` contains only generated OpenAPI JSON artifacts, OpenAPI tooling/auditors, OpenAPI tests, and review documentation. `git diff --name-status e30f4c5bf..5ae9e1351 -- apps/api/app` produced NO output. Worktree clean before and after review.

## Gate conclusion
R2-1, R2-2, R2-4, R2-5, R2-7 closed. R2-3 partial (mechanical auditor incomplete + fails open on missing pointers). R2-6 partial (min:0.01 erased, signed regex). New High/Systematic R3-1 across 67 bounded number|string schemas plus the two split-validation fields.

GATE VERDICT: FAIL

(Note: this is the orchestrator-condensed verdict of record — the detailed per-finding evidence sections were delivered in the Codex thread and are summarized in the tables above with all citations preserved.)
