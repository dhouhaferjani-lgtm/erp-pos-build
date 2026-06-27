# Handoff — Loyalty add-on ownership of product-editor fields

**For a fresh session to design.** During the IZI POS product-editor rebuild, the mock shows two loyalty/promotion fields in the **Pricing & Tax** section: **Loyalty points** (per-product points value) and **Eligible for discounts** (a boolean). Owner decision: these are **owned by the Loyalty add-on module**, not the product master.

## Agreed behaviour
- The fields render in the product editor **only when the Loyalty add-on module is active** for the tenant/vertical (both-layer module gating, per CLAUDE.md rule 12 — FE `hasModule('loyalty')` / `RequirePermission moduleKey` + BE `module:Loyalty` middleware on any endpoint).
- When the module is **off**, the fields do **not** show at all.
- When **on**, the fields show and must be set up correctly; **all logic (accrual, redemption, discount eligibility rules) lives in the Loyalty module**, not in Product.
- The product editor only surfaces a per-product **override** of the loyalty/promotion defaults; the source of truth + rules are the Loyalty module's.

## Open questions for the new session
1. Where do these values persist? A `loyalty_product_overrides` table (Loyalty module, FK product_id) vs columns on `products`? (Recommend module-owned table to keep Product clean + gating honest.)
2. "Eligible for discounts" — is this Loyalty or a separate Promotions module? Clarify module boundary.
3. Points model: fixed points per product, or a multiplier on price? Currency/tier interactions?
4. Cross-module contract: how the editor reads/writes via the Loyalty module's public service/Contracts (no direct model import across modules — rule 6).
5. Module activation UX: how the editor detects the module and renders the gated section.

## Current state in the editor
Per `docs/superpowers/plans/2026-06-25-product-editor-field-model-and-identity.md` §7, these are integrated **visually now** as gated placeholders (hidden unless the Loyalty module is active), unwired pending this design. Do not build accrual/redemption logic in the Product module.
