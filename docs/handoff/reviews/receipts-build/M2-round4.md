## M2 adversarial merge-gate register — round 4

**Range reviewed:** `7d85232cc54abd6a6b2135f476205ab434e71a66..HEAD` (`80796c29a`); M2 = `610a2d6f6`…`59cfb57cb`, with `80796c29a` recording the round‑3 register.
**Criteria:** brief §5 wave‑2 row + §3 (OI‑13/OI‑17) + §4 Addendum A(c); SPEC r5 §3(b)/(c), §3.b.1–§3.b.5, §4.1 S‑4/S‑5/S‑12, §4.4 S‑11, §4.5, §6 CL‑3/CL‑4, §7.1 BT‑6/7/12/13/14/15/17, §7.2 FT‑4…FT‑8/FT‑12/FT‑13.
**Lenses:** fiscal‑pos, frontend‑conventions, treasury, tenancy‑authz, general — all applied, all applicable.

**Round‑3 blocking set re-verified in code, not from the disposition text.**
1. **Closed.** `ReceiptDetailPage.tsx:116-123` now defines all six §3(b) payment columns (`payment_method`, `card_last_four`, `instrument_serial`, `transaction_reference`, `authorization_code`, `amount`); EN/FR keys present and parity-clean (programmatic flatten + set-diff over `{en,fr}/pos.json` → 0 missing either way); `ReceiptDetailPage.test.tsx:128-133` locks the three new column headers and their visible values from a fixture with non-null data.
2. **Closed.** `posted_at` renders on both lineage directions — `ReceiptDetailPage.tsx:211` (original) and `:224` (each refund), asserted at `ReceiptDetailPage.test.tsx:156,177`.
3. **Closed.** `ReceiptDetailData.php:107-110` reads `decimal_places` only; `rounding_method` is gone from the file (grep: zero hits), so `formatForUnit` falls to its own `half_up` default (`QuantityScale.php:72-75`). `ReceiptShowResourceTest.php:112-147` configures `RoundingMethod::Ceil` on a two-decimal unit and asserts `1.2510 → '1.25'`, i.e. the live rounding knob no longer moves a sealed value.
5. **Closed.** `show()` (`ReceiptController.php:579-591`) loads only `location`, `terminal`, `lines.product.unitOfMeasure`, `vatDetails`, `payments`, `originalReceipt`, `returnReceipts` — `company`, `cashier`, `payments.paymentMethod` removed.
6. **Closed.** `ReceiptDetailData.php:95-103` routes quantities through `QuantityScale::formatForUnit` behind an `is_numeric` guard; no `CurrencyScale` call touches a quantity.
7. **Corrected.** The handback no longer claims the `MemoryRouter` component tests fail against the pre-wave routing table (see finding 3).
4 is carried by design and is recorded in the YAML `findings:` and the handback.

---

### 1. **P3 — CONFIRMED — fiscal-pos / frontend-conventions** — a pre-fiscal return's discount is presented as a negative number with no caption
`ReceiptDetailData.php:169` (header) and `:121` (line), rendered at `ReceiptDetailPage.tsx:178`; frozen by `ReceiptAggregateIntegrityTest.php:108,111`.

Uniform negation is algebraically required: the legacy writer stores `headerSubtotal = net + discount` with `net`/`tax`/`total` negative and `discount` **positive** (`ReceiptReturnService.php:965-1041`, independently re-derived), so flipping every signed component keeps `total = subtotal + tax − discount + COALESCE(rounding,0)` true (test `:118-123` re-derives it from the emitted payload). The cost is that the one component stored positive comes out negative: a legacy return with a 0.500 line discount renders **“Discount −0.500 TND”** beside otherwise positive figures.

**Failure scenario:** an accountant reconciling a pre-2026 return reads a negative discount on a positive-signed refund and cannot tell whether it is a discount reversal, a data fault, or a sign bug. Display-only, internally coherent, and declared in the handback's "Declared legacy reporting projection" — a caption ("reversal adjustment") is the cheap close, not a re-derivation.

### 2. **P3 — CONFIRMED — fiscal-pos** — the PDF duplicate and the web detail disagree in sign for the same pre-fiscal return
`ReceiptPdfService.php:132-141` renders `(string) $receipt->total` raw (negative for a legacy return) while `ReceiptDetailData.php:173` emits the magnitude and relabels `invoice_type_code` to `REFUND` (`:156`).

**Failure scenario:** a user opens a legacy return at `/pos/receipts/:id` (header total `5.750`), clicks **Print duplicate** on that same page, and the audited duplicate shows `-5.750`. Both are defensible in isolation — the PDF is the fiscal document, the page is a normalised register view — but nothing on either surface explains the difference. Out of the M2 diff (the PDF service is untouched), so a ticket, not an edit.

