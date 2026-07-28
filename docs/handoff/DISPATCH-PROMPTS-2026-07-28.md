# Dispatch prompts — 2026-07-28 counting program

Paste-ready prompts for Codex Desktop. **Two distinct lanes, two distinct repositories.**

| Lane | Repository | Working directory | Branch |
|---|---|---|---|
| **A** — server counting completion | ERP monorepo (`otospexsolutions/erp`) | `/Users/houssamr/Projects/syneriva/apps/erp.counting-completion` (git worktree) | `feat/live-counting-completion` |
| **B** — mobile counting hardening | **`erp-mobile` — a SEPARATE repo**, sibling of `apps/` | `/Users/houssamr/Projects/syneriva/erp-mobile` | create off `codex/location-hierarchy-mobile` |

`erp-mobile` is **not** inside `apps/erp`. Do not run Lane B from the ERP repo.

Do not run either lane from `/Users/houssamr/Projects/syneriva/apps/erp` — that is the shared `dev` checkout and must not be edited directly.

---

## LANE A — paste this into Codex Desktop

```
You are working in the AutoERP monorepo: Laravel API in apps/api, React web app in
apps/web, Tauri POS in apps/pos.

START HERE. Work only in this directory:

    cd /Users/houssamr/Projects/syneriva/apps/erp.counting-completion

This is a dedicated git worktree on branch `feat/live-counting-completion`, based on
`dev` @ 32da5bd9d (which equals origin/dev).

STEP 1 — verify your ground before touching anything:

    git status && git log --oneline -3

Expect branch `feat/live-counting-completion` with a clean tree, and HEAD on a commit
whose message begins "docs(handoff):". If the tree is dirty or the branch differs,
STOP and report rather than proceeding.

STEP 2 — read your brief in full and follow it exactly:

    docs/handoff/CODEX-live-counting-completion-2026-07-28.md

Every file:line citation in that brief was verified on 2026-07-28. Re-verify each one
before you change the file. If a citation does not match what you find, STOP and report
the discrepancy — do not guess at what was meant.

WHAT YOU ARE BUILDING — six tasks, strictly in order, each with a review gate that must
pass before you start the next:

  A1 (S) Counting must preserve precise placements inside the counted subtree.
         LocationNodeService::assignProduct() unconditionally overwrites node_id, so
         counting aisle A1 flattens A1/R2/B7 down to A1. Re-home only when the current
         placement is OUTSIDE the counted subtree. Use LocationNode::scopeSubtreeOf —
         a bare prefix LIKE is wrong (A1 would match A10). Placement labels only; no
         stock, quantity or movement logic may change.

  A2 (S) POS terminal must pick up a sales-block without a restart. CLIENT-SIDE ONLY —
         the server already sends active_counting_block on GET /pos/terminals/{id}
         because TerminalController::show() eager-loads location. Do NOT add a server
         change. The gap is that pullTerminalState()'s TerminalStateResponse in
         apps/pos/src/lib/sync/syncService.ts neither declares nor projects the field.
         Both new fields must be optional in the wire shape, following the existing
         defensive pattern used for fiscal_schema_version. A missing field means "no
         block" — never let it wedge a till into a permanent block.

  A3 (L) Late-syncing sales must not silently double-subtract. Two halves: a
         pre-finalize unsynced-device warning that gates the Finalize button, and a
         post-finalize read-only detector that attributes the residual to the count it
         slipped past. Detect and surface only — do NOT auto-correct stock.

  A4 (M) Show the reviewer what will actually post, before they finalize. A read-only
         dry-run of the replay for a count in PendingReview, reusing MovementReplayService
         rather than duplicating its math.

  A5 (S) Arabic i18n for counting — roughly 199 missing counting.* keys in
         apps/web/src/locales/ar/inventory.json. Do this last; it must not block A1-A4.

  A6 (S) batchAddProducts accepts any string as a product ID. products.*.productId
         validates as a bare `string` — no uuid rule, no exists check — and the barcode
         lookup only runs when productId is falsy. A barcode sent in the productId field
         is appended to scope_filters['product_ids'] unvalidated and returns "success",
         silently poisoning the count's product list. Validate as a real product
         reference, UUID-shaped AND existing within the caller's company. Reject per-item
         into the existing errors[] array — do not fail the whole batch, or a counter
         scanning 40 items loses the other 39. The mobile lane fixes the client side
         concurrently; this is the durable half that protects against any client.

HARD CONSTRAINTS — violating the first one corrupts a parallel lane:

  * A parallel session owns `feat/pos-cash-rounding` and is mid-flight on a SALE_RECEIPT
    v3 canonical payload migration. Canonical event-version changes are not parallel-safe.
    You must NOT touch PosCoreReceiptProjection, the fiscal payload registry,
    StrictCanonicalParser, CanonicalPayloadReader, BestEffortPayloadParser, any golden
    vector, or any drift gate. A3 is deliberately designed to need none of them. If you
    find yourself reaching for the projection, you have taken a wrong turn — stop and
    report.

  * NEVER run the full PHPUnit suite — it crashes the owner's machine. Run tests by path
    only, e.g. ./vendor/bin/phpunit tests/Feature/Inventory/ZoneScopedCountingTest.php

  * TDD is mandatory. Write the failing test first, watch it fail, then implement.

  * Constructor injection only, with private readonly. Never the app() helper.

  * Strict typing. No mixed in PHP, no any in TypeScript. PHPStan level 8, zero new errors.

  * Precision contract: quantities are decimal STRINGS at scale 4 via QuantityScale/bcmath.
    No floats, no parseFloat/Number() on quantity or money. Frontend uses <QuantityInput>.

  * The UoM guards are live and their baselines are EMPTY. Do not add baseline entries to
    re-allow drift.

  * All user-facing frontend text through t(). Add FR keys alongside EN.

REVIEW GATES — after each task, before starting the next:

  Dispatch the reviewer agent named in the brief for that task, pinned to Opus
  (inventory-costing-reviewer for A1/A3/A4, fiscal-pos-reviewer for A2 and as the second
  reviewer on A3, frontend-conventions-reviewer for A4's frontend and A5). Every finding
  must cite file:line. SAVE each review to a file under
  docs/handoff/gate-reviews-counting/ — never inline only. Fix findings, re-review,
  iterate until APPROVE, then commit the review record alongside the fix.

YOU DO NOT MERGE AND YOU DO NOT PUSH TO dev. Commit on feat/live-counting-completion and
stop there. The orchestrating session owns the merge.

WHEN DONE, write docs/handoff/counting-completion-report.md stating what changed, what you
verified, anything you could NOT verify, any migration you added and why it is
self-guarding, and any residual risk the owner should know before a tester starts.

IF YOU GET STUCK: report the blocker with evidence and stop. Do not invent a workaround
that widens scope, and never touch the frozen fiscal files to get unblocked. A partial
lane with an honest report is worth far more than a complete lane with a corrupted
fiscal payload.
```

