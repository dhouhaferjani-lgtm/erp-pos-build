# R-8 (M-10) Opus Adversarial Review — supplied to close the timed-out review
Date: 2026-06-25 | Reviewer: Opus (cross-model; the 2026-06-23 headless Opus call exited 142 after a 120s alarm with no output — this replaces that stub)

**Scope:** R-8 = M-10 "fail closed consistently" — gate the `DocumentLineEditor` Service tab/search behind `hasModule('Workshop')` so non-Workshop verticals don't 403 on the now-module-gated `/services`. Commit `da0ffeb1e` (rebased; orig `961589044`).

## Verdict: ✅ APPROVE (M-10 closed)

### What R-8 does (verified, `DocumentLineEditor.tsx`)
- `canSearchServices = hasModule('Workshop')`; `activeSearchTab = canSearchServices ? searchTab : 'product'` — stale `searchTab='service'` can't take effect when Workshop is off.
- The `/services` query `enabled` guard now includes `canSearchServices && activeSearchTab === 'service'` → non-Workshop companies never trigger the fetch (the 403 path).
- Service tab hidden (`{canSearchServices && …}`); placeholder/result-list/create-footer all keyed on `activeSearchTab`.
- Tests cover both directions (hidden when disabled, shown when enabled) + the tenant-scope mock updated to provide `hasModule()`.
`DocumentLineEditor` was the actual cross-vertical exposure (used in quotes/orders/invoices for ALL verticals), so this closes the real M-10 hole.

### Adversarial sweep — other `/services` consumers (the "consistently" angle)
- `ServicePicker.tsx` fetches `/services` with **no Workshop gate** — BUT its only consumer is `BundleComponentFormModal` in the **workshop-bundles** feature, whose routes are gated by `RequirePermission permission="workshop-bundles.{view,manage}"` (`routes/index.tsx:1360-1386`). Non-Workshop verticals lack those permissions → the page is unreachable → `ServicePicker` never renders → no 403. **Not a gap.**
- `features/services/*` pages, the Sidebar `allServices` nav item (`module: 'Workshop'`), and the service routes are all module/route-gated. **Not a gap.**

### Findings
- **BLOCKER/HIGH/MEDIUM:** none.
- **LOW-1 (no action needed):** `workshop/bundles` is **permission-gated** (`RequirePermission`) rather than **module-gated** (`ModuleGuard module="Workshop"`) like the work-order routes. It still blocks the M-10 403 path (the page is unreachable for non-Workshop), so it's not an M-10 defect — but for module-gating *consistency* a future pass could wrap it in `ModuleGuard` too. Not required for R-8.

### Conclusion
R-8 fully closes M-10; nothing missing to finish. No code changes required.
