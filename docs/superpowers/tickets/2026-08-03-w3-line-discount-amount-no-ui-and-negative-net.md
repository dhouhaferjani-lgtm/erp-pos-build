# Ticket: line `discount_amount` — no UI control (C-6(b)), and an unguarded over-discount drives the line net NEGATIVE

From the W-3 execution agent's money-campaign leg (2026-08-03), cases `MTP-DSC-02/03/04`
(`apps/web/e2e/money-campaign/documents-discounts.spec.ts`). Live-proven against
`demo-pharmacy-tn`. Finding 2 is the one with money consequences.

This ticket is the **product half** of orchestrator ruling **C-6** in
`docs/qa/2026-08-02-full-e2e-campaign-plan.md` (open question **F-5**). The *test* half — C-6(a),
"drive the API directly" — is done: DSC-02/03/04 are now authored and green.

---

## 1 (P2, UX gap) — `discount_amount` is reachable by the API but by no UI control anywhere

`DocumentLine.discount_amount` is a first-class, validated, calculated field:

- validated on write — `lines.*.discount_amount => nullable|numeric|min:0|regex:/^\d+(\.\d{1,3})?$/`
  (`apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:130`);
- honoured by the canonical line-net calculator —
  `DocumentLine::computeLineTotal()` (`apps/api/app/Modules/Document/Domain/DocumentLine.php:283-290`)
  applies it whenever `discount_percent` is null/zero;
- read back verbatim by `DocumentLineData`.

But the **only** discount control in the documents UI is a single numeric input bound to
`discount_percent`, and its `onChange` handler *unconditionally nulls the amount*:

```
apps/web/src/features/documents/components/DocumentLineEditor.tsx:748-764
  value={line.discount_percent ?? ''}
  onChange={(event) => {
    handleUpdateLine(line.id, {
      discount_percent: event.target.value === '' ? null : event.target.value,
      discount_amount: null,          // <-- always
    })
  }}
```

`handleAddProduct` / `handleAddBlankLine` also seed every new line with `discount_amount: null`
(`:368-369`, `:397-398`, `:421-422`). There is no second field, no percent/amount toggle, and no
read-only display of an amount that arrived from elsewhere.

**Consequences**

- An operator cannot express "take 25.000 TND off this line" — only a percentage. For a
  round-money negotiated discount they must compute the percentage by hand, and any percentage
  that does not divide cleanly cannot reproduce the intended figure at all (money is scale-3,
  percent is scale-2).
- A line whose `discount_amount` was set by any **non-UI** path (API integration, import, a future
  conversion/copy path) becomes silently **uneditable-without-loss**: opening the document in the
  editor and touching the Discount cell wipes the amount to `null`, changing the line net with no
  warning.

**Fix direction (needs a product decision, F-5).** Either (a) ship the control — a percent/amount
mode toggle on the Discount column, mirroring how the backend already models it as an
either/or — or (b) declare `discount_amount` an API-only/integration field and make the UI
**non-destructive** about it (render it read-only when present rather than nulling it on any edit
of the percent). (b) is the cheap correctness fix; (a) is what closes the actual UX gap.

---

## 2 (P1, money correctness) — a `discount_amount` above the line gross is accepted and produces a NEGATIVE net, VAT and total

**Live-proven** (`MTP-DSC-04`, green as a tripwire against today's behaviour). On
`demo-pharmacy-tn`, `POST /api/v1/invoices` with one line
`quantity 10, unit_price 12.500 (gross 125.000), discount_amount 200.000, tax_rate 19.00`:

| field | actual | plan expectation |
|---|---|---|
| HTTP status | **201 Created** | 422, or accepted-and-floored |
| `lines[0].line_total` | **`-75.000`** | `0.000` floor, never negative |
| `subtotal` | **`-75.000`** | `0.000` |
| `tax_amount` | **`-13.250`** | `1.000` (stamp only) |
| `total` | **`-88.250`** | `1.000` |

Note `tax_amount = -13.250` is *negative line VAT* (`-75.000 x 0.19 = -14.250`) plus a
**still-positive** `1.000` Tunisia stamp duty — the `FixedAmount` document-level tax does not
follow the sign of the base it is levied on.

**Root cause — two independent missing guards:**

1. `CreateDocumentRequest.php:130` validates `discount_amount` as `min:0` only. It is never
   compared against the line's own `quantity x unit_price`, so nothing at the HTTP boundary
   rejects an over-discount. (`discount_percent`, by contrast, *is* bounded — `max:100` at
   `:129`.)
2. `DocumentLine::computeLineTotal()` (`DocumentLine.php:288`) subtracts with a bare
   `bcsub($subtotal, $discountAmount, $scale)` — no `max(0, ...)` floor:

   ```php
   } elseif ($discountAmount !== null && bccomp($discountAmount, '0', $scale) !== 0) {
       $subtotal = bcsub($subtotal, $discountAmount, $scale);
   }
   ```

**Why this matters even though there is no UI path (finding 1).** "No UI control" is not a
mitigation — it is exactly what has kept this unexercised. The field is validated and public on a
`can:invoices.create` endpoint, so any API consumer, import, or future UI that ships the control
(finding 1 option (a)) reaches it immediately. A negative-total *invoice* is not a document type
this system has: it inverts the credit-note model (`MTP-DOC-16` proves credit notes are stored
**positive**, with sign handled by allocation), it would feed a negative receivable into aged
balances and the VAT return, and once posted it enters the SHA-256 fiscal chain in that state.

**Fix direction.** Both guards, not one:
- add a validation rule comparing `discount_amount` against the line gross (a `422` is the right
  answer — silently flooring a 200.000 discount to 125.000 hides an operator error), **and**
- floor `computeLineTotal()` at `0` as defence-in-depth, so no other write path can reproduce it.

Whichever lands, `MTP-DSC-04` in `apps/web/e2e/money-campaign/documents-discounts.spec.ts` is a
deliberate TRIPWIRE on the current values and will go red — update it to the new contract, do not
delete it.

---

## Not a defect (recorded so it is not re-filed)

`MTP-DSC-06` — there is no **whole-document** discount control either. `documents.discount_amount`
exists in the schema and `DocumentTotalsCalculator` honours it at the aggregate, but no
create/update endpoint ships it. This is **by design** per the 2026-08-01 money test plan §B.0
("whole-receipt discounts are a POS concept"); `MTP-DSC-06` asserts the absence. No action.