---

## LANE B — paste this into Codex Desktop (different repository)

```
You are working in erp-mobile, the React Native + Expo mobile app for AutoERP. This is a
SEPARATE repository from the ERP monorepo — it is a sibling of apps/, not inside it.

START HERE. Work only in this directory:

    cd /Users/houssamr/Projects/syneriva/erp-mobile

STEP 1 — verify your ground before touching anything:

    git status && git log --oneline -3

Expect branch `codex/location-hierarchy-mobile` at b610560, with ONE unstaged file:
docs/handoff/HANDOVER-mobile-scan-capture.md. That file is someone else's uncommitted
work — do NOT stash, discard, commit or modify it. Report it and wait for a decision.

Then create a dedicated branch off current HEAD for your work.

STEP 2 — read your brief in full. It lives in the ERP repo (read-only reference):

    /Users/houssamr/Projects/syneriva/apps/erp.counting-completion/docs/handoff/CODEX-mobile-counting-hardening-2026-07-28.md

SCOPE CORRECTION — read this before planning anything:

This lane was originally scoped as "build live inventory counting on mobile." That scope
is CANCELLED. A full audit on 2026-07-28 confirmed the live-counting mobile work is
already complete and merged since 2026-07-07, passing 89 counting tests across 11 suites
with none skipped. Already DONE, do NOT rebuild: counted_at_device + device_now on both
the online and offline-drain paths with device_now correctly read fresh at drain time;
the both-or-neither guard on those two fields; zone scope end-to-end including location
and zone pickers and the correct {location_id, zone_ids} branching; session-header zone
label and blocking/advisory banners; the onboarding-worklist screen; barcode scanning
during count entry.

WHAT YOU ARE ACTUALLY BUILDING, in priority order:

  B1 (M) REAL BUG — scan-to-draft silently corrupts the draft's product scope.
         app/(app)/counting/draft/[id]/scan.tsx stores the raw scanned barcode string
         into DraftCounting.productIds, and draftSyncService.ts sends it as
         {productId: <barcode>} rather than {barcode}. Server-side, batchAddProducts only
         performs a barcode lookup when productId is ABSENT — so the lookup never fires
         and the raw barcode is written into scope_filters.product_ids with no existence
         validation, while the API returns success. Fix client-side. Choose between
         sending {barcode: <value>} versus resolving barcode to productId on-device based
         on what survives OFFLINE — drafts are built offline and synced later, so if
         on-device resolution needs a network call it is the wrong choice. State your
         reasoning. Also write up (do NOT fix here, and do NOT edit the ERP repo) the
         server-side gap that batchAddProducts accepts an unvalidated productId.

  B2 (M) Pending counts retry forever with no ceiling and no recovery UI.
         useBackgroundSync.ts leaves failed items in the queue and retries every 30s
         indefinitely. Drafts already solve this properly — draftSyncService.ts has
         MAX_RETRY_ATTEMPTS = 3 plus a dedicated sync-errors screen. Mirror that proven
         pattern. Distinguish retryable (network/5xx) from terminal (4xx — session
         finalized, item gone) failures; terminal failures should park immediately rather
         than burn three attempts.

  B3 (S) useStorageLimits() in src/features/counting/services/storageLimits.ts is fully
         built and unit-tested but has ZERO call sites. Either wire it into the real call
         sites and surface the warnings, or delete it. Recommend one and say why. If you
         wire it, caps must warn and block NEW work — never discard already-captured counts.

  B4 (S) No idempotency key on count submission, unlike expenses. Combined with unbounded
         retry this can re-stamp timestamps and raise false clock_skew flags on counts that
         were fine. Add a client-generated request ID, or check already-counted state
         before resubmitting.

  B5     DECISION ONLY — there is no "flagged for review" visual state on the mobile
         session/item screens. This may well be intentional, since review happens on the
         web finalize page. Write up the trade-off for the owner. DO NOT BUILD IT.

  B6     HANDBACK ONLY — the ERP repo's docs/handoff/HANDOVER-live-counting-mobile.md
         describes all the already-done work above as outstanding. Report that it needs a
         status header. Do NOT edit the ERP repo from this lane.

CONSTRAINTS:

  * TDD. Failing test first. The counting suites are green today — keep them green, and
    run counting tests by path.
  * Strict TypeScript. No any; use unknown plus type guards.
  * Quantities stay decimal strings. No parseFloat/Number() on quantity.
  * All user-facing text through the existing i18n mechanism. This app's counting UI is
    French-facing ("Ventes suspendues", "Fenêtre d'ambiguïté") — match it.
  * Follow this repo's existing patterns rather than importing ERP-monorepo idioms.

REVIEW GATES: after B1 and again after B2, dispatch an adversarial review pinned to Opus,
citing file:line, and SAVE the review to a file under docs/handoff/ in this repo — never
inline only. Fix, re-review, iterate to APPROVE before moving on. B3 and B4 may share one
review round.

YOU DO NOT PUSH. The owner pushes this repository personally. Leave your branch committed
and unpushed.

WHEN DONE, write docs/handoff/mobile-counting-hardening-report.md covering what changed,
what you verified, what you could not verify, the server-side batchAddProducts validation
gap from B1, and the B5 decision put cleanly to the owner.
```

---

## Not a Codex lane — owner-only

- **Lane D is already delivered** (`docs/guides/fr/guide-demarrage.md` + `docs/qa/smoke-test-fr.csv` on branch `docs/launch-guide-fr`, commit `6581a971b`). It needs validation against staging once the deploy chores are cleared, not more build work.
- **Clearing the staging deploy chores** (the seven stacked checklists) stays manual — Lane C was not opened.
- **Secret rotation** needs provider-side revocation and cannot be delegated.
