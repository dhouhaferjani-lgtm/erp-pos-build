# Ticket — the printed POS Z ticket carries no VAT at all

> **Raised by:** B-6(ii) implementation lane (`fix/b6ii-xz-refund-vat-display`), 2026-08-23.
> **Source:** scoping doc `docs/superpowers/specs/2026-08-23-xz-refund-vat-inclusion-scope.md` §4.1, §5 sizing, **Q-2**.
> **Status:** DEFERRED out of the B-6(ii) lane, per the doc's own recommendation. Needs an owner decision on priority.

## What is wrong

`buildZReceiptData()` (`apps/pos/src/lib/printing.ts:250-295`) hardcodes:

```ts
    tax_amount: '0.00',
    vat_breakdown: [],
    …
    show_vat_breakdown: false,
```

So the **thermal Z ticket the cashier physically prints at close-of-day shows no VAT of any kind** — not the sale-only figure, not the net figure, not a per-rate table. Every other Z surface (device modal, server PDF, web detail page) shows at least one VAT figure; this one shows none.

This is a **missing feature**, not the refund-netting defect B-6(ii) fixed. The other surfaces had two disagreeing VAT numbers; this one has zero.

## Why it was not fixed in the B-6(ii) lane

Adding VAT here is not a caller-side change. It requires:

1. extending `BuildZReceiptDataInput` (`printing.ts:285-297`) with the VAT figures,
2. threading them from the caller (`Header.tsx:535-556`, which builds the input at close time and does not currently hold the aggregate),
3. flipping `show_vat_breakdown` and populating `vat_breakdown`, which changes what the **Rust formatter** emits — i.e. the printed layout, column widths and paper length on a 58mm roll.

That is printer-layout risk on a surface with no automated visual coverage, in a lane whose whole safety argument was "display-only, zero fiscal risk". The scoping doc reached the same conclusion independently (§5: *"Size: S if `printing.ts` … is split into a follow-on — recommended, since the printed ticket shows no VAT at all today and fixing that is a separate feature ('put VAT on the Z ticket'), not a refund-netting correction"*).

## What the follow-on needs to decide

- **Q-1 shape on 58mm paper.** The B-6(ii) screens use three lines (VAT on sales / VAT on refunds / net VAT) plus a per-rate table. On a thermal roll that may be one net line plus a per-rate table, with the refund line only when non-zero. The doc's own recommendation was *"three on web/PDF, net-plus-counter-line on device modals"* and it deliberately left the ticket open.
- Whether the per-rate table appears at all, or only the net total.
- Whether it ships with the same device build as B-6(ii) (D-1 stack) or later.

## Reusable pieces already landed

- `apps/pos/src/lib/reports/vatDisclosure.ts` — `deriveVatDisclosure(input, scale)` produces `{ salesVat, refundVat, netVat, hasRefundVat, isReconciled }` from any shape carrying `tax_amount` + `vat_breakdown`. The Z ticket's caller has both, so the arithmetic is done; only the plumbing and the layout remain.
- `apps/pos/src/locales/{en,fr}/pos.json` → `reports.vatOnSales` / `vatOnRefunds` / `netVat` / `vatNetOfRefunds` already exist.

## Tests that will need to move

`apps/pos/src/lib/__tests__/printing.test.ts:4-60` pins the current all-zero shape. It is GREEN and untouched today, and will go red the moment the ticket carries VAT — which is the correct signal, not a regression.
