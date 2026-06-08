# Precision & Scale-Drift Remediation — Session Handoff (Phases 2–12)

> **Read this FIRST, in full, before any action.** Then read the plan
> `docs/superpowers/plans/2026-05-28-precision-drift-remediation.md` and the audit
> `docs/superpowers/audits/2026-05-28-precision-drift-audit.md`. This doc is the operating
> manual; the plan is the authoritative task spec.

**Mandate:** Execute Phases 2–12 of the precision remediation **autonomously, end-to-end. Do not
stop midway.** Only stop for the explicit gate conditions in the last section. Most work runs
through sub-agents; you (the orchestrator) curate context, sequence PRs, run reviews, and keep the
suite green.

---

## 0. Current state (what's already done)

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.precision-drift-remediation`
  (branch `feat/precision-drift-remediation`). Work ONLY here. Never `cd` into `apps/erp` or
  another worktree (parallel-session isolation rule).
- **PR #153** (`feat/precision-drift-remediation` → `dev`) is **OPEN, reviewed, all gates green**.
  It contains **Phase 0** (contract layer) + the **resolver fail-loud remediation** + **Phase 1**
  (storage-scale alignment). Do not redo any of it. If #153 is still open when you start, stack new
  phase branches on its tip (see §4). If it has merged to `dev`, branch new work off `dev`.
- **Tip commit at handoff:** `eadb63376` (run `git log --oneline origin/dev..HEAD` to see all 19
  commits). The plan's "Execution Progress Tracker" (top of the plan file) has Phase 0 follow-up +
  Phase 1 ticked.
- **Suite baseline:** 6784 tests, **0 failures except ONE pre-existing** `TenantCreationTest::test_tenant_get_database_name_returns_correct_schema` (tenancy DB-naming; present on `dev`, untouched by this branch). **Your green gate = "0 failures except that one."** PHPStan L8 full = 0 errors. Pint clean. Frontend typecheck/lint/test green.

---

## 1. Environment setup (DO THIS BEFORE RUNNING ANY TEST)

Fresh worktrees have **no `.env`** (gitignored, not carried in). Tests/PHPStan won't bootstrap
without it. One-time setup:

```bash
cd apps/api
cp .env.example .env
# set an APP_KEY (artisan key:generate fails to bootstrap before drivers are fixed):
php -r '$k="base64:".base64_encode(random_bytes(32));$c=file_get_contents(".env");file_put_contents(".env",preg_replace("/^APP_KEY=.*$/m","APP_KEY=".$k,$c));'
# .env.example ships an INVALID broadcast connection (redis) + redis drivers that aren't running locally.
# Fix them so artisan/phpstan boot:
php -r '$c=file_get_contents(".env");$c=preg_replace("/^BROADCAST_CONNECTION=.*$/m","BROADCAST_CONNECTION=log",$c);$c=preg_replace("/^SESSION_DRIVER=.*$/m","SESSION_DRIVER=file",$c);$c=preg_replace("/^QUEUE_CONNECTION=.*$/m","QUEUE_CONNECTION=sync",$c);$c=preg_replace("/^CACHE_STORE=.*$/m","CACHE_STORE=array",$c);file_put_contents(".env",$c);'
php artisan config:clear
```

**Test runtime facts:**
- Tests are SQLite `:memory:`, **single-process (NO paratest installed)** → the **full suite takes ~30 min**. Run it in the background with `--log-junit /tmp/x.xml` and parse the XML for failing testcases; don't tail the (single-process) progress.
- Tenant migrations live in **`apps/api/database/migrations/tenant/`** (the plan's task paths omit `tenant/` — always add it).
- **Schema-shape tests must guard** `if (DB::connection()->getDriverName() !== 'pgsql') { $this->markTestSkipped('information_schema.columns is Postgres-specific'); }`. A `RefreshDatabase` roundtrip via the `decimal:N` cast gives real coverage on SQLite. Canonical example: `tests/Unit/POS/PosShiftsScale4Test.php`.

---

## 2. Locked decisions & invariants (DO NOT violate without a gate)

1. **Canonical scales:** currency `decimal(N,3)` floor (display via `getDecimals(currency)`: 0 for JPY/KRW, **2 for EUR/USD/GBP, 3 for TND/LYD/JOD/KWD/OMR/BHD**); quantity `decimal(N,4)` storage, per-unit display via `units.decimal_places`. Outliers kept higher: `pos_shifts.*` (16,4), `eco_tax`/voucher (N,5), `exchange_rate` (15,6).
2. **`CurrencyScaleResolver::getScale()` THROWS `UnboundCompanyContextException`** when no company is bound (fail-loud). **Never revert to a silent fallback.** Out-of-request production callers (queued jobs, console, scheduled) use `getScaleSafe($currency, $fallback)`. There is an analogous `QuantityScaleResolver` (`scaleForProduct`/`scaleForUnit`/`storageScale()`=4) and a `QuantityScale` helper with **bcmath-native rounding** (use it, never float `round/floor/ceil` on quantities).
3. **No float/IEEE-754 in money or quantity pipelines.** Use `CurrencyScale::bcformatStrict/bcformatOrNull` (write boundaries) and `QuantityScale` (quantities). Intermediates at `scale + 1` (or `+4` for WAC/landed-cost), round once at the boundary. Forbidden: `number_format((float)$v, …)`, `(float)$model->decimalProp`, JS `parseFloat` on money/qty.
4. **Constructor injection only** (no `app()` in production). Strict types, no `mixed`/`any`. Enums for status/type. Module boundaries via contracts/events. TDD (red→green) for every code task.
5. **DECIMAL *widening* is non-destructive on PG** (safe). **Any *narrowing*** (like Phase 1.2 did for pos_orders) **needs a per-column PG pre-check that aborts on data that would truncate** — and is a gate-stop candidate (§9).

### The resolver-fallout recipe (you WILL need this repeatedly)
Phase 3 adds more no-arg `getScale()` callsites. Any **test that calls a service directly** (not via HTTP) must bind the company in `setUp()`:
```php
app(\App\Modules\Company\Services\CompanyContext::class)->setCompanyId($this->company->id);
```
(Mirror `tests/Unit/Document/CreditNoteServiceTest.php:72`.) The Company factory defaults to **EUR → scale 2**; TND state → scale 3. If a test mocks `CompanyContext`, also stub `getCompany()` or (preferred) bind the real one. If, after binding, a fixture assertion fails on a scale mismatch, align the fixture to the resolved scale (that's the intended precision correction) — but if it's a **fiscal hash / PDF-snapshot** assertion, STOP and treat as a concern.

---

## 3. Fiscal safety (READ — the "we did a run, it's mostly OK" nuance)

A full suite run is green, and the fiscal **hash-chain verification tests pass** because fiscal
hashes are **computed dynamically at runtime** (not pinned to stored-hash fixtures) — so they're
self-consistent regardless of scale. **That does NOT mean fiscal work is risk-free.** The real risk:

- **The canonical payload that FEEDS the hash.** If a Phase-3 change alters the *scale of a monetary
  or quantity value inside the canonical fiscal payload string* (e.g. `ReceiptCreationService`
  canonicalization, `PosCoreReceiptProjection`, the fiscal event payload, `roundVat`), then
  **go-forward receipt hashes change**, and — critically — **the POS device computes hashes too**
  (device-authority pattern). Server and device canonicalization MUST agree byte-for-byte. So any
  task touching receipt/document fiscal canonicalization is **CRITICAL → dual review (Opus + Codex)**
  and must (a) keep server/device canonical scale identical, (b) document any deliberate shape change.
- **Byte-stable golden fixtures** (e.g. `tests/Fixtures/Nf525/jet_export_v1.xml`) intentionally
  fail on shape changes. Phase 1 already regenerated it (Quantite 3→4) — a *deliberate* change.
  When a fiscal fixture diff is the *intended* canonical-scale change, regenerate it (delete →
  re-run writes it → re-run asserts), and **log it in the REALIGNMENT-LOG (Phase 12)**. If a fiscal
  fixture changes *unexpectedly*, STOP — it's a real regression.
- **Known deferred:** a "fiscal-canonical payload" precision contract item exists (see T2-variants
  memory). When you reach receipt canonicalization tasks, confirm the canonical scale is explicit,
  not incidental.

**Bottom line:** treat the user's "fiscal is mostly OK" as "verification passes today"; still give
every fiscal-payload-touching task the CRITICAL (dual-review) treatment below.

---

## 4. PR & branch strategy (stacked)

PR #153 is the base. Until it merges to `dev`:
- Create each phase's branch **off the current branch tip** (so it builds on the contract layer),
  e.g. `git branch feat/precision-phase2-uom <tip>`.
- Open each phase PR with `--base feat/precision-drift-remediation` (the #153 branch) so the PR diff
  shows **only that phase**. Once #153 merges to `dev`, retarget open phase PRs to `dev`
  (`gh pr edit <n> --base dev`).
- If #153 has already merged to `dev` when you start: branch each phase off `dev`, base PRs on `dev`.

**Keep PRs scoped per the chunking in §5 — do NOT bundle everything into one giant PR.** Each PR:
TDD per task → targeted module tests green → **per-phase adversarial review** → close BLOCKER/P1 →
push → open PR → (full-suite checkpoint per §6).

Commit trailer for every commit: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

---

## 5. Roadmap — chunked into PRs (with parallelization & review tier)

Legend: **[PARALLEL]** = dispatch implementer sub-agents concurrently (disjoint files; have them
edit+verify but **NOT commit** to avoid `.git/index.lock` races — you commit per cluster).
**[INLINE]** = orchestrator does it or a single careful sub-agent (cross-cutting/judgment).
Review tier: **Opus** = one Opus adversarial reviewer sub-agent. **Opus+Codex** = both (fiscal/critical).

> Dependency reality: Phase 0 done. Phases 2–11 are each independent after Phase 0 EXCEPT Phase 10
> needs Phase 0+6 components, Phase 8 should co-deliver with or follow Phase 7.1 (Money VO), Phase 11
> comes after the code phases, Phase 12 last. Inventory parallel session (PR #151) is MERGED, so
> Tasks 3.10/3.11/4.3/5.2 are unblocked.

### PR group A — quick, independent, low-risk (do first; can run in one wave)
- **PR A1 — Phase 6 Resources** (6.1 DocumentTaxBreakdownResource `'0.00'`→`bcformat`; 6.2
  TerminalResource drop `(float)` — pairs with the `decimal:2` cast already shipped; 6.3
  WithholdingRuleResource drop `(float)`). **[PARALLEL] · Opus.** Small.
- **PR A2 — Phase 8 Notifications** (8.1 Billing notifications derive scale from `invoice.currency`
  via `CurrencyScale::for(...)`). **[PARALLEL with A1] · Opus.** Note: consumes `Money` — if Phase 7.1
  hasn't landed, this is fine standalone (reads model fields), but re-check after 7.1.
- **PR A3 — Phase 2 UoM settings** (2.1 backend PATCH `/units/{id}` decimal_places+rounding_method
  + cascade job; 2.2 frontend `UnitDecimalSettings.tsx`; 2.3 `UnitSeeder` canonical defaults).
  **2.1 [sub-agent] + 2.2 [sub-agent] in [PARALLEL]; 2.3 [sub-agent]. · Opus.** Medium.

### PR group B — Phase 4 ingress regex sweep (~120 sites, highly parallelizable, MECHANICAL)
Pattern: replace bare `numeric` with `['string','regex:/^-?\d+(\.\d{1,S})?$/', …]` where S = column
scale (money 3, quantity 4, tax/percent 2); update DTO `?float`→`?string`; add a 422 regression test
per module. The plan §4.15 already groups these into ~6 PRs — follow it:
- **PR B1** — POS + Document (4.1, 4.2). **Opus+Codex** (POS receipt ingress feeds fiscal payload).
- **PR B2** — Treasury + Accounting + Pricing (4.4, 4.5, 4.6). **Opus** (4.5/4.6 accounting → consider Codex if touching journal balance).
- **PR B3** — POS Z-sync + Taxation withholding (4.7, 4.8). **Opus+Codex** (Z-report is fiscal).
- **PR B4** — Workshop + Catalog + Service + Marketplace (4.9–4.12). **[PARALLEL sub-agents per module] · Opus.**
- **PR B5** — Cart + Expense + Scheduling + remaining (4.13, 4.14). **[PARALLEL] · Opus.**
- **PR B6** — Inventory sibling stock-movement controllers (4.3). **Opus** (coordinate w/ inventory territory; quantity scale 4).
Each task is independent → within a PR, dispatch one implementer sub-agent per FormRequest cluster in parallel.

### PR group C — Phase 3 service bcmath sweep (16 tasks; mix of mechanical & critical)
Split per the plan §3.17. **Mechanical (single Opus):** 3.1 AgedReceivables, 3.2 RefundService,
3.3 UninvoicedDeliveryNote, 3.4 BatchWriteOff, 3.5 VendorRefund, 3.12 PayrollExport (multiply-first),
3.13 UnitConversion (use `QuantityScale::round`), 3.14 Loyalty earning, 3.15 CompanyController
reservation_settings, 3.16 InvoiceService manual invoice, 3.6 CartConversion, 3.7 TaxCalculation
(scale+1 intermediates).
**CRITICAL (Opus+Codex):** **3.8 LandedCostService**, **3.9 WeightedAverageCostService** (WAC drift —
remove every `(float)`, intermediates at scale+4), **3.10 ReceiptCreationService** (the
`bcsub($stockQty,$quantity,2)` → quantity-scale-4 stock decrement — Codex flagged this; pre-existing
bug, now unblocked), **3.11 ReceiptReturnService** (symmetric VAT bcmath; extract shared helper so
the two paths can't drift). Suggested PRs: C1(3.1–3.3), C2(3.4–3.5), C3(3.6–3.7), **C4(3.8–3.9 crit)**,
**C5(3.10–3.11 crit)**, C6(3.12–3.13), C7(3.14–3.15), C8(3.16).
Within a PR, tasks touching different services are disjoint → **[PARALLEL sub-agents]**; but each is
TDD with a precision regression test (the plan gives exact gold-standard assertions, e.g.
3.12 = 36000 min × 25.123 = `'15073.800'`).

### PR group D — Phase 5 JSONB tightening (6 tasks)
Plan §5.7 groups: **D1** (5.1 opening-balance staging + 5.6 restore pos_receipt_lines line_total
CHECK) — **Opus+Codex, [INLINE/careful]** (5.6 re-introduces a deliberately-dropped fiscal CHECK
constraint; needs judgment + verify modifier price_adjustment arithmetic satisfies the invariant;
this is a **gate-stop candidate** if the formula can't be satisfied). **D2** (5.2 inventory counting
events float→string, 5.3 loyalty metadata) — **Opus**. **D3** (5.4 held-order, 5.5 Z-report sync
per-key validation) — **Opus+Codex** (Z-report fiscal).

### PR group E — Phase 7 Value-object refactor (CRITICAL, mostly INLINE)
- **PR E1 — Money VO** (7.1): `Money` → numeric-string + bcmath, `equals()` via `bccomp` (no
  tolerance), `toCents()` via bcmul. Touches ~8 Billing callers + StripeWebhook. **[INLINE or one
  careful sub-agent] · Opus+Codex.** Co-deliver or sequence BEFORE Phase 8 (8.1 consumes Money).
- **PR E2 — Loyalty VOs** (7.2 PointsAmount, 7.3 LoyaltyBalance) → numeric-string, remove tolerance
  comparisons. **[INLINE] · Opus** (Codex if balance integrity is fiscal-reported).

### PR group F — Phase 10 frontend rollout (~78 `step="0.01"` sites; MECHANICAL, parallel)
Replace with `<MoneyInput currency={}>` / `<QuantityInput decimalPlaces={}>`; payloads as strings.
Plan §10.8 ~6 PRs by cluster (POS, Treasury/Accounting/Document/Expense, Loyalty/Coupon/Promotion/
Voucher/Partner/Settings, Catalog/Service/Workshop, Misc, **10.6 POS `formatCashAmount`
currency-aware — Opus+Codex, fiscal: device hash canonicalization**, 10.7 POS cart/payment store
bcmath — Opus+Codex). Non-fiscal clusters: **[PARALLEL sub-agents] · Opus.** Needs Phase 0.7
components (already shipped) + Phase 6.

### PR group G — Phase 9 seeders/fixtures + Phase 11 guards + Phase 12 docs (finish)
- **PR G1 — Phase 9** (9.1 CoffeeShopSeeder, 9.2 factories currency-state, 9.3 TunisianParapharmacy
  bcmath). **[PARALLEL] · Opus.**
- **PR G2 — Phase 11 lint guards** (11.1 PHPStan forbid `(float)` on decimal props, 11.2 forbid
  hardcoded bcmath scale literals in services, 11.3 ESLint no-hardcoded-step, 11.4 ESLint
  no-parseFloat-on-money, 11.5 CI gate). **[PARALLEL] · Opus.** Run AFTER the code phases so the
  rules don't fire on not-yet-migrated code.
- **PR G3 — Phase 12 docs** (12.1 CLAUDE.md precision section, 12.2 `docs/architecture/precision-
  contract.md`, 12.3 REALIGNMENT-LOG entry incl. the NF525 Quantite 3→4 change). **[INLINE] · Opus.**

**Suggested execution order:** A (warm-up, fast wins) → B (ingress, huge parallel throughput) →
C (services; do C4/C5 critical carefully) → D → E → F → G. You can interleave A/B since independent.

---

## 6. Verification protocol (mandatory)

**Per task (inside a sub-agent):** TDD red→green on the task's own tests; `./vendor/bin/pint --test`
on changed files; `./vendor/bin/phpstan analyse <changed files> --memory-limit=2G --no-progress`
(0 errors). Frontend tasks: `pnpm typecheck && pnpm lint && pnpm test <touched dir>`.

**Per phase/PR boundary (orchestrator):**
1. Run the touched modules' full test dirs (targeted, fast).
2. **Adversarial review** (see §7). Close BLOCKER/P1 before opening the PR.
3. Push, open PR (scoped, §4).

**Full-suite checkpoint:** run the complete `./vendor/bin/phpunit --log-junit /tmp/x.xml` (~30 min,
background) **after each PR group** (not every PR — too slow). Gate = **0 failures except the known
`TenantCreationTest`**. Parse the junit XML; if a NEW failure appears, debug with
`superpowers:systematic-debugging` before proceeding — never paper over. Also run full PHPStan L8
(`./vendor/bin/phpstan analyse --memory-limit=2G` — uses phpstan.neon paths) at each checkpoint.

The plan's own "Verification commands" block is authoritative if it differs.

---

## 7. Review protocol (per your directive)

- **Every PR → an Opus Adversarial Reviewer sub-agent.** Dispatch via the `Agent` tool with the
  `general-purpose` type and `model: opus`. Give it ONLY: the diff range (`git diff <base>..<head>`),
  a crisp description of what changed, and adversarial instructions (hunt real defects: scale
  correctness, float leaks, missing context binding, regression risk). It must classify
  BLOCKER/P1/P2/NIT with file:line and give a verdict, and **SAVE the review to a file** under
  `docs/superpowers/reviews/2026-05-29-precision-<phase>-opus-review.md` (per team convention —
  never inline-only).
- **CRITICAL PRs (fiscal/money-core) → ALSO a Codex Adversarial Reviewer** (`Agent` type
  `codex:codex-rescue`). Critical = anything in §3 (fiscal payload), WAC/landed-cost (3.8/3.9),
  receipt/return (3.10/3.11), Z-report/opening-balance/CHECK (5.1/5.5/5.6), Money VO (7.1), POS
  cash/store frontend (10.6/10.7), and POS/Taxation ingress (B1/B3). Codex runs in a **read-only
  sandbox and may not be able to save files or even read the diff** — instruct it to read the diff
  and, if it can't write, return findings inline; **then YOU verify every Codex finding against the
  real code** (in PR #153 Codex hallucinated filenames and a cast scale, and its "BLOCKERs" were
  pre-existing/forward-looking). Apply `superpowers:receiving-code-review`: fix real BLOCKER/P1,
  push back with evidence on wrong ones, write the consolidated verdict to the review file.
- **You (Opus orchestrator) make the merge call** after reconciling both reviews.

---

## 8. Sub-agent dispatch notes

- Use `superpowers:subagent-driven-development` as the operating frame. Give each implementer the
  **full task text from the plan** (don't make them read the plan), plus: the worktree path + "work
  only here", the §1 env facts, the §2 invariants, the resolver-binding recipe, and the exact
  files/line-refs (verify line refs first — the plan can be slightly stale; e.g. it said
  `loyalty_settings` but the table is `loyalty_programs`).
- **Parallelism:** dispatch multiple implementer sub-agents **in one message** when their files are
  disjoint. Tell them to **edit + verify but NOT commit**; you commit per cluster (avoids
  `.git/index.lock` races). For a single cluster you can let the sub-agent commit.
- Use **`model: sonnet`** for mechanical implementers (most of Phase 4/6/9/10); **`model: opus`** for
  integration/judgment tasks (Phase 7 Money VO, 3.8/3.9 WAC, 5.6 CHECK) and for ALL reviewers.
- If a sub-agent returns BLOCKED/NEEDS_CONTEXT, give context and re-dispatch (more capable model if
  it's a reasoning gap). Never force a silent retry.

---

## 9. When to STOP and ask the human (otherwise keep going)

1. A schema change you judge **irreversible/destructive** beyond a plain non-destructive DECIMAL
   widen (e.g. another narrowing where the pre-check would actually fire on real data; a dropped
   column; **5.6's CHECK constraint if the modifier-price-adjustment arithmetic can't satisfy the
   invariant**).
2. A genuine ambiguity the plan + audit can't resolve, or a **file-level collision** with another
   active session's territory.
3. A phase that still fails verification **after** a real `superpowers:systematic-debugging` pass
   (a NEW suite failure you can't attribute/fix — not the known `TenantCreationTest`).
4. An adversarial review returns a BLOCKER you **cannot** close without changing a **locked decision**
   in §2 (e.g. softening the resolver throw, abandoning scale-4 quantity, removing a fiscal CHECK).
5. A **fiscal canonical-payload** change where server vs POS-device canonicalization would diverge,
   or a fiscal golden fixture changes **unexpectedly** (vs the deliberate NF525-style regen).

Otherwise: work all the way through Phase 12.

---

## 10. Definition of done

- Every Phase 2–12 PR open (or merged) against `dev`, scoped per §5; PR #153 merged.
- Full PHPUnit green except the known `TenantCreationTest`; PHPStan L8 0 errors; Pint clean;
  frontend typecheck/lint/test green; new PHPStan+ESLint precision guards live in CI (Phase 11).
- Plan "Execution Progress Tracker" fully ticked; **REALIGNMENT-LOG** entry added (Phase 12.3, incl.
  the NF525 Quantite 3→4 shape change and the API string-type changes).
- Memory updated: `project_precision_drift_remediation.md` set to "all phases shipped"; keep the
  resolver-fallout + env quirks notes.
- Spot tests from the plan's "Done definition": TND POS `5.123` payment hash-verifies end-to-end and
  shows `5.123` on receipt + accounting; EUR parapharmacy 0.5 kg fractional sale shows `0.5000 kg`,
  stock decrements `0.5000`, COGS posts at canonical scale.

---

## 11. Key file/anchor reference
- Plan: `docs/superpowers/plans/2026-05-28-precision-drift-remediation.md` (authoritative task spec;
  PR groupings in §X.last of each phase; "Self-Review Checklist" maps audit findings → tasks).
- Audit: `docs/superpowers/audits/2026-05-28-precision-drift-audit.md`.
- PR #153 review: `docs/superpowers/reviews/2026-05-29-precision-phase0-1-codex-review.md`.
- Contract helpers: `app/Shared/Domain/{CurrencyScale,QuantityScale}.php`,
  `app/Shared/Infrastructure/{CurrencyScaleResolver,QuantityScaleResolver}.php`,
  `app/Shared/Contracts/*ScaleResolverInterface.php`, `app/Shared/Exceptions/UnboundCompanyContextException.php`.
- Frontend: `apps/web/src/lib/decimal.ts` (canonical `formatCurrency`), `apps/web/src/lib/formatQuantity.ts`,
  `apps/web/src/components/atoms/{MoneyInput,QuantityInput}/`.
- Test patterns: schema/roundtrip `tests/Unit/POS/PosShiftsScale4Test.php`; context-binding
  `tests/Unit/Document/CreditNoteServiceTest.php`.
- Memory: `project_precision_drift_remediation.md`, `project_inventory_quantity_precision.md`.
