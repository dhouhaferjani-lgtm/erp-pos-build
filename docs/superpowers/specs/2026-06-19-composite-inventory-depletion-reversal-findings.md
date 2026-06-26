# Findings & decision: composite/recipe ingredient depletion, reversal & wastage

**Date:** 2026-06-19
**Status:** Findings + decision (feature deferred; bug-fix logic settled)
**Inputs:** deep-research (102 agents, primary-vendor sources, adversarially verified) + the Codex adversarial review of the 86-ing spec (`docs/superpowers/reviews/2026-06-18-recipe-86ing-spec-codex-review.md`) + code grounding.

This records the industry-standard logic so the deferred F&B-with-inventory feature and the two known bugs can be done **correctly** later (or now), not naively.

---

## 1. What the industry does (researched, cited)

**Depletion timing.** Two patterns by system class:
- **Sale-time depletion** — lightweight F&B POS (Toast Inventory): *"recipes drive stock depletion… automatically decrements every time that menu item is sold."* No built-in "already prepared" notion.
- **Production-step depletion** — manufacturing ERP (Odoo MRP, NetSuite, Katana, Cin7 Core, Business Central): components consumed at a **build/production order**, not at sale.

**Reversal — the key question. There is NO universal "full auto-restore."**
- A pure POS **void is a financial/transactional reversal**; Toast's void + stock-depletion docs contain **no ingredient-restoration** language and **no before/after-prep distinction**.
- Restoring components is a **deliberate, explicit inverse operation**: **unbuild** (NetSuite, Odoo) / **disassembly** (Cin7 Core) / **MO revert** (Katana) — decrements the finished good, increments each component **by its BOM proportion**. NetSuite's documented use case for unbuild *is* order cancellation.
- Cin7 Core is the closest POS-return analogue: a refunded/credited auto-assembled product **is** auto-disassembled back to components **on restock — but only when explicitly flagged** for auto-disassembly (and on a supported costing method); **not during sales orders, not by default**.

**Wastage / yield loss is a SEPARATE, non-restoring mechanism.**
- Scrap orders / waste logs are distinct from sales depletion and are **not auto-reversed** — scrapped material stays consumed. Odoo *adds* scrap to total consumption (4 used + 2 scrapped = 6) and moves it to a virtual Scrap location (a loss log, not usable stock).
- **Unbuild assumes components are reclaimable**; damaged/unusable ones are **not** returned by the unbuild — they're removed via a **separate scrap**. The canonical pattern for the half-cooked-dish problem is **full-restore-then-scrap-the-loss**, NOT a partial restore baked into the reversal.
- "Full restore" is therefore **conditional, not universal** (Business Central writes off actual-scrap WIP to an adjustment account; Katana doesn't clear manually-recorded consumption on MO revert).
- F&B inventory platforms (Toast, MarginEdge) model **waste as a first-class feature**: waste log keyed by item/recipe, quantity, UoM, **reason** — separate from sale depletion.

**Costing caveat (open):** components restored on unbuild re-enter at the assembly's average cost (NetSuite), which can drift a **company-wide WAC** model on void/return — must be handled to avoid cost drift.

Sources: Toast (Stock-Depletion, Voiding-Items, Waste), Oracle/NetSuite (unbuild assembly), Odoo (unbuild_orders, scrap_manufacturing), Microsoft Business Central (cancel/reverse production), Katana (revert MO), Cin7 Core (disassembly), MarginEdge (waste log). Full report: research task output (2026-06-19).

## 2. Decision (the "right logic") for our ERP

1. **Depletion stays sale-time for recipe POS** (acceptable per Toast pattern) — but on our **device-authoritative** flow it must ride an **immutable recipe snapshot** in the fiscal event so the projection can explode + deplete (see the deferred feature, §4).
2. **Reversal = explicit inverse-consumption ("unbuild"), restoring by recipe proportion** — NOT silent-no-restore (today's bug), NOT naive-always-partial. Default on void/return of a recoverable composite = **full-restore each ingredient by recipe quantity**.
3. **Wastage is a separate path, not a partial reversal.** The "cracked eggs" / after-prep case is handled by a **first-class Waste feature** (waste reason + log + non-restoring deduction), layered *on top of* full-restore — matching Odoo/NetSuite. Not by making the void-restore partial.
4. **Configurable, per Cin7's model:** whether a composite auto-disassembles (re-credits ingredients) on void/return is a **flag** (per-recipe and/or per-vertical default) — default ON for recoverable verticals, with the waste path for the rest.
5. **Costing:** restore ingredients at a cost consistent with how they were consumed to avoid **company-wide WAC drift** (reuse the WAC/landed-cost capitalization entry point). Open question — must be designed before implementing reversal.

## 3. The two known bugs — status & correct fix

Both are in the `ReceiptCreationService` composite-explosion path, reachable via **order→receipt and exchange** flows (`OrderToReceiptService`, `ExchangeService`) — **not** the live device POS sale path, where the projection sets `composite_item_id => null` and never explodes composites. So they only bite a tenant using **composites WITH inventory tracking** through those flows — the deferred F&B-with-inventory scenario.

- **Bug A — void/return doesn't re-credit composite ingredients.** `ReceiptVoidService`/`ReceiptReturnService` skip `product_id === null` (composite) lines, so ingredients depleted on the composite sale are never restored. **Correct fix:** on void/return of a composite line, **explode the recipe and re-credit each leaf by proportion** (inverse of `deductCompositeItemStock`), gated on `module:Inventory` — i.e. the unbuild default from §2.2. The non-recoverable/after-prep case is the separate Waste feature (§2.3), deferred.
- **Bug B — composite ingredients never reach FEFO/batch allocation.** Composite leaf `decrementStock()` discards the returned movement and never calls `allocateBatches()`, so batch/expiry ingredients in a dish aren't FEFO-allocated. **Correct fix:** route composite leaf deductions through the same batch-allocation path as simple products.

**Recommendation:** fold both fixes into the deferred feature OR ship them as bounded TDD fixes now using the §2 logic (full-restore default; waste path deferred). They are correct-as-described regardless of timing, but only deliver value once a tenant runs composites + inventory.

## 4. Deferred feature (from the Codex review) — recorded, not built

86-ing + sale-time ingredient depletion on the **live device flow** requires:
- A new canonical fiscal event version (`SaleReceiptV3`) carrying an **immutable recipe snapshot** so the projection explodes from the snapshot (fixes recipe-history drift; needs **owner sign-off** — fiscal events are append-only/immutable).
- **On-device** recipe + ingredient-stock data + a local explosion for **offline 86-ing** (the server can't gate a device-authored sale).
- Explicit decision on **offline Block policy** (today Menu tenants' POS gate PASSes before policy — Block is effectively downgraded offline).
- Flattened, sorted leaf locking (reuse `ProductCostLock` order) to avoid deadlocks; reserved-aware availability; variant-level ingredients; the costing/WAC reconciliation (§2.5).

**Status: deferred** until an actual F&B-with-inventory customer needs ingredient-level inventory + auto-86. The cross-vertical/recipe-costing work already shipped does **not** depend on any of this.

## 5. Open questions to resolve before building

- OQ-A: Square/Lightspeed void/return ingredient behaviour (no verified data — confirm if they become reference points).
- OQ-B: per-vertical vs per-recipe configurability of auto-disassembly + waste defaults.
- OQ-C: WAC reconciliation on restore (assembly-avg vs original-lot cost) under company-wide WAC.
- OQ-D: offline Block enforcement (fail-closed vs sell-through-and-flag) — owner decision.