### 3. **P3 — CARRIED, re-verified as still present** — FT-13/CL-4 cannot fail against the pre-build routing table
`ProvenanceSection.test.tsx:24-34` and `LedgerHistoryTable.test.tsx:64-76` now unmock `react-router-dom` and assert real navigation, but register a **local** `<Route path="/pos/receipts/:id">` inside their own `MemoryRouter`; neither reads `src/routes/index.tsx`. FT-13's "must fail against the pre-build routing table" is structurally unmet by these two files. The handback text is now accurate (it attributes the source-parity failure to `ReceiptPermissionParity.test.ts:51-66`, which genuinely does read the route source), so the evidence claim is honest; the structural gap remains.

### 4. **P3 — CARRIED** — `returned_quantity` under-reports once the lineage relation is location-scoped
`ReceiptController.php:588-591` → `calculateReturnedQuantities()`. Recorded in the YAML `findings:` and the handback as a terminal-audit follow-up; reachability is low (refunds are authored against the original's terminal), the surface is read-only, and `show()` has no write consumer. Unchanged since round 3.

### 5. **P3 — CONFIRMED — fiscal-pos** — the wire quantity is rounded to current unit precision, not emitted raw with its precision hint
`ReceiptDetailData.php:118` — `QuantityScale::formatForUnit($documentQuantity(...), $quantityDecimals)`. The named precedent (`ShiftController.php:305-330`) emits the raw scale‑4 quantity and lets `quantity_decimals` drive client formatting; here the sealed `1.2510` leaves the server as `"1.25"`. Output is display-equivalent today (the FE `formatQuantity` → `Big.toFixed` rounds identically), so no user-visible defect, but the payload no longer carries the sealed digits, and reconfiguring the unit's `decimal_places` changes what the API returns for an immutable line. Within the letter of the brief's `quantity_decimals` authorisation; worth a line in the follow-up ledger.

### 6. **P3 — CONFIRMED — frontend-conventions** — VAT table keyed on a non-unique column
`ReceiptDetailPage.tsx:189` uses `keyExtractor={(row) => row.tax_rate}`. `pos_receipt_vat_details` has only an **index** on `(receipt_id, tax_rate)`, no unique constraint (`2026_01_08_190639_…:44-45`). Two rows at the same rate would collide on the React key. The projector aggregates per rate, so this is defensive only; `row.tax_rate + '-' + index` closes it.

### 7. **Evidence outstanding (declared, not findings)**
Exact preflight **BLOCKED** on repo-wide Pint drift; scoped vitest **PARTIAL** (3 out-of-lane baseline failures); scoped PHPUnit **PARTIAL** (21 out-of-lane errors); all four Playwright flows and every wave-2 screenshot **ENVIRONMENT BLOCKED** on the absent API at `127.0.0.1:8010`. The handback states each with its blocker and does not call any of them green — which is what brief §7.4 requires of an unavailable E2E environment. These remain M3's to close.

### 8. **M2-close obligation, with the observed state (informational)**
The A0 STOP-and-check is due after this milestone's ACCEPT. Observed on this branch: `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:304,341` still excludes fiscal-era rows via `whereNull('fiscal_event_id')`, and there is no `chain_context` predicate or `pos_receipts.fiscal_hash ↔ fiscal_events.current_hash` mirror check anywhere in the verifier path. **A0 has not landed** — so the specced outcome is `M2b: blocked_owner` with waves 1+2 as the delivered scope. Do not ship screen (d).

---

## Bypasses attempted that FAILED (the implementation held)

| Attack | Result |
|---|---|
| Find a legacy return the `isLegacyReturn` predicate misclassifies | Re-derived independently: `ReceiptReturnService` writes `receipt_type = Return` (`:767`) and **never** a `fiscal_event_id` (grep over the file: zero hits); the projector only reaches `ReceiptType::Return` for `REFUND`/`VOID` with a resolved original (`PosCoreReceiptProjection.php:571-577`). The two eras are disjoint under the predicate. Held. |
| Break register disjointness with a legacy return (stored `invoice_type_code='SALE'`) | `ReceiptController.php:142-163`: the SALE arm explicitly excludes `receipt_type='return' AND fiscal_event_id IS NULL AND invoice_type_code='SALE'`, and the REFUND arm re-admits exactly that shape. Legacy returns appear on (c) only, never on (a). Held. |
| 500 the detail on a null column (`fiscal_hash`, `chain_sequence`, `receipt_year`, `cashier_name`, `discount_amount`) against the non-nullable DTO | All five are NOT NULL in schema (`create_pos_receipts_table.php:38-39,42,54`; `add_transaction_discount_to_receipts.php:22` default 0). `previous_hash`/`change_due`/`cash_rounding_*`/`synced_at`/`sync_error`/`voided_at` are nullable and typed `?string` with `$nullableMoney`/`?->`. Held. |
| Hide a v4 VOID from the original's lineage via `where('is_voided', false)` | The projector writes `'is_voided' => false` on every projected receipt (`PosCoreReceiptProjection.php:375`), so a VOID child is never self-excluded. Held. |
| Widen location scope from the client, or read another location's receipt/PDF by direct id | `applyAllowedLocationScope` precedes `findOrFail` on `show`/`downloadPdf`/`streamPdf` (`ReceiptController.php:592-593,760-762,794-796`); index intersects requested ∩ allowed (`:95-104`); `[]` membership yields an empty `whereIn` → 404. `ReceiptLocationScopeAuthorizationTest.php:15-48` locks 404 on all three plus the unrestricted 200. Held. |
| Write a reprint audit row on a denied PDF request | Scope/permission checks precede `recordPrint()` in both actions; `ReceiptPdfPrintAuditTest.php:48-70` asserts `pos_receipt_prints` count 0 on 403 and 404, and `ReceiptLocationScopeAuthorizationTest.php:26` on scope denial. Copy sequence 1→2 with `terminal_id`/`user_id`/`PrintMethod::Pdf` asserted on both rows. Held. |
| Enumerate an out-of-scope terminal through verify-chain | `ReportController.php:427-441` re-scopes on `company_id` + `whereIn(location_id)` via the injected `LocationScopeResolver`/`LocationScopeBoundary` and 404s; `ReceiptLocationScopeAuthorizationTest.php:56-66`. Held. |
| Leak an out-of-scope original's receipt number through the refunds register | The index eager-load of `originalReceipt` is itself company + allowed-location scoped (`ReceiptController.php:87-93`). Held. |
| Cross-receipt aggregate anywhere in the branch (Addendum A(c) rule 2 / OI-3) | My own grep, not the handback's: `git diff -U0 7d85232cc..HEAD -- apps/api/app apps/web/src \| grep '^+' \| grep -inE 'sum\(\|->sum\(\|\.reduce\(\|array_sum'` → **zero hits**. Held. |
| `parseFloat` / `Number(` / `(float)` / `toFixed` / `number_format` / `app(` / `: any` in new production code | Same diff, same method → **zero hits**. Held. |
| Company-default currency leaking into a render | Every money render passes an explicit `{ currency: … }` from the row/receipt (`ReceiptDetailPage.tsx:87,99,111-113,122,136,176-180,196,237`; `RefundReceiptListPage.tsx:61,184`). The differing-currency lock is real: `CompanyFactory` defaults **EUR** while the detail fixtures force **TND** — backend `ReceiptShowResourceTest.php:108-109` asserts 3-decimal output, and `ReceiptDetailPage.test.tsx:120` asserts `/11[.,]900/`, which an EUR default would render as `11,90` and fail. Held. |
| `canonical_bytes` / `vat_breakdown_hash` / `payment_methods_hash` / `tenant_id` at any depth | `ReceiptResourceNoCanonicalBytesRecursiveTest` walks the full tree with both lineage directions populated; the DTO is constructed key-by-key with no `toArray()` spread, and `ReceiptShowResourceTest.php:78-99` asserts exact key-set equality on root, line, payment and VAT. Held. |
| EN/FR drift on the three new payment keys | Flatten + set-diff over `{en,fr}/pos.json`: 0 missing either way; `receiptReporting.detail.{cardLastFour,instrumentSerial,authorizationCode}` present in FR. Held. |
| A new named queue, a non-additive migration, or an `apps/pos/**` change in M2 | `git diff --name-only` over `database/migrations`, `config`, `apps/pos` → zero paths in wave 2. Held. |
| E2E using the mocked-auth fixture instead of a real permission gate | All four specs import `loginAsRole`/`apiRequest` from `../money-campaign/helpers`; `e2e/fixtures.ts` is not imported. Held. |

**Not executed by me:** PHPUnit and vitest were not re-run (no live-DB env in this worktree; full-suite prohibition). Every finding above is derived from source and cited by `file:line`.

**Blocking set: empty.** All three of round 3's dispositioned items (its P2 plus the two it asked to close in the same edit) are verified closed against code, not prose; the remainder are P3s that brief §7 permits to ship with recorded tickets. Findings 1, 2, 5 and 6 should be added to the YAML `findings:` before the terminal audit; finding 8's A0 determination is now due.

VERDICT: ACCEPT
