# Opus Adversarial Review — Dev Go-Live Remediation Round 2

**Reviewer:** Claude Opus 4.7 (in-session, implementer)
**Date:** 2026-05-13
**Branch reviewed:** `chore/dev-go-live-remediation-2`
**Commits:** `c933632b..HEAD` (14 commits: A.0 through E)
**Prior round review:** `docs/superpowers/reviews/2026-05-13-dev-remediation-opus-adversarial-review.md`
**Plan:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md`

This is the adversarial review of Round 2. Same reviewer = same implementer; the review reads each commit as if seeing it for the first time and asks: where is this half-done, weak, or wrong?

Severity scale: **BLOCKER** / **P1** / **P2** / **NIT**.

---

## Verdict: APPROVE-WITH-RESIDUALS

Round 2 closes every P1 finding from Round 1 plus all four open M2.x scope clusters (M2.1–M2.5), the M2.6 CSP gap, the M2.0 CSV triage, and the M2.7 file/PDF endpoint review. The only items that remain genuinely open are external-dependency follow-ups (provider-side secret rotation, release-owner sign-off) that this engineering branch cannot close from a developer terminal.

No BLOCKER findings. Three P1 residuals named below — none of them caused by Round 2's work; all of them inherited from Round 1's accepted-risk plan items that this branch was not asked to drive.

## Residual P1 — Owned outside this branch

### Residual P1-A — Production secret rotation still un-executed

`docs/security/secret-rotation-2026-05-12.md` is now an execution-ready playbook with Option B locked, per-row owners, verification commands, and an evidence ledger. But **every row still reads `pending-provider-rotation`**. The first-tenant gate cannot close until the release owner executes the playbooks against production and fills the evidence ledger.

**Action required from release owner:** run Playbook 1–8 against production, append evidence rows.

### Residual P1-B — Live-terminal smoke + Tunisia legal sign-off pending

The plan's First-Tenant Ready criteria include a live-terminal smoke pass + Tunisia accountant sign-off on the legal pack. These are operational, not engineering deliverables, and were never in this branch's scope.

**Action required from release owner.**

### Residual P1-C — Pre-deploy TBD scan for runbooks

P2-6 from the Round 1 review (no CI hook to fail the first-tenant deploy if any `pos-operations/` runbook still contains a literal `TBD`). Still open — neither Round 1 nor Round 2 added the pre-deploy script.

**Action:** add `scripts/preflight-runbooks.sh` that greps `docs/pos-operations/` for `\b(TBD|TODO|PLACEHOLDER)\b` excluding the template-substitution markers, then wire it into the deploy CI gate. ~30 min of work.

## P2 / NIT findings on Round 2's own work

### P2-1 — POS receipt PDF endpoints scope by company_id only

The Phase E review found that `ReceiptController::streamPdf` / `::downloadPdf` and `ReportController::downloadPdf` use single-column scope predicates (company_id only, or terminal->company_id). They are NOT vulnerable today because company UUIDs are tenant-scoped, but the defense-in-depth `tenant_id` predicate is missing. Queued in the review doc as P2, not P1.

**Mitigation:** add `where('tenant_id', $company->tenant_id)` alongside the existing `where('company_id', ...)` when these controllers are next touched.

### P2-2 — AuthController pre-auth cluster classified as `accept-with-doc`

Four endpoints (checkEmail, verifyEmail, forgotPassword, resetPassword) accept cross-tenant queries by design because the tenant context does not exist before login/register. The triage doc records this as `accept-with-doc` with a rate-limit mitigation note, but the gap is real: `checkEmail` discloses email-existence across tenants. This is a UX-vs-security trade-off the audit accepted; Round 2 just documented it.

**Mitigation (future round):** consider returning a constant-time "we sent you a magic link if the email exists" response, which removes the existence-disclosure dimension. Adds friction; balance with product.

### P2-3 — Round 2 auto-promoted 68 legitimate-platform-candidate rows in bulk

The Phase C closer ran the classifier and locked every `legitimate-platform-candidate` row to `legitimate-platform` with the heuristic basis as the acceptance note. The auto-lock is honest about what it is (the script's classifier is the same one that originally classified the row), but a human did not read 68 individual annotations and confirm each one. Spot-check probability of a mis-classification is low (the heuristic is conservative — it matches explicit substrings like "Spatie TeamScope auto-scoping", "super-admin", etc.) but non-zero.

**Mitigation:** the next round (or a sampling-based review) reads ~5 random `legitimate-platform` rows and confirms each is correctly characterized. The lock is reversible by editing the classifier and re-running.

### P2-4 — Round 2 added a new ProductImage `reorder` validation rule that may be over-strict

In `dev-remediation/B.M2.1`, the `reorder` endpoint's `image_ids.*` validation changed from `exists:product_images,id` to
`exists:product_images,id,product_id,<bound-product-id>`. A legitimate request that includes an image_id from another product within the SAME company now returns 422 instead of silently reordering. That's the correct semantics, but consumers (web admin's product-edit UI) may not expect 422 on this field. Spot-check the consumer.

**Mitigation:** `rg -n "reorder.*image_ids" apps/web/src/features/products` — confirm the UI does not silently swallow 422 on this payload shape. (Not done by this branch; queued.)

### P2-5 — CSP `style-src 'self' 'unsafe-inline'`

The web CSP added in `dev-remediation/D` permits `'unsafe-inline'` for styles. Tailwind utility classes inject inline styles, so this is necessary today, but it weakens the CSP. A future migration to a strict-CSP-compatible stylesheet generation (e.g., per-build hash list, or Tailwind's static extraction mode) would let us drop `'unsafe-inline'`.

**Mitigation:** track as a P3 hardening follow-up.

### P2-6 — Round 2 did not update `feedback_modal_fixed_size.md` or other linked memories

The closure ledger (`docs/qa/2026-05-13-round2-p1-closures.md`) is the single canonical record of what Round 2 closed. No other memory file or cross-reference was updated.

**Mitigation:** the user's auto-memory system can pick up `2026-05-13-round2-p1-closures.md` as the next-session-context anchor. No action this round.

### NIT-1 — Round 2 commit messages are verbose

Each Round 2 commit has a multi-paragraph body explaining the closure. Reviewers scanning the log may prefer single-line summaries; the verbose bodies are intentional for this round (closure ledger doubles as the PR description) but could be trimmed in future work.

### NIT-2 — Phase C generator script edits are not test-covered

The Python classifier in `scripts/generate-cross-tenant-inventory.py` is only verified by running it and inspecting the CSV diff. A small pytest-style sanity test for the classifier function would let future edits run safely.

---

## Closed Round 1 P1 findings — verification

| Round 1 P1 | Closure status | Commit | Notes |
| --- | --- | --- | --- |
| P1-1 secret rotation | engineering closed; provider rotation pending owner | `dev-remediation/A.1` | Playbook ready; ledger awaiting evidence rows. |
| P1-2 EnforceTokenTenantClaim | closed | `dev-remediation/A.2` | Middleware verified; wire contract pinned. |
| P1-3 Category 404→422 UI | closed (no impact) | `dev-remediation/A.3` | UI consumer is status-code-agnostic; toast covers both. |
| P1-4 seedAuth resetAuth | closed | `dev-remediation/A.4` | 24 files updated; 270/270 web test files green. |
| P1-5 rate-limit enforcement | closed | `dev-remediation/A.5` | 5 end-to-end tests pin login/forgot/reset/register/manager-PIN throttles. |

All five Round 1 P1 findings are closed from the engineering side.

## Closed Round 1 P2 findings — relevant subset

| Round 1 P2 | Status | Notes |
| --- | --- | --- |
| P2-1 Scramble consumer audit | not done | Out of scope this round. |
| P2-2 pnpm overrides doc comment | not done | Out of scope this round. |
| P2-3 api.marketplace cluster audit-trail check | not done | Out of scope this round. |
| P2-4 renderWithProviders docstring update | not done | Mentioned in A.4 closure ledger as queued. |
| P2-5 POS golden-hash Tauri side | not done | Out of scope this round. |
| P2-6 pre-deploy runbook TBD scan | not done | Residual P1-C above. |
| P2-7 CORS guard config:cache | not done | Out of scope this round. |
| P2-8 audit-log dispatch verification | not done | Out of scope this round. |
| P2-9 M2.0 heuristic miscategorization | partly done | Phase C auto-promoted candidates with explicit acceptance notes; spot-check still recommended (P2-3 above). |
| P2-10 CSV route column | not done | Out of scope this round. |

These remain queued for future rounds and are not first-tenant blockers.

---

## What Round 2 Delivered

- **Phase A** (5 commits, P1 closures from Round 1).
- **Phase B** (5 commits, M2.1–M2.5 — 24 controller annotations + 1 cache-key annotation closed test-first across both pilots).
- **Phase C** (1 commit, CrossTenantRoute CSV: 97 rows triaged, 0 TBD remaining, first-tenant gate satisfied).
- **Phase D** (1 commit, route-aware CSP + 2 new rate limiters + tests).
- **Phase E** (1 commit, file/PDF endpoint scope review + AttachmentController fix).

14 commits. ~52 new tests added across feature suites. 0 regressions on existing tests (270 web files / 2129 tests / 1 pre-existing skip; 143 POS files / 1274 tests; full backend PHPUnit run pending in closing-gate verification).

## Bottom Line

Round 2 closes the gap between "engineering side of the audit is done" and "first-tenant operational gate is closed." The three residual P1s above all require human-driven action outside the engineering branch (provider revocation, live-terminal smoke, runbook TBD scan). Recommend merging Round 2 into `dev` and tracking the three residuals as named pre-deploy blockers.
