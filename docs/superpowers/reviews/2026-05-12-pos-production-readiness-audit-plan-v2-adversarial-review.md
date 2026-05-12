# Second Adversarial Review: POS Production-Readiness Audit Plan v2

Plan reviewed: `docs/superpowers/plans/2026-05-12-pos-production-readiness-audit-plan-v2.md`

Prior review: `docs/superpowers/reviews/2026-05-12-pos-production-readiness-audit-plan-adversarial-review.md`

Scope spot-checks covered the v2 plan, the round-1 review, POS/API anchor paths, Sentry/Tauri references, and memory notes under `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/`.

## Prior Round-1 BLOCKER + P1 Closure

- [P2] No round-1 `[BLOCKER]` or `[P1]` finding remains wholly unaddressed. v2 adds first-class sections for Tauri security (§2), build/deploy/manual update (§3), telemetry (§1), Tunisia compliance (§5), measurement harnesses (§11/§13/§14/§16), POS-specific tenant isolation (§15), operational chain-break recovery (§3/§14/out-of-scope clarification), and cross-section flow audits (§16). It also corrects the major stale anchors called out in receipt printing, payment, i18n, and tenant middleware.

- [P1] Some round-1 items are only partially closed because v2 reintroduces precision problems: memory-note "quotes" are not actually quoted and one Otospex/customer-lookup summary is materially off; a cash-drawer service anchor is still stale; §12 still uses ellipsis/vague anchors for `ActiveMenuController.php` and `productApi`; and §1 pre-recommends a community Sentry plugin without enough caveats for production adoption.

## §1. Observability & Telemetry

- [P1] The `tauri-plugin-sentry` recommendation is too strong as a pre-decision. Sentry's own help article says there is no official Tauri SDK and recommends using Browser SDK + Rust SDK separately; the community plugin exists, but docs.rs labels `tauri-plugin-sentry 0.5.0` "experimental" with 0% docs coverage. v2 should keep it in the matrix, but require a maintenance/security check and treat "Browser SDK + Rust SDK separately" as the conservative baseline, not make the plugin the default.

- [P2] The cost/data-residency cells are asserted as if stable. For an audit plan, this should say "verify current pricing, quotas, retention, PII scrubbing, region, and support model at execution time." Otherwise Codex may copy stale SaaS assumptions into the findings.

- [P2] The API anchor `apps/api/.../HandleExceptions` remains vague. The repo has Sentry integration in `apps/api/bootstrap/app.php` and config in `apps/api/config/sentry.php`; v2 should anchor those explicitly alongside `apps/api/config/logging.php`.

## §2. Tauri Device Security & Native Permissions

- [P2] The section is coherent and addresses the round-1 blocker. One missing production-readiness question: the audit should verify whether the local SQLite database itself is encrypted or only selected token material is encrypted. The current threat model asks what is recoverable from SQLite/Tauri Store in §10, but §2's capability proposal should also classify which local data remains plaintext after filesystem access.

## §3. Build, Deploy, Install, Manual Update

- [P1] The section asks "what OS does the TN terminal run?" but the rest of the plan depends on that answer. Make this a Phase 0 prerequisite: target OS/arch, printer model/transport, drawer wiring, screen resolution, network topology, and operator account model must be captured before Codex builds measurement scripts or manual-update runbooks.

- [P2] The backup list includes database sidecars, store, encryption key, and images, which closes the round-1 backup gap. It should also require a restore drill against a copied app-data directory, not just a written procedure; otherwise "manual update preserves data" can pass as prose without evidence.

## §5. Tunisia Compliance Matrix

- [P1] The matrix is a major improvement, but it still does not force a dated legal-source packet. Each row should require `source URL / document title / access date / owner decision`, especially for VAT rates, e-invoicing/Elyssa applicability, fiscal printer requirements, receipt retention, and Z-report cadence. Without that, Codex can fill "TN DGI" as a label and still leave the legal dependency unresolved.

- [P2] "Arabic RTL on printed receipt" is correctly gated as customer requirement, but the production-ready definition should say who decides it for this single TN client. If the client says French-only, the Arabic work becomes documented out-of-scope rather than an open compliance ambiguity.

## §6. Receipt Rendering & Printing

- [P2] The previously stale anchors are fixed. Spot-check confirmed `buildReceiptData.ts`, `printing.ts`, `printerStore.ts`, Rust printing modules, print commands, offline receipt read-path, customer display page, checkout success modal, today sales panel, `ReceiptPrintAuditService.php`, and `SyncController.php` exist.

