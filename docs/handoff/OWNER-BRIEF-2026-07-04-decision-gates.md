# Owner Brief — Decision Gates & Laptop-Track Queue (2026-07-04)

> Prepared by the delivery-factory orchestrator. Everything here needs YOU —
> either a decision or macOS/owner-in-the-loop work. Each decision is
> phrased so a one-word answer unblocks the factory.

## A. Decisions (answer inline, factory applies them)

### A1. CI trigger model — "A" or "B"?
Today a direct `git push origin dev` runs **zero CI**, and PR→dev runs only lint/typecheck (no PHPUnit/Vitest/build). Full analysis + ready-to-apply patches: `docs/superpowers/audits/2026-07-04-factory-quality-gates-decision-memo.md`.
- **Option A** — add `push: branches: [dev]` to ci.yml (+ widen 4 heavy-job guards). Keeps direct-push workflow.
- **Option B (recommended, and preferred in the handover)** — PR-based merges into dev + branch protection. Gives the audit-agent bench a durable review anchor and makes "green-before-merge" preventive.

### A2. PHP version pin — confirm "8.4"?
CI pins 8.3; the production Dockerfile ships 8.4. Recommendation: bump CI to 8.4 (one-line patch in the memo). Any 8.3↔8.4 delta is currently invisible to CI.

### A3. Staging web autoDeploy — flip to true?
Web sat **78 commits / 2 days stale** after the last promotion because it needs a manual Dokploy deploy and the one "in flight" never ran (caught + fixed today; staging is now current at `8cb507faa` on all services). Recommendation in the memo: flip only after dev is gated (B + branch protection). Until then the factory workflow doc mandates an explicit web deploy per promotion.

### A4. Accounting GL A1 — expert-comptable countersign
Unchanged gate from the GL roadmap: A1 needs your expert-comptable session. VPS/factory can build everything downstream of D4/D6, but A1 is yours.

### A5. Procurement OQ3
Marked non-blocking (with A6) in the Waves 3-6 plan resolutions; flagging it here only so you know Waves 3-6 will proceed without it unless you say otherwise.

### A6. Margin-hierarchy FE UX choices
BE slice is built/reviewed on `feat/margin-category-override` (not merged). FE tasks 15-19 need your UX calls (where override provenance shows, clamp behavior surfacing). Suggest a 15-minute review when the BE merge lands.

## B. Laptop-track work queue (macOS/owner-bound, in rough order)
1. **Fresh Tauri POS build → staging** (builds/signing are macOS-bound).
2. **POS return-disposition selector UI** (spec on `feat/pos-return-disposition`).
3. **Caisse redesign P4-P7 + TransactionCart restyle** (visual sign-off work; base on origin/dev, NOT the stale `feat/pos-caisse-redesign`).
4. **Shifts v56 loose ends.**
5. **Customer-workflow features from the demo** — needs a DEDICATED brainstorming/spec session with you; outputs feed the autonomous queue.
6. **Visual sign-offs** on design-heavy pages after agents draft them.
7. **Mobile expense logging — device sign-off.** Built 2026-07-04 on `feat/mobile-expense-logging` in the `erp-mobile` repo (off `design-system`, HEAD `6f341cf`, unpushed): create + categories + receipt camera + offline queue/sync, mirroring the counting feature; typecheck clean, 25 suites/228 tests. Needs an Expo run on the demo stack (camera permission → capture → upload → offline drain). Note: payment repository/method pickers fail-soft because `repositories.view`/`treasury.view` aren't in the expense roles' permission set — decide whether to grant those to expense-logging roles or keep the pickers hidden on mobile.

## D. Branch/worktree hygiene — your call on these (everything provably-merged was already pruned: 29 branches + 5 stale worktrees removed 2026-07-04)

| Item | What would be lost if discarded |
|---|---|
| **`erp.dashboard-demo` worktree** | ⚠️ **UNCOMMITTED CODE** — live-sales report service rework (`LiveSalesReportService.php`, `OwnerSalesSummaryService`, `SalesReportService`, `ReportsController` edits) + `DemoPosSalesSeeder` + tests. Not committed anywhere. Commit, hand to the factory, or explicitly discard. |
| `feat/db-per-tenant-deploy` (10 ahead) | Direct-DDL pgbouncer connection manager, `TENANCY_DB_PREFIX` plumbing, dokploy db-per-tenant compose defaults — real infra not on dev. |
| `feat/accounting-gl-go-live` (11 ahead, docs-only) | GL roadmap + A1/A2/A3 specs + Tunisia SCE mapping. Suggest: merge the docs to dev and delete the branch. |
| `feat/supplier-invoice-ocr` (5 ahead) | `GoodsReceiptDocumentService` + `DocumentType::GoodsReceipt` enum + 3 design docs (PO-less invoice→GRN). Overlaps procurement Waves — reconcile with the receipt-ledger work before reviving. |
| `feat/bank-reference-verification` (1 ahead) | One un-built design doc (banks table, RIB/IBAN/BIC validator). |
| `feat/demo-pharmacy-account` (5 ahead) | 2 unique planning docs; all code superseded on dev. |
| Worktrees `erp.procurement`, `erp.treasury-*` ×4, `erp.unified-imports`, `erp.demo-pharmacy`, `erp.pos-stock` | Branches fully merged, but each carries untracked handoff/audit docs never committed. Say the word and the factory sweeps the docs into `docs/` on dev and removes the worktrees. |

## C. FYI — found today, already in the backlog docs
- `RecordCustomerDepositTest` exposes a likely REAL bug: pure-advance deposits never post the CustomerAdvance GL leg (`PaymentAllocationService.php:324` keys off `gl_account_id` while the deposit path populates `account_id`). Needs a red/green run on the VPS to confirm; triaged High (money correctness), TD-011 in the refreshed Product Bible.
- **RoleController privesc remains the launch blocker** (TD-015) — no `can:roles.*` gate on role mutations. In the Track A bug queue.
- `react-doctor.yml` workflow is untracked-only — it never runs in CI until committed.
- Known-failure triage (green-means-green): `docs/superpowers/audits/2026-07-04-preexisting-test-failures-backlog.md`.
