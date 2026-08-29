<!-- Codex CLI read-only adversarial gate, round 7 (closing pre-merge round), Session H orchestrator 2026-08-29; brief r7 at 29460add3. -->

# Round-7 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — revision r7  
**HEAD:** `29460add3ad5252ebd668f3e37afac03c950ce29`

## Residual resolution

| Finding | Status | Resolution and code evidence |
|---|---|---|
| N-33 | RESOLVED | The policy target now explicitly uses `PartnerTaxStatusValues::NON_REGISTERED` (`brief:382,417-420`), while the shared contract is specified with named constants and a parity-pinned `VALUES` list (`:445-453`). Fresh real-path grep shows the only present Partner→Taxation references are the acknowledged existing debt in `apps/api/app/Modules/Partner/Domain/Partner.php:15,158,375,399`; the not-yet-created policy is no longer instructed to add one. |
| N-22 | RESOLVED | The brief requires a typed full mutation envelope through raw `api.patch` (`brief:706-710`), necessary because current `apps/web/src/lib/api.ts:397-399` discards response metadata. The sole transition assertion is now `meta.cleared_fields` non-empty plus dialog ⊆ server (`brief:711-729`); fresh grep found no inverse-subset or equality requirement. |

## NEW findings

None. The r7 delta contains only the two residual corrections and revision metadata. `git diff --check` is clean, and the fresh `tax_status` census across the real POS mirror, fiscal validator/service, and POS sealed/offline paths returned zero hits.

## Conditions for the post-merge final round

1. Merge Phase 1 first, then re-gate against the resulting `dev` tip.
2. Re-pin `base_sha`, both migration timestamps, and every Phase-1-moved code anchor.
3. Parent must create `docs/handoff/progress/session-h-phase2.progress.yaml` with the pinned base before dispatch.
4. If OQ10 remains unruled, apply accepted default (a) and record the deviation and affected-row counts.
5. Retain the recorded M4 resolution order; obtain an owner exception and STOP only if neither workbook-verification branch is feasible.
6. Re-run the sealed-path `tax_status` census and the complete M1–M5 implementation gates during the post-merge round.

## Disputes

None.

VERDICT: ACCEPT-WITH-CONDITIONS