- [P2] The golden-render harness is actionable, but "React preview" is still not anchored to a concrete preview component. If there is no preview component, say the harness compares `buildReceiptData` output plus rendered success/reprint surfaces to ESC/POS bytes. Otherwise Codex may spend time searching for a nonexistent preview.

## §7. Cash Drawer & Shift Management

- [P1] One anchor is stale: v2 points to `apps/api/app/Modules/POS/Application/Services/CashDrawerService.php`, but the actual service is `apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php`. This is the only concrete missing path found in the main anchor sweep.

- [P2] The smoke flow says "document arithmetic on a paper worksheet." For Codex implementation, require a machine-readable fixture/table in the audit output as well. Paper-only evidence is hard to review and cannot be diffed.

## §8. Payment Flows

- [P2] Round-1 payment anchors are fixed and the manual-card-terminal ambiguity is now explicitly in scope. No new blocker found.

- [P2] The section should add `refundDraftStore` as a direct POS anchor because question 4 names it but the anchor list only points at backend `ReceiptReturnService.php` and `ReceiptVoidService.php`.

## §9. Sync Reliability

- [P2] `VoucherSyncController.php` and `ZReportSyncController.php` exist, but v2 leaves them as `apps/api/.../VoucherSyncController.php`, `ZReportSyncController.php`. Use the exact paths: `apps/api/app/Modules/POS/Presentation/Controllers/VoucherSyncController.php` and `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`.

- [P2] The sync-flow matrix is actionable. It should explicitly require idempotency-key tracing through server logs/DB rows for each failure mode, because §16 depends on proving duplicate-safe replays after app kill and reconnect.

## §10. Authentication, Permissions, Device Threat Model

- [P2] The `feedback_usePermissions_hardcoded_map.md` note is real, but v2 mixes a memory quote with an inference: the note only says the web hook ignores Spatie permission grants; it does not state "POS terminal permissions are checked server-side." Keep the POS sentence, but label it as an audit assertion to verify, not as content from the note.

- [P2] `project_discount_permissions.md` is referenced but not reproduced inline despite v2's claim that memory notes are reproduced inline. Add the concrete facts from the note: terminal `max_discount_percent` default blocks discounts, `discountApi.ts` exists but was never called as of the note, and POS used operator fields instead of the terminal-aware endpoint. Then instruct Codex to reconcile with current code.

## §11. UI/UX & Ergonomics

- [P2] The corrected `apps/pos/src/lib/i18n.ts` anchor and both component trees are good. Spot-check confirmed only `en` and `fr` locales exist.

- [P1] The Playwright instruction is under-specified for a Tauri app. `apps/pos/e2e/` does not currently exist. v2 should say whether Codex should test the Vite web surface, drive the Tauri window through a Tauri-compatible harness, or use Playwright only for browser-rendered UI invariants. Otherwise Phase 3 can get stuck building infrastructure instead of auditing production risk.

## §12. Multi-Vertical

- [P1] The memory-note summary is materially inaccurate: `project_otospex_pos.md` says vehicle selection in POS receipt flow is a critical MVP gap, while `project_refund_flow_phases.md` says customer lookup by phone/email/loyalty is a Phase 1 standard-retail deferred item. v2's "Vehicle/customer lookup deferred" under `project_otospex_pos.md` collapses two different facts and risks treating a Phase 1 IziPOS customer lookup gap as Otospex-deferred work.

- [P2] Two anchors are still vague/stale-looking: `apps/api/.../ActiveMenuController.php` should be `apps/api/app/Modules/Menu/Presentation/Controllers/ActiveMenuController.php`, and `apps/api/.../productApi` should be `apps/pos/src/api/productApi.ts` plus the actual API product controller/routes if server behavior is being audited.

- [P2] "Workshop integration (Otospex, deferred)" is now scoped better than v1, but "audit current stub state" still needs anchors if Codex is expected to verify it. Add `apps/api/app/Modules/Workshop/` if it exists, or state that absence of the module is the expected current state.

## §13. Performance Budget

- [P1] The six-phase model is directionally actionable, but Phase 3 has a hidden implementation decision: temporary `performance.mark` hooks are to be "removed after audit," while all scaffolding is implemented in the audit branch. The plan should specify whether the audit branch keeps harness code committed and removes only app instrumentation, or whether all measurement scaffolding is disposable. Codex needs that boundary to avoid mixing audit evidence with production code churn.

