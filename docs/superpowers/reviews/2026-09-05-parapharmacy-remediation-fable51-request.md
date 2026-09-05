# Fable 5.1 adversarial design review request

You are the independent adversarial reviewer requested by the repository owner. Review the proposed design, not an implementation. Do not modify files, run application commands, create commits, implement fixes, deploy, or request that another model review on your behalf. Read-only file tools are sufficient. Do not read secrets, environment files or unrelated personal data.

Requested model: `claude-fable-5-1`. No fallback model is authorized. The caller will retain actual model metadata separately. Do not infer model identity from this prompt.

Repository: `/Users/houssamr/Projects/syneriva/apps/erp`.
Primary input: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md`.
Audit context: `docs/sessions/2026-09-05-parapharmacy-readiness-audit.md`.
Read `CLAUDE.md`, the relevant architecture guidance and conventions 09–11 as needed. The owner's instructions take priority over skill process: this turn is ONE review and must stop, without implementation or fixing loops.

Owner clarification: the POS is intentionally offline-first and meant for fiscal-register use cases such as NF525/NACEF. Web sales documents are B2B, potentially offline-capable in the future. Tunisia is confirmed; one legal company, checkout surface selection and lot-control expectations remain proposed assumptions. The owner asked for fixing measures in a spec (or spec+plan), an adversarial review specifically by Fable 5.1, then a stop. Do not misclassify local-first POS sealing as a defect or invent regulatory requirements for lot capture.

Review the full spec and investigate the cited current source. Treat the previous audit as a hypothesis, not authority. In particular try to disprove findings before endorsing their fixes. This is a program-level spec, explicitly not a task-level execution plan; assess whether its decomposition and decision gates are appropriate, rather than requiring implementation code for every proposed package.

Probe at least:

1. W1: existing intended company-global treasury authority versus branch scope; direct/detail/list/transfer bypass; restricted versus central destinations; permission assignment and data leakage. Does the proposed permission/setting duplicate an existing concept?
2. W2: classify real tender semantics without breaking cheques/vouchers/customer credit; record destination before disconnection; mixed-version old outboxes, changed mappings, frozen repositories and honest card clearing. Can a safe simple correction replace an overbroad new field/protocol?
3. W3: synchronously moving B2B accounting into the transaction, all InvoicePosted consumers, prepayments/periods, hash chain/source uniqueness, lock-order interactions and recovery of already sealed documents. Does the proposed atomicity actually cover all mandatory evidence?
4. W4: source+obligation durability, registry/config failures, Redis publication loss, worker crash after partial completion, scheduler tenant context, quarantines, stale running rows, optional module lifecycle and policy history. Can recovery double effects or authorize invalid ones?
5. W5/W6: exact lot count reconciliation, current count-as-of/replay rules, late offline activity, local receipt+inventory evidence transaction, old versus new clients, mismatched/forged evidence, lot allocation shortages and refund provenance. Is a separate evidence stream worth the complexity? Is the shelf-control alternative defensible and honestly labeled?
6. W7: actual existing operational_event_range, sequence namespaces and completeness; multiple streams/terminals/shared drawers; account collections/back-office cash; late arrivals versus post-close authoring; gross/net/refund/VAT/rounding semantics; immutable Z versus derived reconciliation.
7. W8: additive rollout and rollback, backfills without invented history, realistic release gates, accounting/tenant/branch data meaning and tests skipped under SQLite.
8. Architecture: unnecessary work, duplicated sources of truth, risks shifted into new queues/tables/policies, omissions in baseline guarantees and unresolved decisions falsely presented as implementation-ready.

Return a concise but substantive review with:
- Findings ordered by severity. Each has a spec line reference, verified source file:line where relevant, a concrete failure scenario, minimum necessary correction, and whether it blocks spec approval or a later execution plan.
- Distinguish CONFIRMED from PLAUSIBLE/UNVERIFIED; disclose areas you could not verify. Do not claim tests ran.
- Counterevidence/rejected false positives, sound parts worth preserving and any packages that should be simplified or split.
- A short list of owner decisions required before planning/execution.
- Your final line must be exactly `VERDICT: ACCEPT-FOR-OWNER-REVIEW` or `VERDICT: CHANGES-REQUIRED`.

Do not produce a patch or implementation. Stop after the review. The caller must not fix review findings in this turn.
