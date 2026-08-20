# Build handovers delta consistency gate — round 1

## Gate verdicts

### `CODEX-DISPATCH-receipts-build-2026-08-12.md` — FIX-FIRST

The post-gate owner closures, three-key accountant grant, currency rules, OP-23 supersession, phase series, and requested code spot-checks are otherwise faithful, but the brief is not dispatch-ready: its OI-17 wording contradicts the spec's deliberate `quantity_decimals` enrichment, and its blanket event-dossier exclusion collides with the DN brief's explicit ES-31 ownership. Its Lane A0 source pointer also does not resolve to supporting lines.

### `CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md` — FIX-FIRST

The OI-8 REFUSE ruling and its four owner-ratified UI conditions, OI-10/OI-14 acknowledgment, seeder prohibition, staged closures, phase series, and `/delivery-notes` gate claim are otherwise faithful, but the brief silently promotes three additional research recommendations to binding status. An executor would also have to design the proposed durable losing-claim trace and invent the scoped preflight path sets. One cited handoff path is unresolved as written.

## Findings

| ID | Brief | Finding | Required correction |
|---|---|---|---|
| R-1 | Receipts | §3 says both that quantities use `quantity_decimals` and that the build must not read present-day `product.unitOfMeasure`. The spec explicitly defines `quantity_decimals` as its one deliberate current-product enrichment and requires eager-loading `lines.product.unitOfMeasure` (`SPEC-pos-receipts-reporting…` §3.b.5(iii), lines 327–328; S-4, line 433). The unqualified prohibition therefore contradicts the normative S-4 contract. | Narrow the prohibition to deriving/rendering the **unit symbol/code** from current product data; explicitly preserve the spec's `quantity_decimals` enrichment and fallback. |
| R-2 | Receipts / cross-brief | Receipts §1 says anything in the event-sourcing dossiers belongs to the fixes session, while the DN brief read order and scope explicitly say DN owns ES-31. The cited event-sourcing handover itself assigns ES-31 to the DN build (`HANDOVER-event-sourcing-remediation…` §3 lines 68, 94). This makes one brief's DO-NOT-TOUCH list overlap the other's scope. | Exclude ES-31 from the receipts blanket statement and name the DN-consolidation build as its owner. |
| R-3 | Receipts | The Lane A0 citation `HANDOVER-event-sourcing-remediation-2026-08-11.md:50,119` is both root-relative to a nonexistent file and substantively wrong: current lines 50 and 119 concern Lane B context and ES-31/ES-42, not ES-07/A0. The same scope row also uses unresolved root-relative `FINDINGS-*.md` paths. | Use repo-valid `docs/handoff/...` paths and cite the actual A0/ES-07 supporting section/lines; fully qualify both findings documents. |
| D-1 | DN | §3 promotes research 17 conditions 5–7 (queue invalidation, durable trace, no client auto-retry) to **equally binding**. The ruling of record ratifies REFUSE and names only conditions 1–4 (`OWNER-DECISIONS…` line 90); research 17 still labels itself “owner rulings owed.” This silently expands the owner closure. | Cite an owner ratification for conditions 5–7, or stop calling them binding and return them to the owner/parent as proposed additions. |
| D-2 | DN | If condition 6 is ratified, it requires a durable trace of who attempted, which DNs were involved, and which lane won, but neither the gated spec nor the brief freezes a persistence surface, payload, transaction boundary, retention/query surface, permission contract, or acceptance test. The existing 422 is expressly insufficient, while the spec expects rolled-back losses to leave zero success audit records. An executor must invent a cross-cutting audit design. | After ratification, freeze the trace mechanism and its exact write/read/test contract, with any necessary scoped exception to the spec's no-new-events/no-unspecified-surface rules. |
| D-3 | DN | §6 requires `PREFLIGHT_TEST_PATHS` and `PREFLIGHT_VITEST_PATHS` but supplies neither values nor a paste-ready command. The DN spec also contains only a bare `./scripts/preflight.sh`; therefore the executor cannot run the required scoped preflight without inventing the path sets. | Add one exact repo-root preflight invocation with both variables populated and consistent with all §6.1–§6.3 tests, including the concurrency test, while retaining `PREFLIGHT_SCOPE != full`. |
| D-4 | DN | The scope guard cites bare `FINDINGS-other-problems-2026-08-11.md`, which does not exist at the repo root. | Replace it with `docs/handoff/FINDINGS-other-problems-2026-08-11.md`. |

Code spot-checks passed: the three named overloads default to EUR; `apps/web/src/lib/format.ts` defaults to active-company currency; `/delivery-notes` and its detail route currently use `moduleKey="inventory"`; the fraud routes currently use `moduleKey="settings"`; and the lane-separation backend uses `reports.financial`. The ev5 unit lane and Rafiq editor directory remain outside both build scopes.
