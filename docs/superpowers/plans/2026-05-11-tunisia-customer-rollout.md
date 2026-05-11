# Tunisia Customer Rollout — Tenant-Isolation Sweep Ship

> **For:** Team member taking the dev test + main promotion + customer rollout.
> **Goal:** Get the IziPOS desktop app (Tauri 2 at `apps/pos/`) into the first Tunisian customer's hands, safely, on top of the tenant-isolation sweep work that's been landing on `feat/tenant-isolation-sweep-execution`.
> **Author:** Opus, after this session's reviews and investigation.
> **Date:** 2026-05-11.

---

## What you need to know

The branch `feat/tenant-isolation-sweep-execution` has been the home of a long-running tenant-isolation sweep. Codex implements, Opus reviews. As of this doc:

- **Last Opus-reviewed lock SHA:** `86d2a433` (post-B42, batches B1–B42 all locked).
- **Branch HEAD:** `b7e111e6` (B51 submitted; B43–B51 reviewed by Codex internally but not yet locked by Opus).
- **Branch divergence:** 450 commits ahead of `origin/dev`, 552 ahead of `origin/main`.
- `origin/dev` is 200 commits ahead of `origin/main` — including PR #37 cash-counting (already merged to dev).

We are **not** going to ship from HEAD. We ship from `86d2a433` — the last fully Opus-reviewed point.

---

## Investigation results — answers to the open questions

### 1. PR #37 cash-counting overlap — **no conflict expected**

PR #37 is **already merged to dev** (state: MERGED, baseRefName: dev). It touches:
- `apps/api/app/Modules/POS/...` (cash count validation, Z-report sync, hash service)
- `apps/api/app/Modules/Compliance/...` (fraud settings, listeners)
- `apps/pos/src/...` (Tauri desktop UI: CashReconciliationSection, EndOfDayPreviewModal, ManagerPinPanel)
- `apps/pos/src-tauri/src/printing/receipt_template.rs`

The tenant-isolation sweep on this branch touches `apps/web/src/features/...` and various `apps/api/app/Modules/...` files (api.taxation, api.compliance, api.accounting, api.pos-stabilization). There **may** be touch-overlap in `apps/api/`, but PR #37 was merged into dev before this branch diverged in a meaningful way, so a merge should auto-resolve. **You will need to do a real `git merge --no-commit` and visually inspect** — see step 2 below.

### 2. Fiscal chain — **keep enabled, no Tunisia-specific toggle exists**

There is **no per-country fiscal-chain toggle**. The hash chain is gated by `FiscalCategory::requiresHashChain()` (see `apps/api/app/Modules/Document/Domain/Enums/FiscalCategory.php`) and applies to TaxInvoice / CreditNote / FiscalReceipt / DeliveryNote / ReturnNote — across all countries.

