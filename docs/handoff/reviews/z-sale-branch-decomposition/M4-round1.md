## M4 whole-lane gate — round 1

**Scope held to:** brief §M4 ("re-run the full accumulated evidence over the integrated branch with every lens; paste and read `git diff --name-only <BASE>..HEAD`; prove the negatives; record the R-6 sequencing obligation"), as amended by `M1-ruling.md` (Option B, unconditional; conditions 1–5).
**Lenses:** `fiscal-pos` (applies — signed `Z_REPORT` / `X_REPORT` / `SESSION_CLOSE` payload bytes and the legacy `z_reports` hash chain), `general` (applies — rule 19 scale, TS strictness, gates). Tenancy/authz, treasury, inventory-costing: **not applicable**, device-local read-path aggregation only; no route, permission, GL or stock surface in the diff.

**Everything I verified independently, not from the brief or the YAML:**

- `git diff --name-only` = **17** files: 5 under `apps/pos` (`reportApi.ts`, `endOfDayPreview.ts`, `zReportService.ts`, `endOfDayPreview.test.ts`, `saleReportingEndToEnd.test.ts`) + 12 under `docs/`. Working tree clean.
- Production change re-derived: three structurally identical blocks, `lineGross = line.line_total`, `lineNet = bcsub(lineGross, lineVat, <scale>)`, three scaled accumulators — `zReportService.ts:917-926`, `endOfDayPreview.ts:307-321`, `reportApi.ts:497-506`. Scale is the injected currency scale in all three (`zReportService.ts:143`, `endOfDayPreview.ts:172`, `reportApi.ts:382-386`). No float, no `parseFloat`, money as strings. Rule 19 **holds**.
- Ruled option implemented exactly: **no** `apps/api/**` path, no registry/payload/parser/validator/projection/NF525/`zSessionAuthoring` path, no writer (`receiptService.ts`/`cartStore.ts`/`cartTotals.ts`), no migration/seeder/backfill, no new named queue. Ruling condition 1 **holds**.
- Ruling condition 2 (SESSION_CLOSE lockstep) is a real assertion, not an assumption: `saleReportingEndToEnd.test.ts:614-646` captures the actual `AuthorZSessionCloseInput`, runs the **real** `buildZReportPayload`/`buildSessionClosePayload`, asserts byte-identical `vat_breakdown` **and** that the shared value is the corrected decomposition.
- Ran the evidence myself: Tier-1 (7 suites) **86 passed**; Tier-2 (8 suites) **80 passed**; `tsc --noEmit` exit 0; `node scripts/lint-ratchet.mjs` → `@autoerp/pos` **held at 84**, `@autoerp/web` 6449→6452 FAIL — inherited, and the inheritance is structurally sound (`apps/web` is absent from the branch file list, as are eslint config, the ratchet script and every shared package).
- R-6 recorded, never assigned (`progress.yaml` `r6_device_build_sequencing`); LEDGER citations re-derived and correct (`LEDGER.md:68` D-1, `:70` D-3, `:34` O-22, `:111` C-2).

---

### Register

**1 — P2 · CONFIRMED · `docs/handoff/progress/z-sale-branch-decomposition.progress.yaml:463` (and `:469`, and `docs/sessions/codex-z-sale-branch-decomposition-report.md:374`, `:399`)**
M4's whole-branch evidence is numerically wrong in the one artifact the milestone's own evidence contract names as the anti-faking antidote ("the file list is pasted and **read**").
- `whole_branch_file_list` says *"16 files. FIVE under apps/pos … ELEVEN under docs/"*. Actual at HEAD: **17 files, 12 under `docs/`**. This is not staleness — `git log -S` shows the M4 block was written in `27acf2833`, the **same commit that added `M3-round1.md`** and made the count 17. It was wrong the moment it was committed, and it carries no "as of" qualifier.
- `production_diff_size` says *"18 lines — three IDENTICAL six-line blocks"*. Actual: **15 added non-comment lines, three identical five-line blocks** (`git diff … | grep -E '^\+' | grep -vE '^\+[[:space:]]*(//|$)'` → exactly 15).
- The handback report repeats the 16/11 figure at `:374`; it is qualified *"CURRENT as of 5b805657c"*, where it was true — but it is the section the parent reads to close LEDGER C-2, and it is stale at the tip it will be merged from.

Failure scenario: the parent closes LEDGER C-2 on this evidence line, or a future auditor reconciles the row against the branch, and finds the whole-branch census disagrees with the branch. This is the **third** artifact-truth miscount in this wave (M1 rounds 3–4 broken cost table; M3 gate-r1 P2 stale negatives) — and the M3.2 commit message that introduced this one is itself the fix round for the previous instance. The report's own "Deviations and concerns" §4 states the durable lesson ("after any structural edit, re-derive its structure mechanically"); the lesson was not applied to the count in the same commit.

**2 — P3 · CONFIRMED · `apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts:113`**
The R-3 scale-argument regression guard exists for **one** consumer, and it is the only one whose output is **not signed**. Dropping the `decimals` arguments in `zReportService.ts:918-925` (signed `Z_REPORT` + `SESSION_CLOSE`) or `reportApi.ts:500-505` (signed `X_REPORT`) leaves the entire regression set green: the only other `vat_breakdown` assertion anywhere in Tier-1 is `zReportService.test.ts:1045`, a **0 %-rate refund** row, and every sale fixture uses exact 2-dp EUR values that re-round identically through `bcformat(totals.*, scale)` at emission — which is precisely the invisibility the new test's own comment (`:117-124`) documents. The LEDGER C-2 evidence line's *"R-3 scale arguments added **and given their own regression guard**"* reads as coverage of the fix, not of one third of it.
Failure scenario: a later refactor drops the scale argument from the signed Z path; 86 Tier-1 tests stay green; a cash-rounded EUR shift seals a `vat_breakdown` a cent off, in bytes that are immutable forever.

