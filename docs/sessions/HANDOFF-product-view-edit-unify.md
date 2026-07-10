# HANDOFF — Product View/Edit Unification

Date: 2026-07-10
Branch: `feat/product-view-edit-unify`
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.viewunify`
Base: rebased onto `dev` at `c9017365f`
Status: complete, unmerged, ready for Claude final assembly

## What Shipped

Product detail and product edit now use one shared frontend section package and the exact canonical order:

1. `hero`
2. `general`
3. `pricing`
4. `inventory`
5. `suppliers`
6. `media`

`ProductSectionStack` and the immutable registry drive both modes. Each shared wrapper emits the same `data-product-section-key` and `section-${key}` identity. Pricing renders once per mode.

### Hero

- Shared shell keeps the identity, enrichment, and primary-image geometry aligned.
- View is read-only.
- Edit preserves barcode lookup, scanner handoff, manual enrichment refresh, image upload or create-buffer behavior, and enrichment state.
- Price, cost, margin, and stock facts were removed from the hero.

### General

- Shared labels and card layout cover name, SKU, unit, category, description, active status, and e-commerce status.
- Edit retains RHF registration and the existing prefill-clearing behavior; view resolves the same fields read-only.

### Pricing

- One canonical pricing section replaces the hero ready-to-sell metrics and the page-level `PricingIntelligencePanel` composition.
- `useProductPricingEditAdapter` is the sole owner of margin ↔ HT ↔ TTC draft/focus/blur commits and all canonical `sale_price` writes.
- Focused inputs retain their literal draft; commits occur on blur; `sale_price` remains HT.
- View labels now use the correct `inventory` translation namespace.
- `pricing.view_cost_prices` is server-authoritative in the frontend permission hook and fails closed before the server permission list is available.

### Inventory

- Shared edit fields cover initial quantity, pack units, shelf location, reorder values, and batch tracking.
- Shared view embeds stock totals and location expansion.
- Stock value, WAC, and cost multiplication are permission-gated while non-cost quantities remain visible.

### Suppliers

- Both modes share the same translated managed-in-Purchases explanation and supplier route without inventing unsupported product-supplier fields.

### Media

- The dedicated `section-media` remains.
- View uses a read-only gallery; persisted edit keeps upload/delete management; create mode keeps the buffered-file flow.
- The hero displays only the primary image.

### Extensions

- Pharmacy, loyalty, and variants remain edit-only as required.
- Automotive remains vertical-gated in edit and data-presence-gated in view.

## Cost Confidentiality

The API already returns a concrete permission list and redacts product cost fields. The frontend now stores that list from login, registration, and `/auth/me`, and treats `pricing.view_cost_prices` as authoritative rather than inferring it from a role name.

The gate covers:

- view pricing WAC, last purchase price, margin, and cost-derived floor;
- edit purchase price, WAC, and margin;
- hero cost/margin facts, which no longer exist;
- `ProductStockLevels` stock value and WAC multiplication;
- the discount-policy verdict request itself.

Legacy role-map behavior remains for older frontend route keys that do not map one-to-one to backend permission names.

## Browser Trace

Local services used:

- Web: `http://127.0.0.1:5174`
- API: `http://127.0.0.1:8011`
- Existing seeded pharmacy tenant and existing seeded product; no record was saved or changed.

Holder trace:

- Opened product detail and then Edit.
- View order: `hero, general, pricing, inventory, suppliers, media`.
- Edit shared order: `hero, general, pricing, inventory, suppliers, media`; edit-only pharmacy and loyalty remain extension slots.
- Hero anchor moved from 306 px in view to 307 px in edit.
- `#section-pricing` count was exactly 1 in both modes.
- Hero contained no price, cost, margin, or WAC text.
- Pricing labels resolved as user-facing English rather than raw translation keys.
- Margin draft `33.333` remained literal while focused and after a delay, then normalized to `33.33` only after focus moved.
- HT draft `14.9999` likewise remained literal while focused; blur updated the non-focused derived fields without a save.

Non-holder trace using an existing manager whose API permission list lacks `pricing.view_cost_prices`:

- View retained HT, TTC, tax, discount, and all stock quantities.
- View omitted weighted average cost, last purchase price, margin, stock value, and the WAC multiplication line.
- Edit retained HT and TTC inputs.
- Edit omitted purchase price, WAC, and margin inputs.
- Shared order stayed canonical and pricing count stayed 1.

The browser pass initially exposed two integration defects—raw pricing translation keys and role-inferred cost visibility. Both were fixed, regression-tested, re-reviewed by Opus, and rechecked in the browser.

## Adversarial Reviews

All required review artifacts are committed under `docs/superpowers/specs/reviews/`:

- `2026-07-10-product-sections-m1-opus-review.md`
- `2026-07-10-product-general-section-opus-review.md`
- `2026-07-10-product-pricing-section-opus-review.md`
- `2026-07-10-product-pricing-section-opus-rereview.md`
- `2026-07-10-product-inventory-section-opus-review.md`
- `2026-07-10-product-suppliers-section-opus-review.md`
- `2026-07-10-product-media-section-opus-review.md`
- `2026-07-10-product-hero-section-opus-review.md`
- `2026-07-10-product-hero-section-opus-rereview.md`
- `2026-07-10-product-view-edit-final-integration-opus-review.md`

Every BLOCKER and MAJOR was reconciled before handoff. The final review found no blocker; its two process majors were closed by committing the security/i18n delta and rebasing onto current `dev`. Its fail-closed permission recommendation was also applied.

## Final Verification

- Explicit by-path Vitest sweep: 32 files passed, 187 tests passed, 1 existing skip. Existing React `act(...)` and Node local-storage warnings were emitted.
- `pnpm --filter @autoerp/web typecheck`: passed.
- `pnpm --filter @autoerp/web build`: passed; 4,232 modules transformed. Existing large-chunk warnings remain.
- `pnpm --filter @autoerp/web lint`: passed with 0 errors; 8,637 repository-baseline warnings. TanStack key audit: 0 new, 0 stale baseline entries.
- `npx -y react-doctor@latest --verbose --scope changed --base dev`: no changed-code issues, 85/100.
- `git diff --check`: passed after normalizing legacy review whitespace and extra EOF lines.
- No orphan Vitest workers remained.
- Added-code scan found no `TODO`, `FIXME`, `HACK`, or `any` additions in the product/inventory scope.

## Baseline Test Repairs Included

- Replaced stale media barrel mocks with the real shared media composition.
- Updated WAC edit tests to seed the required cost permission.
- Updated tenant-query-key expectations for the current parameter serialization and pagination shape.
- Updated general-section category expectations for the shared adapter.

## Deferred Intentionally

- Read-only pharmacy and loyalty sections require dedicated view variants and payload work.
- Read-only variants remain deferred because variant management needs a persisted product.
- The now-unused `PricingIntelligencePanel` implementation/export remains available but has no production consumer; deleting that legacy surface can be a separate cleanup.
- No backend, migration, generated DTO, or API contract change was made.

## Commit Sequence

- `Phase 0.1.0: Add shared product section contract`
- `Phase 0.1.1: Unify product general section`
- `Phase 0.1.2: Unify product pricing section`
- `Phase 0.1.3: Unify product inventory section`
- `Phase 0.1.4: Unify product suppliers section`
- `Phase 0.1.5: Unify product media section`
- `Phase 0.1.6: Unify product hero section`
- `Phase 0.1.7: Integrate shared product section stack`
- `Phase 0.1.8: Enforce authoritative product cost visibility`

Claude should perform final assembly or merge. Do not merge this branch into `dev` from the Codex handoff session.
