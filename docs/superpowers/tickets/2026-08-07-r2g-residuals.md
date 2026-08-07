# R2-G lane residuals (post-merge, non-blocking) — 2026-08-07

Source: the two R2-G gate records (travel with the lane, on dev). All CLEAR-verdict leftovers.

1. **Backfill report: FILED refusals share the skip bucket with data-quality skips** (backend
   re-gate minor-5): an operator can't distinguish "needs manual review" (data problem) from
   "deletion refused, FILED period" (policy). Give policy refusals their own counter + line.
2. **Row/total penny mismatch under a 2dp currency** (FE MINOR-4, pre-existing class):
   VatBreakdownTable rows can fail to visually sum to the total when display rounds at 2dp.
   Display-only; decide per the round-once convention (tooltip exact values or footnote).
3. **`ar/finance.json` has no `vatReporting` block at all** (pre-existing, feature-wide;
   surfaced now that VatSpecialItems actually renders): falls back to English labels via
   fallbackLng. Add the Arabic namespace with the whole vat-reporting key set.
4. **FR/GB special-items re-enablement** (MAJOR-3 disposition): mappings deliberately removed
   because FranceVatStrategy/UkVatStrategy emit deferred-feature stubs. When those strategies
   compute real intra-community/EC values, restore the panel config + flip the renders-nothing
   tests back.
