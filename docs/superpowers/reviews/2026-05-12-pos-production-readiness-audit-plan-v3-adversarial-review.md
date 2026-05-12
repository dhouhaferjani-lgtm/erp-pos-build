# Third Adversarial Review: POS Production-Readiness Audit Plan v3

Plan reviewed: `docs/superpowers/plans/2026-05-12-pos-production-readiness-audit-plan-v3.md`

Prior reviews:
- r1: `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-plan-adversarial-review.md`
- r2: `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-plan-v2-adversarial-review.md`

Scope: verified v3 against every r2 P1, spot-checked corrected anchors in the worktree, and checked the cited memory-note excerpts against `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/`.

## Round-2 P1 Closure

- [P2] No r2 `[P1]` remains as a production-readiness blocker. v3 addresses the strong `tauri-plugin-sentry` pre-recommendation, Phase 0 target-device prerequisite, dated Tunisia legal-source packet, stale `CashDrawerService` path, Tauri/Playwright testing ambiguity, Otospex-vs-IziPOS deferred-item confusion, performance harness commit boundary, crash-injection mechanism, shared §16 fixture contract, Phase 1 audit-setup findings, per-phase artefact contracts, target-device supportability, and memory-note quote discipline.

- [NIT] The review trail is now clean enough for Codex execution. The remaining items below are precision edits, not reasons to hold the audit plan.

## §0 / Execution Model

- [P2] Phase 0 is correctly first and captures the right device attributes, but the Phase 0 acceptance gate omits §2 even though §0 says every later section depends on the target device and §2 has OS/appdata/security implications. Current text says, "Otherwise §1, §3, §11, §13, §14 cannot proceed" (`v3.md:737-738`). Change this to include §2, or explicitly state §2 may proceed device-agnostically with OS-specific follow-ups after Phase 0.

## §1 Observability & Telemetry

- [NIT] The Sentry matrix is now balanced enough. It keeps `tauri-plugin-sentry` as an experimental option, makes Browser SDK + Rust SDK separately the conservative baseline, and requires pricing/region/retention/PII verification at audit time. No remaining issue.

## §5 Tunisia Compliance Matrix

- [NIT] The dated legal-source requirement is now explicit and actionable. The matrix requires URL/title/access date/owner for each regulatory row and makes unresolved legal gaps severity-tagged findings. No remaining issue.

## §10 / §11 / §12 / §13 Memory Notes

- [P2] One memory-note excerpt is mislabeled as a verified quote. `v3.md:558-560` says:
  `Canonical spelling: "Otospex" (NOT "OtospEx" or variants). Theme: pink. Tunisia deployment.`
  The source note instead says `**Otospex** (capital O, lowercase rest -- not "otospex", not "Otospexx", not "Otospexsolutions")...`, and separately says `Theme: pink. IziPOS is the sibling retail product (copper theme).` This is an accurate summary, but not a verbatim quote. Relabel it as "Summary of `project_otospex_brand.md`" or replace with the exact source wording.

- [NIT] The other checked memory excerpts are materially correct and sufficiently quote-like for implementation use:
  `feedback_usePermissions_hardcoded_map.md`, `project_discount_permissions.md`, `feedback_modal_fixed_size.md`, `project_otospex_pos.md`, `project_refund_flow_phases.md`, and `project_pos_performance.md`. v3 also correctly labels the POS permission sentence as an audit assertion rather than memory-note content.

## §12 Multi-Vertical

- [NIT] The Otospex vs IziPOS distinction is fixed. v3 separates `Vehicle selection in POS receipt flow` as an Otospex critical MVP gap from `Customer lookup by phone / email / loyalty card at till` as a standard-retail IziPOS Phase 1 deferred item (`v3.md:551-556`). No remaining issue.

## §13 / §14 / §16 Measurement And Artefact Contracts

- [NIT] The per-phase artefact contracts are concrete enough for Codex as implementer. Phase 3 defines retained vs removed harness code, §14 defines explicit fault-injection mechanisms instead of random process kills, and §16 defines shared fixture files plus per-flow deliverables. No remaining issue.

## Structural / Precision Drift

- [NIT] Spot-checked r2 stale/vague anchors now resolve in the worktree: `CashDrawerService.php`, `ActiveMenuController.php`, `productApi.ts`, `refundDraftStore.ts`, `VoucherSyncController.php`, `ZReportSyncController.php`, `EnforceTokenTenantClaim.php`, and `apps/pos/src/lib/i18n.ts`.

- [NIT] The plan still uses "CI green on the audit branch" as Phase 3 acceptance, which is fine if Codex treats audit harnesses as branch-local scaffolding. No edit required, but Codex should avoid interpreting this as permission to ship temporary app instrumentation to `dev`.

APPROVE-WITH-MINOR-EDITS
