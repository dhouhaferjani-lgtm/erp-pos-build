# Build handovers gate — round 3

Scope: this round verifies only round-2 findings N-1, N-2 and N-3, the UI `commit_series` fill, and package consistency caused by those corrections. It does not re-open findings resolved in rounds 1–2 or any gated specification.

## 1. Verdict per lane

| Lane | Verdict | Grounding |
|---|---|---|
| POS receipts | **DISPATCH-READY** | The brief's OI-17 ruling requires the decimal-places-only S-4 enrichment while excluding unit-label derivation (`docs/handoff/CODEX-DISPATCH-receipts-build-2026-08-12.md:124`), and the operative YAML gate now says the same (`docs/handoff/progress/receipts-build.progress.yaml:35-38`). Its M0 banner exception and YAML record also agree (`brief:19-22`; YAML `:53-60`). |
| DN consolidation | **DISPATCH-READY** | The brief and YAML agree that OI-8 conditions 1–4 are ratified and 5–7 remain proposals (`docs/handoff/CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md:132-158,342-344`; `docs/handoff/progress/dn-consolidation-build.progress.yaml:51-54,65-67,97-99`). M0 is consistently setup-only in the banner, milestone table and YAML (`brief:20-23,109-112`; YAML `:57-64`). |
| UI Wave 0 | **DISPATCH-READY** | The brief makes a null `commit_series` the pre-dispatch stop and directs M0 to consume the parent's YAML value (`docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:58-60,648-653,740,763-764`); the YAML now supplies `Phase 0.<task#>.<seq>` (`docs/handoff/progress/ui-wave0.progress.yaml:22-26`). Its M0 contract matches the other two lanes (`brief:23-26`; YAML `:32-39`). |

## 2. Per-fix verification

### N-1 — RESOLVED

- The receipts brief scopes the prohibition to deriving or rendering a present-day unit symbol/code and separately forbids reading `pos_receipt_lines.unit`. In the same OI-17 row it expressly requires S-4 to eager-load `lines.product.unitOfMeasure`, read `decimal_places` only, fall back to 4 when the product/relation/FK is absent, and unset the relation before serialization (`docs/handoff/CODEX-DISPATCH-receipts-build-2026-08-12.md:124`).
- The YAML's `ev5-unit-lane` gate now mirrors that split: no unit symbol/code and no `pos_receipt_lines.unit`, but the `quantity_decimals` enrichment from `product.unitOfMeasure.decimal_places` is “EXPRESSLY ALLOWED AND REQUIRED,” with fallback 4 and relation removal before serialization (`docs/handoff/progress/receipts-build.progress.yaml:35-38`).
- Result: the YAML no longer turns the brief-required precision enrichment into an owner STOP. N-1 is closed.

### N-2 — RESOLVED

- The DN brief states that only conditions 1–4 are binding (`docs/handoff/CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md:132-137`) and labels conditions 5–7 “PROPOSED, NOT RATIFIED” (`:139-148`).
- The former mismatch/precedence instruction has been replaced by an affirmative alignment block: the YAML carries the same split, the brief and YAML agree, and the executor must not rewrite the aligned YAML (`:154-158`). The revision-log residual likewise now says the parent corrected the YAML and that no executor action remains (`:342-344`).
- The YAML agrees at every operative repetition: the OI-8 owner-gate entry says 1–4 ratified / 5–7 proposed (`docs/handoff/progress/dn-consolidation-build.progress.yaml:51-54`), M1 repeats that split (`:65-67`), and M5 requests evidence for 1–4 plus the recorded proposal status of 5–7 (`:97-99`).
- Result: there is no remaining brief/YAML precedence conflict. N-2 is closed.

### N-3 — RESOLVED

- Receipts: the banner names the exception—M0 is setup-only, has no bridge call or register, passes by recording `base_sha`, and M1 owns the first bridge call (`docs/handoff/CODEX-DISPATCH-receipts-build-2026-08-12.md:19-22`). The YAML M0 title says `SETUP-ONLY`/`NO bridge review`, defines the same completion, and has `review_lenses: []` (`docs/handoff/progress/receipts-build.progress.yaml:53-60`).
- DN: the banner carries the identical M0 exception (`docs/handoff/CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md:20-23`); the milestone table says “No implementation, no review” (`:109-112`); and YAML M0 says setup-only/no bridge/no register with `review_lenses: []` (`docs/handoff/progress/dn-consolidation-build.progress.yaml:57-64`).
- UI Wave 0: the banner carries the identical M0 exception (`docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:23-26`), and YAML M0 says setup-only/no bridge/no register with `review_lenses: []` (`docs/handoff/progress/ui-wave0.progress.yaml:32-39`).
- The briefs' general bridge rules are immediately qualified by these explicit, named M0 exceptions. M1 is unambiguously the first reviewed milestone in all three lanes. N-3 is closed.

### UI `commit_series` — RESOLVED

- The UI brief defines F-6 as a pre-dispatch fill: the parent writes `commit_series` into the YAML; only a still-null value at M0 causes `blocked_precondition` (`docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md:58-60,648-653,763-764`). The report must quote that YAML value (`:740`).
- The YAML now contains the ratified value `commit_series: "Phase 0.<task#>.<seq>"` (`docs/handoff/progress/ui-wave0.progress.yaml:22-26`). M0 checks that the field is filled and stops only if it is null (`:32-39`); the F-6 owner-gate record repeats that conditional contract (`:117-120`).
- The older prose describing F-6 as awaiting a parent answer (`brief:652,793`) does not create a live blocker: the immediately following r4 mechanics make the YAML the answer source (`:653`), and that source is now non-null.
- Result: the dead-on-arrival precondition is removed without authorizing the executor to invent a series.

## 3. New defects

None. The four corrections introduce no new operative contradiction across the three brief/YAML pairs.

## 4. Dispatch authorization

All three lanes are dispatch-ready. The owner may paste all three into Codex Desktop as autonomous self-reviewing waves.
