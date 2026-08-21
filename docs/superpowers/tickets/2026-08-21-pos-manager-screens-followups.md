# POS manager screens (`/shift`, `/reports`) — deferred follow-ups

**Opened** 2026-08-21 · lane `fix/pos-mocked-manager-screens`
**Status** OPEN — all four deliberately deferred; none blocks the lane
**Sibling ticket** `2026-08-21-xreport-blind-count-gap.md` (blind-count disclosure family)

Four bounded items found while replacing the mocked data on the two POS manager
screens. Each is real, none is in that lane's scope.

---

## 1. Cash-count policy is resolved but never refreshed

`resolveCashDisclosure` (`apps/pos/src/lib/offline/cashDisclosurePolicy.ts`)
calls `fetchFraudSettings()` live-first and falls back to the durable
`company_fraud_settings_cache`. It does **not** refresh that cache on a
successful online read, unlike `Header`'s EOD-open effect
(`Header.tsx:145-178`) and `refreshFraudSettingsCache`
(`apps/pos/src/api/fraudSettingsApi.ts:28`).

Consequence: a device that opens `/shift` online, then goes offline, reads a
cache last written at activation — possibly a stale policy — instead of the one
it just successfully fetched.

**Fix**: have `resolveCashDisclosure` call `refreshFraudSettingsCache(db, companyId)`
(or reuse its upsert) on the success path, best-effort and non-throwing.
Deferred because it widens the module from a pure read into a writer, and the
Header path already refreshes the cache on the flow that actually matters (the
close itself).

## 2. Neither screen refreshes after mount

`/shift` and `/reports` read once per dependency change (shift id, period,
terminal, company). A sale rung up on the same device while `/shift` is open
does not move the figures; the manager must navigate away and back.

Every other live POS surface has the same shape, so this is a consistency
question, not a regression. **Fix**: either a visibility/interval refetch (the
`AppShell` `visibilitychange` handler is the existing precedent) or wire the
catalog-channel pattern (`useCatalogChannel`) to receipt writes.

## 3. "Items" means LINE COUNT, not quantity

`SalesHistoryTicket.itemCount` is `JSON.parse(lines).length` — the number of
distinct lines, not the summed quantity. A ticket with one line of qty 12 shows
`1`. The column header is `reports.dashboard.items` ("Items"), which reads as
the quantity.

Deferred rather than guessed: summing quantity correctly means going through
`formatQuantity` / the unit's `decimal_places` (rule 19 display contract), and
whether a manager's ticket log wants line count or unit count is a product
decision. `TodaySalesPanel` shows an item **summary** string, so there is no
single existing precedent to copy.

**Fix**: rule on the semantic, then either relabel the column or sum quantities
through the unit-precision path.

## 4. `/sales` and `/reports` disagree on which receipts count

`loadSalesHistoryTickets` (`apps/pos/src/lib/offline/salesHistory.ts`) filters
`voided = 0 AND is_training = 0`, matching the Z / end-of-day paths.

`fetchLocalShiftReceipts` in `apps/pos/src/api/reportApi.ts` — the source behind
`/sales` (`TodaySalesPanel`) — applies **neither** filter. So a training-mode
receipt appears in `/sales` and not in `/reports`, from the same device, for the
same shift.

`reportApi.ts` is owned by the C-2 lane and was left untouched here.
**Cross-reference**: the parallel O-28 lane (`fix/o28-todays-sales-net-labeled`)
reports the identical finding against the same function — these should be fixed
once, together, not twice.

**Fix**: align `fetchLocalShiftReceipts` to the Z filter, in the C-2 lane.

---

## Also recorded (bounded claim, not a defect)

Legacy (pre-v4) refunds are folded into the `/shift` and `/reports` refund
counter-figures via `loadLegacyRefundTotalsForPeriod` /
`loadLegacyRefundTotalsForShift`, but they can never appear as **rows** in the
ticket list: `local_refund_records` has no `lines`, `payments_json`,
`operator_name`, `voided` or `is_training` columns, so there is nothing to
render. The period variant also filters on `created_at` (the local write
instant) rather than `settled_at` (server `posted_at`), because `settled_at` is
ISO 8601 with a `T` separator and is therefore not lexicographically comparable
against a `toSqliteUtc()` bound (rule 20). The two diverge under the documented
crash / shift-rollover races in `refundZAccounting.ts:16-24`.

`local_refund_records.terminal_id` is **unindexed** (only `idx_local_refund_records_shift`
exists), so the period-scoped read is a table scan. Fine at device volumes;
worth an index if the table ever grows.
