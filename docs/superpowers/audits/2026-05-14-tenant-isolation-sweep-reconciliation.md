# Tenant-Isolation Sweep — Post-Regeneration Reconciliation

**Date:** 2026-05-14
**Author:** Claude Opus 4.7 (in-session)
**Scope:** Reconcile the `tenant-isolation-sweep-inventory.yml` state after the
2026-05-13 post-PR-#93 regeneration. **Read-only — no fixes applied.**
**Trigger:** `sweep:inventory:status` reports a state ("0 callsites, 27 clusters
pending") that contradicts the YAML's actual callsite data.

---

## Bottom line

**The scary-looking numbers are a tooling artifact, not a security backlog.**

- The big tenant-isolation sweep (PR #93, merged 2026-05-13) **holds** — a fresh
  scanner re-run finds **zero** "fixed in YAML but unsafe in code" regressions.
- The append-only command-event chain is **intact** — `verify-history` walks
  1240 callsites / 6463 events with 0 problems.
- The apparent "discrepancy" is a **stale `progress:` block** in the YAML, which
  the default `sweep:inventory:status` prints verbatim.
- The "59 non-fixed callsites" (32 pending + 27 needs_recheck) are **review
  bookkeeping**, not vulnerabilities: the `php_ast_find` scanner is a
  *candidate-finder* (it flags every `find()`/`first()` on a tenant model), and
  the 2026-05-13 regenerate added candidates without anyone running the review
  pass since. **10 of 10 spot-checked callsites are already tenant-scoped in
  code**, several with explicit sweep-reference comments.

The genuine remaining work is small and well-bounded — see "Recommended sequence".

---

## How the sweep system works (for context)

- Two source-of-truth artifacts. This report covers the **tactical** one:
  `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — one row per
  code callsite, mechanically scanned. Mutated **only** via the
  `sweep:inventory:*` artisan commands; never hand-edited.
- **Code is the source of truth.** The YAML carries ownership/status metadata;
  pass/fail comes from re-scanning code, never from the YAML.
- Status enum: `pending → claimed → in_progress → under_review → fixed`, plus
  `blocked`, `deferred`, `needs_recheck`, `stale_orphan`.
- Three scanners feed `sweep:inventory:generate`:
  - `php_presentation_exists` — bare `exists:` validation rules on tenant tables.
  - `php_ast_find` — AST detector for `find()`/`findOrFail()`/`first()` calls on
    tenant-owned models. **This is a candidate-finder, not a vulnerability
    detector** — it flags the call regardless of whether a `->where('tenant_id')`
    scope precedes it. A human `review` confirms the scoping and moves the row
    to `fixed`.
  - `manual` — hand-curated stub.

---

## Findings

### F1 — `verify-history`: chain intact

```
verified 6463 event(s) across 1240 callsite(s); 0 problem(s).
```

The append-only command-event history is sound. No tampering, no broken chains.

### F2 — `--drift`: zero security regressions

`sweep:inventory:status --drift` re-runs all three scanners against current
code and compares emitted stable-keys to YAML state:

```
yaml_says_fixed_code_unsafe: 0      <- no "fixed" row is still unsafe in code
code_safe_yaml_pending:      0      <- (see F5 for why this is expected, not alarming)
unmapped_in_scanner_output:  0      <- clean cluster mapping
```

**`yaml_says_fixed_code_unsafe: 0` is the key safety result:** every one of the
1180 callsite-level `fixed` rows is genuinely scoped in code. PR #93's sweep did
not regress.

### F3 — the `progress:` block is stale (root cause of the "discrepancy")

The default `sweep:inventory:status` prints the YAML's stored `progress:` block
verbatim — it does **not** recompute. That block currently reads:

```yaml
progress:
  total_callsites: 0          # <- WRONG: there are 1240
  total_clusters: 29          # <- there are 31
  by_status: { pending: 27, blocked: 2, fixed: 0, ... }   # <- counts CLUSTERS, all pre-population
```

It was never recomputed after the 1240 callsites were populated and worked. So
the default status command is **misleading** — it describes a bootstrap state,
not reality. This is a **tooling bug**, not lost work.

**Actual callsite-level state (parsed directly from the YAML):**

| Status | Count |
|---|---:|
| `fixed` | 1180 |
| `pending` | 32 |
| `needs_recheck` | 27 |
| `deferred` | 1 |
| **Total** | **1240** |

### F4 — cluster-level `status` is also stale

25 clusters store `status: fixed`, but **9 of them now carry non-fixed
callsites** (`api.treasury`, `api.catalog`, `api.document`, `api.inventory`,
`api.pos-stabilization`, `api.pricing`, `api.taxation`, `api.cart`,
`api.marketplace`). The 2026-05-13 `generate` added new callsites under these
clusters without rolling the cluster `status` back. **The cluster `status` field
cannot be trusted; the callsite rollup is authoritative.**

### F5 — the 59 non-fixed callsites are review bookkeeping, not vulnerabilities

All 59 (`32 pending + 27 needs_recheck`) come from the `php_ast_find` scanner.
History provenance from the 2026-05-13 regenerate (commit `7f7177dd`):

- **27 `needs_recheck`** — were previously `fixed`; the regenerate's
  `stale_mark` reverted them (`from_status: fixed → needs_recheck`) because the
  `php_ast_find` scanner still emits their key. Since the scanner emits *every*
  `find()` candidate, a previously-fixed row coming back is **expected
  behaviour**, not a regression — it needs `recheck-clear` after confirming the
  scope is still present.
- **32 `pending`** — `from_status: null → pending`; brand-new callsites the
  regenerate discovered (code merged since the prior scan). Each needs a
  `review` pass.

`code_safe_yaml_pending: 0` (F2) is consistent with this: the `php_ast_find`
scanner emits every candidate's key whether or not it is scoped, so no pending
row is ever "absent from scanner output". The metric cannot distinguish
safe-pending from unsafe-pending — only human review can.

**Spot-check — 10 of 10 sampled callsites are already tenant-scoped in code:**

| Callsite | Status | Code |
|---|---|---|
| `api.catalog.008` ModifierGroupController:68 | needs_recheck | `->where('tenant_id')->where('company_id')->findOrFail()` |
| `api.treasury.030` PaymentRefundService:423 | needs_recheck | `->where('tenant_id')->where('company_id')->find()` + "Defense-in-depth" comment |
| `api.pos-stabilization.054` ReceiptPaymentService:108 | pending | `->where('tenant_id')->where('company_id')->findOrFail()` + guard comment |
| `api.treasury.063` BankReconciliationService:44 | pending | `->where('tenant_id')->findOrFail()` *(tenant-only — see F6)* |
| `api.treasury.067` PaymentRepositoryController:46 | pending | `->where('tenant_id')->findOrFail()` *(tenant-only)* |
| `api.treasury.073` PaymentMethodController:44 | pending | `->where('tenant_id')->findOrFail()` *(tenant-only)* |
| `api.treasury.071` PaymentRefundController:33 | pending | `->where('tenant_id')->findOrFail()` *(tenant-only)* |
| `api.treasury.064` VendorRefundService:51 | pending | `->where('tenant_id')->where('company_id')->lockForUpdate()` + comment |
| `api.document.046` CreditNoteService:310 | pending | `->where('tenant_id')->where('company_id')->lockForUpdate()` + `api.document.007` comment |
| `api.inventory.034` WeightedAverageCostService:225 | pending | `->where('tenant_id')->where('company_id')->lockForUpdate()` + comment |

Sample size is 10/59 (~17%), unanimous. This characterises the backlog as
review-bookkeeping debt with a low risk profile — it does **not** individually
certify the other 49. The full review pass (recommended below) is what closes
that gap.

### F6 — one genuine judgement call: `tenant_id`-only scoping

Several pending Treasury callsites (`api.treasury.063/067/071/073`, all in
`PaymentRepository` / `PaymentMethod` / `PaymentRefund` controllers) are scoped
`->where('tenant_id', ...)` **without** a `->where('company_id', ...)` predicate.
Whether that is a finding depends on whether the resource is tenant-level or
company-level. The sweep's Treasury-cluster convention (per
`feedback_sweep_audit_trail_anchoring` / the api.compliance round-2 hardening)
is **both predicates on every read whose anchor came from a route param**. This
is the one place in the 59 that may need an actual code change rather than a
status transition — it should be an explicit owner decision per resource, not a
blanket fix.

### F7 — the 5 non-fixed clusters carry zero callsites (confirmed)

| Cluster | Stored status | Callsites | Meaning |
|---|---|---:|---|
| `api.unmapped` | in_progress | 0 | `--unmapped` confirms empty — clean mapping, nothing to reclassify |
| `web.form-selectors` | pending | 0 | scanner never built (only `web.tanstack-keys` got one — 849 fixed) |
| `web.stores-localstorage` | pending | 0 | scanner never built |
| `tauri.sqlite-cache` | blocked | 0 | per master plan, Tauri tenant-isolation is BLOCKED on the POS orchestrator branch merge |
| `tauri.sync-envelope` | blocked | 0 | same |

These are **scanner-build / structural follow-ups**, not pending fix work —
consistent with the prior session's note.

### F8 — Category C: the one `deferred` callsite

`api.identity-company.001` — `UpdateCompanyRequest.php:43`, resource
`tax_configurations`. Deliberately deferred 2026-05-09: `tax_configurations` is
a country-scoped global reference table with no `tenant_id`/`company_id` columns,
so cross-tenant exfiltration is structurally impossible — a scanner
false-positive. Un-defer path is documented in
`docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md`:
remove `tax_configurations` from `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES`,
regenerate, and the row (plus siblings in catalog/taxation) auto-marks
`stale_orphan`.

---

## What the non-fixed callsites are (by cluster)

| Cluster | pending | needs_recheck | Notes |
|---|---:|---:|---|
| `api.treasury` | 13 | 1 | BankReconciliation, VendorRefund, Payment{Repository,Method,Refund,Controller}. **F6 applies to 4 of these.** |
| `api.pos-stabilization` | 7 | 4 | ReceiptPaymentService, OrderManagementService, ReceiptSyncService, ReceiptCreationService |
| `api.inventory` | 4 | 4 | WeightedAverageCost, StockAdjustment, GoodsReceipt, Location/InventoryCounting |
| `api.document` | 3 | 3 | CreditNoteService, DocumentPdfController, AgedReceivables, ReturnNote |
| `api.catalog` | 2 | 8 | Modifier/ModifierGroup controllers, CategoryController |
| `api.taxation` | 1 | 2 | Withholding controllers |
| `api.pricing` | 1 | 3 | PricingService/Controller, CouponApplicationService |
| `api.cart` | 1 | 0 | CartConversionService |
| `api.marketplace` | 0 | 2 | SyncListingOn{Price,Stock}Change listeners |
| **Total** | **32** | **27** | |

---

## Recommended sequence (next session — fixes, not in this pass)

1. **Fix the stale `progress:` block + cluster-status rollup** *(tooling, cheap,
   unblocks trust).* Either `sweep:inventory:generate` should recompute
   `progress` and roll cluster status from callsites, or add a recompute step.
   Until this is done, `sweep:inventory:status` (default) is misleading.
2. **Category C — un-defer `api.identity-company`** *(smallest, fully
   documented).* Ship the `DEFAULT_GUARDED_TABLES` scanner fix per the
   2026-05-04 audit doc; auto-resolves the deferred row + catalog/taxation
   siblings.
3. **Run the review pass on the 59** *(the bulk — but fast).* Most are already
   scoped (F5): `recheck-clear` the 27, `review → fixed` the already-scoped
   pending rows. Work cluster by cluster via the `sweep:inventory:*` workflow
   (claim → start → submit → review). Start with `api.treasury` (largest).
4. **Resolve F6 explicitly** — for the `tenant_id`-only Treasury controller
   reads, get an owner decision on whether `company_id` is required per
   resource, then fix or annotate accordingly.
5. **Consider a `php_ast_find` precision pass** — if the review pass confirms
   the scanner is systematically re-flagging already-scoped chains
   (`->where('tenant_id')->where('company_id')->find()`), teach the scanner to
   recognise the preceding scope so future regenerates don't churn `fixed` rows
   back to `needs_recheck`. Optional; the `recheck-clear` workflow already
   handles it, just more manually.

**Out of scope of any of the above:** the `web.form-selectors` /
`web.stores-localstorage` scanners (never built) and the `tauri.*` clusters
(blocked on the POS orchestrator branch) — these remain structural follow-ups.

---

## Commands used (all read-only)

```bash
php artisan sweep:inventory:verify-history     # F1
php artisan sweep:inventory:status --drift     # F2
php artisan sweep:inventory:status             # F3 (showed the stale block)
php artisan sweep:inventory:status --unmapped  # F7
# + direct YAML parse for the callsite/cluster rollups and spot-checks
```
