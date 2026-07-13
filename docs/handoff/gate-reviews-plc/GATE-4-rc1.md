# GATE 4 RC1 — Wave 4 (counting node scope)

## Inventory-spine review

- **Subtree seeding matches canonical §3.1.** `LocationNode::scopeSubtreeOf` uses `path = :p OR path LIKE :p || '/%' ESCAPE '\'` (`LocationNode.php:150-155`), and `zoneItemSeeds` applies that scope to every selected node (`InventoryCountingService.php:327-348`).
- **A1 vs A10 is protected and test-locked.** The explicit slash boundary is covered by `test_node_scope_seeds_its_entire_subtree_and_expands_all_variants_without_prefix_collision` (`ZoneScopedCountingTest.php:204`).
- **Product placement expands to variant-grain count items.** Variant and stock rows are batched, each live variant gets its own seed, and products without variants keep the null-variant grain (`InventoryCountingService.php:364-435`). Partial uniqueness of live placements prevents duplicate product/location seeds.
- **Assign-as-count remains single-scope and ancestor-safe.** The selected node is the assignment target; a selected ancestor is covered by the focused assignment test and the end-to-end flow.
- **Stock remains location-grained.** The integration flow creates a tree, assigns, bulk-moves, seeds a subtree into two variant items, finalizes, runs the queued listener without `CompanyContext`, and proves both stock rows remain unchanged (`ZoneScopedCountingTest.php:359`).

No BLOCKER/HIGH counting-seed finding was found, so the inventory-spine escalation rule is not triggered.

## Cross-cutting hunt

- Reference migration coverage scans the runtime request/controller/service/advisory/DTO surfaces for legacy table and singular-column names and validates generated `node_id` types (`LocationNodesMigrationTest.php:45`).
- The wizard reuses `NodePicker`, keeps the legacy `'zone'` enum and `zone_ids` payload, scopes the node query with `tenantScopedKey`, and explains that selected nodes include their subtrees (`CreateCountingPage.tsx:488-561`).
- Wave 4 terminology and helper copy are real translations in en/fr/ar and test-locked; no stale `zones.*` key remains.
- Design audit has zero new violations (one stale raw-checkbox baseline entry was removed), TanStack key audit is clean, quantities remain strings, generated types are current, and permissions continue to reuse the Phase-1 `inventory.view` / `inventory.adjust` routes.

## Findings

1. **LOW — multi-selection highlight.** `NodePicker` receives both single- and multi-select props. Label clicks and checkboxes consistently toggle `zone_ids`, but the single-row highlight disappears when two or more nodes are selected. This is cosmetic; checkbox state remains visible.
2. **LOW — legacy mixed stock grain.** If a variant product also carries a legacy product-grain stock row, the product-grain quantity is omitted rather than zeroed. This is consistent with D9's variant expansion and does not affect correctly grained stock.
3. **INFO — inactive variants.** Inactive but non-deleted variants are intentionally counted because physically held stock still requires reconciliation; this behavior is test-locked.
4. **INFO — module import.** The direct Catalog `ProductVariant` dependency follows existing inventory counting precedent.

The reviewer CLI sandbox blocked only the requested file write. The executor preserved the returned Opus review in this artifact after the process exited successfully.

VERDICT: APPROVE
