# Remediation Round 2 — plan v1 (2026-08-07)

**Trigger:** owner directive post L5/Q-rulings batch: push dev → staging, then burn down the
remaining pre-launch defect pile, with a PARALLEL test-coverage/edge-case track.
**Predecessor:** P0 fix-lane program (L1–L6, complete 2026-08-06/07) + L5 ruling lanes
(complete 2026-08-07). This plan covers what those programs ticketed but did not fix.
**Process per lane (unchanged):** worktree off dev → TDD implementer → specialist Opus
adversarial gate → fix round → narrow re-gate → orchestrator merges ff to local dev with
post-merge green-proof. Adversarial review of THIS PLAN before dispatch (standing rule).

---

## Phase 0 — promotion + staging verification (blocking, in progress)

0.1 Land the Q1 CN-stamp GL lane (in flight) → dev.
0.2 Push dev → origin/dev (§A; owner authorized 2026-08-07). NOTE: batched push carries TWO
    self-guarding migrations (L1 rounding-accounts backfill, W-5b allow_negative) — D-2
    letter waived by owner's "push what we have"; media-assets backfill rides the same push
    (L6 media lane merged to dev).
0.3 Immediately post-deploy on staging, in order:
    a. per-tenant `tenants:run db:seed --class=RolesAndPermissionsSeeder` + tenant-wide
       `permission:cache-reset` (perms lane; ⚠️ check custom-grant drift first — sync clobbers)
    b. `channels:reconcile` (cat-b central directory backfill)
    c. pre/post-migrate negative-repo + negative-line detection SQL per tenant (repobal +
       discount lanes; artifacts → accountant-disposition list)
    d. spot-checks: manager 403 on trial-balance AND vat-periods file; tax-config CRUD as
       admin; upload ≥160KiB; partner delete 204/409
0.4 Staging smoke (campaign smoke spec) green before any Phase-1 dispatch is promoted.

## Phase 1 — correctness lanes (dispatch after plan review; parallel worktrees)

R2-A **Cross-company authz within tenant** (W-8 F-1 P0-class + siblings): JournalEntry
     index/show/post + AccountController company-scoping; post() settles with caller currency;
     2nd-company-can't-invoice (documents unique index vs per-company counters); POST
     /companies commit-then-500. Gate: tenancy-authz. [tickets 2026-08-05-w8-isolation]
R2-B **Quote totals apply line discounts** (NEW P1 from discount gate): QuoteController
     bare bcmul totals vs persisted discount fields; quote→invoice conversion delta; route
     through DocumentLine::computeLineTotal. Gate: treasury (money) + regression pins on
     ConvertsDocuments. [ticket 2026-08-07-discount-lane-out-of-lane-findings §2]
R2-C **Resolver-403 swallow → 500 + message leak** (3 report endpoints: aged-AR/AP,
     upcomingPayments): surface 403 with envelope, no body leak. Small. Gate: tenancy-authz
     (narrow). [ticket 2026-08-06-l3-cash-scope-residuals (b)]
R2-D **is_numeric+bcmath 500-class sweep**: harden the 3 known validator sites with
     isBcmathSafeDecimal; repo-wide sweep for `is_numeric` guarding bc*; evaluate a PHPStan
     rule (ForbidBcmathOnIsNumericGuard) to close the class. Gate: precision-focused Opus.
     [ticket 2026-08-07-discount-lane-out-of-lane-findings §1]
R2-E **Optimistic locking on Draft+Confirmed documents** (W-7 P1): If-Match/updated_at
     precondition on document update; FE sends precondition; 409 envelope + FE conflict
     toast. Gate: FE + treasury. [ticket 2026-08-03-w7-cross-cutting-findings]
R2-F **L2 deferred cluster** (one lane, shared surfaces): COGS mirror + supplier-AP reversal
     on cancel (purchase-side twins of F-6b); refuse-cancel-when-period-not-OPEN condition;
     remaining balance_due??total consumers (SmartPayment + 4 PaymentController writers).
     Gate: GL + treasury dual. [tickets 2026-08-06-l2-*]