**Recommendation: leave it enabled.** No legal requirement in Tunisia mandates it (NF525 is French), but disabling it would mean:
- Removing or skipping `FiscalHashService` calls and listeners (code change, not config).
- Breaking the CI gate `ci/fiscal-chain-gates` (PR #77).
- Losing the ability to verify chain integrity later if you decide to turn it back on.

The chain has been running green on this branch (per project memory: "fiscal verify-chains now green on all 5 verticals"). Just confirm it stays green post-merge via:
```bash
cd apps/api && php artisan compliance:verify-fiscal-chains
```
If it ever goes red, that's a stop-ship signal — investigate before continuing.

### 3. Customer hardware — confirmed same as tested

Same printer / drawer / scanner models we've already validated. No new hardware-specific risk for this rollout.

### 4. Staging server — confirmed available

You have staging access. **All testing in this doc happens on staging, not on the customer's machine.**

---

## The ship plan (go / no-go gated)

### Phase 0 — Pre-flight (15 min)

- [ ] Confirm you are on the right working copy:
  ```bash
  cd /Users/houssamr/Projects/syneriva/apps/erp
  pwd && git status --short
  ```
- [ ] Confirm `86d2a433` exists and is the last Opus-locked SHA:
  ```bash
  git log --oneline 86d2a433 -1
  # Should print: 86d2a433 chore(tenant-isolation): lock web.tanstack-keys batches 39-42 — Opus APPROVE
  ```
- [ ] Confirm dev tip and main tip:
  ```bash
  git fetch
  git log --oneline origin/main -1
  git log --oneline origin/dev -1
  ```
- [ ] Confirm verify-history is green at the cutoff:
  ```bash
  git checkout 86d2a433
  cd apps/api && php artisan sweep:inventory:verify-history
  # Expect: verified <N> event(s) across 1205 callsite(s); 0 problem(s)
  ```

**Go gate:** all four checks pass. Stop and report if any fail.

---

### Phase 1 — Merge tenant-isolation up to cutoff into dev (30 min)

- [ ] Create a local merge branch:
  ```bash
  git checkout origin/dev
  git checkout -b chore/merge-tenant-isolation-86d2a433
  ```
- [ ] Merge the tenant-isolation work up to the cutoff SHA (NOT past it):
  ```bash
  git merge --no-ff --no-commit 86d2a433
  ```
- [ ] **Inspect any conflicts in `apps/api/`.** This is where PR #37 territory overlaps. Most likely zero conflicts (different files), but visually scan:
  ```bash
  git status
  git diff --stat | head -30
  ```
  If there are conflicts, resolve them keeping **both** changes (PR #37 already lives on dev; tenant-isolation work adds the cross-tenant scoping). When in doubt, prefer dev's version of any `apps/api/app/Modules/POS/` or `apps/api/app/Modules/Compliance/` line that came from PR #37, and rerun the API tests below.
- [ ] Finalize the merge:
  ```bash
  git commit -m "chore: merge feat/tenant-isolation-sweep-execution up to 86d2a433 into dev

  Tenant-isolation sweep batches B1-B42 (locked, Opus-reviewed). Stops at last
  fully reviewed SHA; B43-B51 remain on the feature branch unmerged.

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
  ```
- [ ] Run full preflight from repo root:
  ```bash
  ./scripts/preflight.sh
  ```
  This runs PHPStan level 8, Pint, PHPUnit, TypeScript check, ESLint, vitest. **All must be green.**
- [ ] Run verify-history one more time:
  ```bash
  cd apps/api && php artisan sweep:inventory:verify-history
  ```
- [ ] Run fiscal-chain verification:
  ```bash
  cd apps/api && php artisan compliance:verify-fiscal-chains
  ```

**Go gate:** preflight green, verify-history clean, fiscal chains green.

If green, push the merge branch and open a PR `chore/merge-tenant-isolation-86d2a433 → dev`:
```bash
git push -u origin chore/merge-tenant-isolation-86d2a433
gh pr create --base dev --title "chore: merge tenant-isolation sweep B1-B42 into dev" \
  --body "Cutoff at 86d2a433 (last Opus-locked). B43+ stays on feature branch for separate review."
```

Wait for CI green. **Then merge to dev.** Do NOT skip CI even if preflight passed locally — CI runs in a clean environment with different env vars and catches things local doesn't.

---

### Phase 2 — Smoke test on staging (1–2 hours)

After dev is updated, deploy the staging API + staging-frontend admin from the new dev tip. Then build the Tauri POS pointed at staging.

- [ ] **Deploy staging API.** Follow the existing staging deploy procedure (Dokploy redeploy or whatever the team uses).
- [ ] **Build the Tauri desktop pointed at staging:**
  ```bash
  cd apps/pos
  # Set API base URL to staging (env var is something like VITE_API_BASE_URL — check apps/pos/.env.staging)
  cp .env.staging .env  # or whatever the project uses
  pnpm install
  pnpm tauri build
  # Binary lands under apps/pos/src-tauri/target/release/bundle/
  ```
- [ ] Install the binary on a clean test machine (same OS as the customer's).

#### Smoke test sequence (run all, mark each gate)

##### Gate A — Auth bootstrap (highest-risk failure mode from the sweep)

- [ ] Cold start: launch IziPOS with no cached session. Log in. Confirm:
  - Dashboard loads without stuck spinners.
  - Product list populates (no infinite loading).
  - Tables / floors view populates if applicable.
- [ ] Force a reload (Ctrl+R or restart) during a session. Confirm same as above.
- [ ] Log out, log back in as a different user. Confirm no stale data shown.

**Stop-ship trigger:** any view shows a permanent loading spinner or empty state after auth completes. That's the `enabled: hasTenantScope` race biting in production. If you see it, capture the URL/view, file a bug, and **do not** promote to main.

##### Gate B — POS critical path (must work for the customer)

- [ ] **Open a shift.** Set opening cash count. Confirm shift status goes to OPEN.
- [ ] **Take 3 sales.** Mix of cash + card. Use products, services, and one with a discount.
- [ ] **Print one receipt.** Confirm thermal printer outputs cleanly. Confirm receipt template formatting (Tunisia uses TND currency, French locale typically — confirm with customer's preference if unsure).
- [ ] **Refund one sale.** Confirm refund flow completes and stock adjusts.
- [ ] **Open the customer display** (if the customer uses dual-monitor). Confirm it works AND the lock-out fix from `docs/superpowers/plans/2026-03-23-windows-desktop-fixes.md` is in. If single-monitor, ensure the customer display is disabled by default.
- [ ] **Close the shift.** Generate X-report (preview), then Z-report (final). Confirm:
  - Cash count entry works (this is the PR #37 territory — variance flow, manager PIN if required, etc.).
  - Z-report numbers reconcile to sales taken.
  - Z-report PDF/print works.
- [ ] **Verify fiscal chain after the shift:**
  ```bash
  cd apps/api && php artisan compliance:verify-fiscal-chains
  ```
  Must remain green. Any new break here is a stop-ship.

**Stop-ship trigger:** any step fails AND the failure is reproducible.

##### Gate C — Web admin (lower priority, but customer may use it)

- [ ] Log into the web admin at the staging URL.
- [ ] Navigate: Products, Inventory, Partners, Reports — each list should load. No infinite loading spinners.
- [ ] Open one product detail page. Confirm it loads.
- [ ] Open the Z-reports list. Confirm the shift you just closed appears.

**Stop-ship trigger:** same as Gate A — stuck loading states.

##### Gate D — Tunisia-specific quick checks

- [ ] Confirm company currency is TND. Receipts show "TND" or "د.ت" depending on locale config.
- [ ] Confirm date format is DD/MM/YYYY (or whatever the customer expects).
- [ ] Confirm the Tunisia VAT rate (typically 19%) is the default for new products IF the company is set to country TN. Check `CountryFiscalRulesProvider.php` if uncertain.
- [ ] Confirm language: most Tunisian users want French. Switch the user's language to French and verify all touched POS screens are translated. Watch for hardcoded English strings — the sweep added `t()` keys in some places but not all.

**Stop-ship trigger:** wrong currency / tax rate on a real sale; missing translations on the cashier-facing POS screens.

---

### Phase 3 — Promote dev → main + tag release (30 min)

If all gates pass:

- [ ] **Merge dev → main:** open a PR `dev → main`, run CI, merge.
- [ ] **Important pre-promotion step from project memory:** if any hotfix has landed on main since the last dev → main promotion, merge main back into dev first to avoid phantom deletions. Quick check:
  ```bash
  git fetch
  git log --oneline origin/main..origin/dev | head -5
  git log --oneline origin/dev..origin/main | head -5
  ```
  If `main..dev` shows commits unique to main (rare but possible after hotfixes), merge `main → dev` first, then `dev → main`.
- [ ] After dev → main lands, tag the release:
  ```bash
  git checkout main && git pull
  git tag -a v0.1.0-tn-rc1 -m "First Tunisia customer release candidate"
  git push origin v0.1.0-tn-rc1
  ```

---

### Phase 4 — Build customer binary + deliver (1 hour)

- [ ] **Build the production binary from main:**
  ```bash
  git checkout main && git pull
  cd apps/pos
  cp .env.production .env  # production API URL
  pnpm install
  pnpm tauri build
  ```
  Confirm bundle exists under `apps/pos/src-tauri/target/release/bundle/`. On macOS you'll see `.dmg`/`.app`, on Windows `.msi`/`.exe`, on Linux `.AppImage`/`.deb` — match the customer's OS.
- [ ] **Code-sign / notarize** if the customer's OS requires it (macOS notarization, Windows code signing). If you skip this, the customer will see a "Windows protected your PC" warning — acceptable for a small rollout but worth flagging to them in advance.
- [ ] **Deliver the binary** to the customer through whatever channel you use (USB, secure download link, in-person install).

---

### Phase 5 — Post-rollout monitoring (first 48 hours)

- [ ] **Daily check** for the first 2 days:
  ```bash
  cd apps/api && php artisan compliance:verify-fiscal-chains
  ```
  Confirm green.
- [ ] **Daily check** that no error spikes in the API logs.
- [ ] **Reach out to the customer** end-of-day-1 to ask: any UI behaved unexpectedly? Any stuck screens? Any receipts that printed wrong?
- [ ] Keep `86d2a433` and the merge branch tagged so you can revert quickly if needed.

---

## Rollback plan

If something breaks after the customer is on the new binary:

1. **Quick fix path:** if the bug is small, fix on `dev`, run preflight + smoke test, merge to main, ship a patch binary.
2. **Rollback path:** revert the dev → main merge:
   ```bash
   git checkout main
   git revert -m 1 <merge-commit-sha>
   git push
   ```
   Then rebuild the binary from the reverted main and redeliver. The customer goes back to whatever they had before (or to the prior tagged release).
3. **Data implications:** the tenant-isolation sweep does **not** include data migrations that would block a rollback. PR #37 (cash-counting) DID add migrations — those stay applied even if you revert, which is fine; the schema is forward-compatible.

---

## Open items / things I couldn't verify from here

- [ ] **B43–B51 batches** exist on the feature branch (Codex-reviewed internally, not Opus-locked). They are NOT included in the dev merge. If you want them in for the customer, ask Opus to review/lock them and then merge again on top.
- [ ] **Locale: French vs Arabic vs both.** I don't know the customer's preference. Default IziPOS comes with English + French; Arabic would need additional translation work.
- [ ] **Manager PIN flow** from PR #37 — I didn't validate it end-to-end myself. If the customer's workflow requires manager-PIN authorization for variance > threshold, walk through it explicitly during Gate B.
- [ ] **Currency precision.** Memory file flags a recurring bug: "NEVER use `number_format((float) $value, ...)` for monetary values." If you see any cents off in the receipts or Z-report, that's the bug pattern. Use `CurrencyScale::bcformat($value, $scale)` — fix and reship.
- [ ] **Hardware-specific tauri config.** The customer's printer/drawer model — confirm it's wired up in `apps/pos/src-tauri/src/printing/`. Quick check: is there a printer entry that matches their model name?

---

## Summary table — what passes / fails the ship

| Phase | Stop-ship trigger |
|---|---|
| 0 Pre-flight | `verify-history` not 0 problems at cutoff |
| 1 Merge to dev | preflight fails, fiscal-chain breaks, conflicts unresolved |
| 2A Auth bootstrap | any view shows permanent stuck loading after login |
| 2B POS critical path | shift open / sale / receipt / Z-report doesn't complete |
| 2B Fiscal chain | `verify-fiscal-chains` goes red after the test shift |
| 2C Web admin | stuck loading on product/inventory/partners list |
| 2D Tunisia checks | wrong currency, missing translations on POS screens |
| 3 Promote | any unique main commits not yet merged back to dev |

Anything else: judgment call. When in doubt, ping Opus or escalate.

Good luck. Customer expectations are reasonable — first rollout, single tenant, hardware we know. The risk is bounded.
