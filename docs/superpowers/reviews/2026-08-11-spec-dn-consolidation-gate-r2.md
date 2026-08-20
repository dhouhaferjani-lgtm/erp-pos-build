# DN-consolidation spec — adversarial gate round 2 (verdict recovered from job log)

> **Provenance note:** the Codex reviewer completed this round but its sandbox rejected the report write; this file was reconstructed by the orchestrator from the run's final output (job `task-msp3bcqn-etgjc8`, 2026-08-11T20:12Z). The per-defect narrative detail beyond the summary below was not persisted; the r3 fix round works from these verdict bullets plus the r1 review.

## 1. Gate verdict: FAIL

Defects **3, 5, 6, 7, 10, 11, 12 — VERIFIED** as applied. Defects **1, 2, 4, 8, 9 — PARTIAL**; the r2 revision log overstates those five fixes.

## 2. The five partial defects — what remains

| Defect | Residual |
|---|---|
| 1 (claim-before-create) | **Claim-before-create is incompatible with the target invoice FK** as written: the claim row references the invoice before the invoice exists. The spec must specify either a reservation/finalization two-step (claim with NULL invoice ref, finalize after create) or a deferred FK with a **preallocated invoice UUID**. |
| 2 (lock inventory) | **The SO auto-created-DN branch runs before the transaction** and is missing from the lock inventory — the published global lock order doesn't cover it. |
| 4 (claim service/DTO) | **`DeliveryNoteBillingState` inconsistently includes `invoice_number`** — contradicts the r2 statement that `fromPayload()` cannot produce the resolved invoice number. |
| 8 (generated DTO consumption) | Tied to the defect-4 inconsistency (the DTO section contradicts itself). |
| 9 (concurrency test barrier) | **The test barrier assumes an invoice numbering-sequence row exists, but source confirms it is created lazily** — the deterministic barrier as spec'd would not block on first use. A different (or explicitly seeded) barrier is required. |

## 3. Requirement

Fix the five residuals in the spec (r3), restate the revision log honestly, then re-gate.