- [P2] The memory-note reconciliation for `project_pos_performance.md` is correct. The actual note says `SyncScheduler` was never instantiated; v2 correctly warns that current code must override stale memory.

## §14. Resilience & Crash Safety

- [P1] The scripted crash list is useful but not yet concrete enough for Codex to implement safely. It names kill points inside transactions (`BEGIN`, insert, chain advance, commit) without saying how to inject those kill points. Add an explicit approach: debug-only fault-injection flags, test-only hooks, or manual instrumentation in the audit branch. Without that, Codex may approximate with random `kill -9`, which will not prove each state boundary.

- [P2] The manual-update preservation check belongs in both §3 and §14. v2 has it, but it should require before/after checksums or row counts for SQLite, Tauri Store keys, image files, and encryption key file.

## §15. Multi-Tenant / Company Isolation

- [P2] The in-scope/out-of-scope boundary is now consistent with the user's constraints: POS-specific leaks are in, broader web-admin sweep is out. No remaining P1 from round 1 here.

## §16. Cross-Section Flow Audits

- [P1] The flow list is good, but several flows require fixtures and seed identities that are not named: a cashier, manager, terminal, company, TND tax rates, vouchers, product/menu data, and admin user for mutation/PIN-change flows. Add a shared "audit fixture contract" before Phase 3 so the eight flows do not each invent incompatible setup.

- [P2] Flow 1 includes "analytics" but gives no anchor. If analytics is in scope for production-ready proof, anchor the report/table/dashboard service. If not, change the flow to end at server receipt/Z-report/fiscal chain.

## Execution Model

- [P1] Phase 1 says it produces no findings and only surfaces gaps in the plan. That is wrong for an audit branch: stale anchors, missing files, and contradictory source notes are findings about audit readiness and should be recorded immediately in an "audit setup findings" section. Otherwise Codex could discover the same stale-anchor issues in Phase 1 and have no formal place to file them.

- [P1] The model is concrete enough for Codex only after adding acceptance criteria per phase. Each phase should state exact outputs: filenames, expected tables, screenshots/log artefacts, command transcripts, and whether harness code remains in the branch. The current six phases are a good skeleton, but Phase 3 through Phase 5 are too large to execute deterministically without these artefact contracts.

- [P2] Section ordering is coherent. Block A foundation first is the right order: observability, Tauri security, and deploy shape inform every later measurement and runbook. The only ordering change I would make is to put the Phase 0 target-device profile before §1 so telemetry/deploy/security decisions are grounded in the real OS/hardware.

## In-Scope / Out-of-Scope Consistency

- [P2] The scope now matches the stated constraints: single TN client, one terminal, single tenant, manual update for v1, in-app updater deferred to v2, NF525 status to be decided rather than assumed, POS tenant isolation in scope, broader web-admin tenant sweep out of scope.

- [NIT] "v2" is overloaded: plan v2, deploy updater v2, and audit sections. Rename the future updater to "client-deploy phase 2 updater" or similar to avoid confusing it with this audit-plan revision.

## Production-Ready Definition

- [P1] Still missing for a single-tenant TN POS deploy: target-device supportability. Add explicit requirements for remote/support access policy, who can retrieve logs, how support authenticates, whether unattended remote access is allowed, and what the operator does when the internet is down and support cannot connect. This is not a broad enterprise concern; it is essential for one physical terminal in Tunisia.

- [P2] Add local clock/time synchronization. Fiscal chains, receipt numbers, Z-reports, shift close, vouchers, and audit logs depend on trustworthy timestamps. The audit should verify NTP/OS clock setup, server-vs-terminal clock drift handling, and what happens if the terminal clock is wrong while offline.

## Memory Notes

- [P1] v2 claims memory-note quotes are inline, but most are summaries, not quotes. `feedback_modal_fixed_size.md` is only referenced by name; `project_discount_permissions.md` is only summarized as "what's built vs what's needed"; `feedback_usePermissions_hardcoded_map.md` has an added POS inference; and `project_otospex_pos.md` is summarized inaccurately as noted above. Fix by either quoting the short relevant excerpt from each note or changing the claim from "quotes inline" to "summaries inline, source path provided."

- [P2] Verified accurate enough: `feedback_modal_fixed_size.md` does require fixed modal sizes; `project_otospex_brand.md` confirms canonical "Otospex" and pink theme; `project_refund_flow_phases.md` confirms customer lookup by phone/email/loyalty is Phase 1 deferred; `project_pos_performance.md` confirms the stale SyncScheduler claim and v2 correctly warns to reconcile against current code.

REQUEST-CHANGES
