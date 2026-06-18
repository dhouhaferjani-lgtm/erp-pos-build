# Composite items, recipes & inventory — industry standards research

**Date:** 2026-06-16
**Method:** deep-research harness — 6 search angles, 25 sources fetched, 105 claims
extracted, top 25 adversarially verified (3-vote). **All 25 confirmed 3-0, 0 killed.**
Primary vendor documentation throughout; confidence HIGH.
**Purpose:** decide how a multi-vertical ERP should gate composite-item /
recipe / BOM functionality relative to the Inventory module (Module 4 of the
vertical route-gating work), and whether to offer it cross-vertical.

## Verdict

1. **Items sell without recipes or inventory.** Lightspeed K-Series: only *Name +
   Accounting group* are mandatory. Katana: the recipe/BOM tab only appears when
   "Make" or "Kit/bundle" is enabled. → composite-item/dish **definition must not
   require inventory.**
2. **Recipe management is coupled to the inventory capability.** Lightspeed authors
   recipes inside the Inventory add-on; MarketMan is inventory-first. → recipe
   authoring belongs with the stock-tracking upgrade.
3. **Ingredient-level depletion requires inventory.** Toast: *"You must set up
   recipes that connect your menu items to the correct inventory ingredients.
   This is a prerequisite for depletion"* and depletion counts down from a
   recorded physical stock baseline. **Caveat:** recipes alone still power
   *theoretical* food-cost/menu-profitability without counts — inventory is a
   prerequisite for **depletion + actual-vs-theoretical variance**, not for all
   recipe value.
4. **One composite/BOM primitive, typed, relabeled per vertical.** Odoo BoM
   (Type = Manufacture / Kit / Subcontracting); Cin7 Core BOM (Assembly /
   Production / Make-to-order / Product-family); NetSuite (Assembly = built &
   stock-tracked vs Kit/Package = non-built bundle vs Item Group); Katana
   (BOM = "product recipe", Kit/bundle checkbox); Shopify apps (assembly/kit/BOM,
   atomic component deduction on sale).
5. **Apparel is a documented case.** Cin7 Core's Product-Family production BOM
   models *a shirt built from fabric component variations* (fabric colour/type →
   shirt colour/material, quantity by size).
6. **Synthesis (HIGH confidence, convergent across all vendors):** a multi-vertical
   ERP can validly (a) keep composite/recipe **definition independent of
   inventory**, requiring inventory only for **stock-aware features** (depletion,
   availability, costing-variance), and (b) ship the composite/BOM module as an
   **upgrade to non-F&B verticals with vertical-specific relabeling.**

## Decision applied (Module 4, refined 2026-06-18 to the decoupled model)

The research found two valid patterns (recipe-coupled-to-inventory vs.
definition-decoupled). The owner chose the **decoupled / progressive-growth**
model: define + cost recipes without inventory; inventory only for stock-aware
behaviour.

| Routes | Gate |
|---|---|
| composite-item CRUD + availability | `module:CompositeItems` |
| menu modifiers / modifier-groups | `module:CompositeItems` |
| recipes / recipe-lines / recipe `calculate-cost` | `module:CompositeItems` |
| composite-item variants | `module:CompositeItems` |
| ingredient depletion + recipe availability / 86-ing (SELL path) | requires `Inventory` (active stock tracking) — wiring pending |
| accurate WAC `cost_price` feeding `calculate-cost` | `Inventory` (else manual cost) |

`calculate-cost` rolls up each component's `cost_price` field, settable manually
before any stock ledger — so theoretical menu/BOM costing works on CompositeItems
alone, and grows more accurate once Inventory provides WAC.

Done: `CompositeItems` added to the `compatible_extras` of retail/fashion/
parapharmacy; the FE `useVerticalLabels` (fashion → 'sewing') relabels per
vertical (Recipe / Bill of Materials / Kit). Pending: wiring 86-ing enforcement
into the POS sell path.

## Open questions (not blocking; flagged by the research)

- Oversell policy: do Toast/Square/Clover block, warn, or sell-through a recipe'd
  item at zero/negative on-hand? (Affects whether inventory should ever gate a
  SALE vs. only reporting.)
- Standalone costing: can recipe-based theoretical cost roll up without a stock
  ledger, and is component cost a price field vs. moving/weighted average?
- Upgrade/packaging boundary in Odoo/Cin7/NetSuite: separate licensed app vs. a
  flag on the base item; does enabling it force inventory on components?
- Multi-level composites (kit-of-kits, recipe-using-sub-recipe): is every
  intermediate level inventory-tracked, or only leaf components?

## Key sources (primary)

- Lightspeed K-Series — Creating items and menus: https://k-series-support.lightspeedhq.com/hc/en-us/articles/1260804513170-Creating-items-and-menus
- Toast — Inventory stock depletion: https://support.toasttab.com/en/article/Toast-InventoryStock-Depletion
- MarketMan — Restaurant inventory management: https://www.marketman.com/platform/restaurant-inventory-management-software
- Odoo — BoM configuration: https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/manufacturing/basic_setup/bill_configuration.html
- Odoo — Kit shipping (BoM Type = Kit): https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/manufacturing/advanced_configuration/kit_shipping.html
- Cin7 Core — Bill of materials: https://help.core.cin7.com/hc/en-us/articles/9034450446479-Bill-of-materials
- NetSuite — Groups, assemblies, kit/packages: https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2318089.html
- Katana — Creating a product (Product recipe / BOM tab): https://support.katanamrp.com/en/articles/5967033-creating-a-product
- Katana — Managing bundles: https://support.katanamrp.com/en/articles/5914245-managing-bundles
- Shopify — Assemblage (kit component depletion): https://apps.shopify.com/assemblage