**3 — P3 · CONFIRMED · `apps/pos/src/lib/offline/zReportService.ts:899-901` and `apps/pos/src/api/reportApi.ts:484-486`**
The headline accumulators sitting **inside the same loop iteration** as the corrected sale branch still call `bcadd` with **no scale argument** → `decimal.ts:22`'s default of 3, feeding the signed `gross_sales` / `net_sales` / `tax_amount`. Correctly **not fixed** (brief §NOT-IN-SCOPE, ruling conditions 1 and 4), but it is the identical R-3 defect class on signed fields and it is recorded **nowhere**: not in F-1…F-5, not in `owes_parent`, not in the C-2 evidence line (grep over the YAML and the handback returns nothing). It is reachable, not theoretical — `subtotal` carries genuine sub-cent precision on cash-rounded EUR receipts (`receiptService.cashRounding.test.ts:199-211` persists `line_total '9.997'`), so two such receipts diverge by one cent between scale-2 and scale-3 accumulation, exactly the arithmetic the new R-3 test demonstrates.
Failure scenario: the F-2 sibling lane rewrites these three lines (they are its entire subject) without knowing the scale defect rides along, and ships the corrected headline still accumulating at the wrong scale. Record it against F-2 so the sibling lane inherits it.

**4 — P3 · CONFIRMED · `apps/pos/src/lib/offline/__tests__/endOfDayPreview.test.ts:164`**
The comment reads *"the per-line **truncation** happens at the CURRENCY scale."* `decimal.ts:14` sets `Big.RM = 1` (ROUND_HALF_UP), so `bcsub`/`bcadd`/`bcformat` **round**, they do not truncate. The test bites for the right reason (I re-derived it: 10.003+10.003 = 20.006 → `toFixed(2)` half-up → 20.01), but rule 19's contract explicitly turns on "`bcformat` truncates", so a reader reconciling the two will be misled about which primitive behaves how.

**5 — P3 · CONFIRMED · `docs/handoff/progress/z-sale-branch-decomposition.progress.yaml:503`**
`updated: 9e43a4f5a` is three commits stale (HEAD is `27acf2833`). Cosmetic, but this file is the parent's merge input and its own freshness stamp is the first thing a merge check reads.

**6 — P3 · CONFIRMED · `docs/handoff/progress/z-sale-branch-decomposition.progress.yaml:41-47`**
`owes_parent.ticket-residue-column-label` defers the wrong "+2.00 net / +2.00 **VAT**" residue label on **both** the ticket **and** the brief (`:111-112`) to the parent as *"Not this lane's file to edit"* — but the lane **did** edit the brief under ruling authority twelve lines above that exact sentence (`CODEX-DISPATCH…md:102-121`). The reproduction is right (I re-derived it: buggy sale +12.00/+2.00/+14.00 minus corrected refund −10.00/−2.00/−12.00 = net +2.00, **vat 0.00**, gross +2.00). Declining an unordered edit is defensible scope discipline; flagged only so the parent does not assume the brief was left pristine when reconciling `owes_parent`.

---

### Bypasses I tried that FAILED to find a defect

- **Old-signed-event invalidation (the P1 candidate).** Looked for any path that recomputes a per-rate decomposition and compares it to a sealed one. None exists: `validateZReportFamilyPayload` (`FiscalPayloadConstraintValidator.php:763-770`) asserts only four UUIDs, a date and a bool and never iterates `vat_breakdown`; `ZReportProjection.php:154` is a verbatim passthrough; `computeZReportHash` (`zReportService.ts:445-451`) hashes the **stored** `report_data`, so a historical `z_reports` row re-verifies against its own persisted bytes, not against a re-aggregation. Option B genuinely cannot invalidate history. The memo's claim survives.
- **Zero-rate regression.** For `tax_rate '0'`, `lineVat = '0'`, so old (`gross = net + 0`) and new (`net = gross − 0`) are algebraically identical; `zReportService.test.ts:1045-1047`'s 0-rate refund assertion is untouched — confirmed green in my own run.
- **Refund-branch tampering (R-2).** No added or removed non-comment line contains `bcabs(`; both refund derivations are byte-identical at base (`zReportService.ts:868`, `reportApi.ts:463`). The single refund-side edit is the struck comment in `endOfDayPreview.ts:280-288`, comment-only, and it is disclosed rather than glossed.
- **Brief self-edit.** Diffed the executor's changes to its own acceptance brief line by line: it is exactly the ruling-ordered F-2 annotation plus the NOT-IN-SCOPE row rewrite. **No acceptance criterion, invariant, required test or gate was weakened.**
- **Test vacuity.** Ran Tier-1 and Tier-2 myself rather than trusting the pasted counts (86 / 80, matching). Checked the new suite for `any` (none), for CSS/i18n-key assertions (none — all assertions are on derived monetary values, rule 17 held), and confirmed it drives the **real** `createOfflineReceipt`/`createRefundReceipt`/`FiscalEventEngine` against real SQLite with only the append functions intercepted.
- **Scope leak / rule 8.** Whole file list re-derived; no server change, no version bump, no migration, no seeder, no new queue, no user-facing string, no `ar` tree.

**Net:** the code is right, the ruling was implemented exactly, the negatives hold, and the gates pass. What fails is M4's own deliverable — the whole-branch census the milestone exists to produce and that the parent will merge on. Correct the counts (17 files / 12 docs; 15 lines in three five-line blocks) in both the YAML and the handback, refresh `updated:`, and record findings 2 and 3 in the register, then re-gate.

VERDICT: CHANGES-REQUIRED