R2-G **Q2/I-3 closure round** (BLOCKED on expert answer for 0%-deductible): implement the
     ruling either way + close backfill minors m-7/m-8/m-9 + scale-2 pin m-3 + VatBreakdownTable
     parseFloat m-6. Gate: treasury (narrow). [ticket 2026-08-06-q2-gate-minor-followups]
R2-H **Withholding route gating** (readiness-register blocker "withholding routes ungated"):
     module/permission gate per vertical-module-gating doc. Gate: tenancy-authz (narrow).

Ordering: R2-A and R2-F are the launch-heavy ones — dispatch first. R2-C/R2-D/R2-H are small
laners that can interleave. R2-B before any tenant issues quotes. R2-E anytime. R2-G on the
expert's answer.

## Phase 2 — test-coverage & edge-case track (PARALLEL with Phase 1; separate worktree,
test-only commits, no product code except where a red test exposes a real defect → then it
becomes a Phase-1-style mini-lane)

T-1 **PG-mode CI leg**: the repobal gate proved sqlite masked live-PG behaviour twice in one
    week (pcntl locks, savepoint/GUC, aggregate typing). Wire `phpunit-pgsql.xml` into
    preflight/CI for the treasury/GL/fiscal suites at minimum; budget the runtime.
T-2 **Bearer-based tenancy harness (T12)**: actingAs() never runs ResolveTenancy → the
    BUG-007 class is structurally invisible. Add a `actingAsViaBearer()` helper + convert the
    tenancy-critical suites (partner/document/treasury controllers); tie into T11's audit
    discriminator (flagged-service-behind-base-Controller-extending-controller).
T-3 **T11 tenancy-DI audit** (read-only, feeds T-2 conversions): 10 files injecting
    ConnectionInterface; OutboxIngestor = confirmed-risk shape; produce fix list.
T-4 **Ops-command dry-run coverage**: every artisan remediation command asserts its DRY-RUN
    report (operators run dry first — Q2 gate m-9 generalized); sweep vat:backfill-*,
    fiscal:*, pos:configure-* commands.
T-5 **FE truth harness top-3** (L6 forensics): expectImageRendered() naturalWidth helper
    (suite has ZERO image-loaded asserts); route-remount pattern with assert-a-request-FIRED;
    error-envelope DISCRIMINATION contract (rendered messages must differ across causes).
T-6 **Deterministic FE mocks**: stable-t fixture + missing-deps closure (discount m-4+m-5,
    pricing-popover staleness); promote exhaustive-deps to error in features/documents.
T-7 **fr `_many` plurals** (19 bases/9 ns known gap; en-vs-fr diffs can't catch): audit
    script + fill. [treasury-burndown ticket]
T-8 **IngressPrecisionTest route-bound leg**: cover the invoice/order override rule set the
    wildcard-replacement made load-bearing (discount gate follow-up).
T-9 **Playwright proof debt**: MTP-DSC-04 live proof (deferred at discount merge) + the
    perms-lane flipped specs, one batch run on the live stack.

## Explicitly OUT of this plan
Device-side work (Z sale-branch gross-as-net has its own gated ticket + fiscal gate); §Z/§Y
campaign legs (sequenced after staging); prod-env buildout (own program); configurable
CN/return-note stamp feature (owner feature line, post-certification); marketplace T7;
deferred post-launch tickets (86ing, impersonation, etc.).

## Open questions for plan review
1. R2-A scope: is multi-company hardening pre-launch-mandatory given tenant #1 is
   single-company, or does it slip to a fast-follow? (Readiness register lists it; POST
   /companies + switcher ARE shipped, so drift risk is real.)
2. T-1 runtime budget: full treasury+GL+fiscal PG leg in preflight may be minutes — CI-only?
3. R2-E If-Match vs updated_at-in-payload: pick per existing API conventions.
