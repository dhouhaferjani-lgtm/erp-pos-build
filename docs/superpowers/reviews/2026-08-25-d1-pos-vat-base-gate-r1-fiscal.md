# D-1 gate r1 — fiscal-pos lens (adversarial, code-grounded, verified by execution)

**Lane** D-1 (CRITICAL, owner-ruled 2026-08-25 option (a)) · branch `fix/campaign-d1-pos-vat-discount-base`
· worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/d1-pos-vat-base` · HEAD `f9fc06907`
**Diff** `git diff dev...HEAD` — 52 files, +5105/-221. MIGRATION-BEARING. POS-release-coupled.
**Inputs read in full** brief `docs/sessions/session-A-2026-08-24/BRIEF-D1-pos-vat-discount-base.md`;
handback `docs/superpowers/reviews/2026-08-25-d1-handback.md` (incl. §12 addendum);
`.claude/context/compliance.md`; `docs/architecture/precision-contract.md`; CLAUDE.md rules 8 / 13 / 19 / 20.
**Reviewer posture** verify, do not trust. Every claim below cites a line I opened, or a command I ran.

---

## VERDICT

**spec ❌ · quality CHANGES-REQUESTED · merge-blocking: YES**

The **arithmetic core is correct and I reproduced it independently** — the ventilation, the
largest-remainder residue, the exempt group, the comp clamp, the golden vector, the era-aware ledger
arm, the CHECK widening and the device report rewiring all hold up under execution on both sqlite and
PostgreSQL, and the three red-proofs the handback claims are real (I re-ran all three from a tamper
worktree and got the same counts).

It is blocked on **three defects the diff itself introduces at the server contract boundary**, two of
which I demonstrated by execution:

1. adding `event_version = 5` to the parseable set while narrowing five refund guards from `>= 4` to
   `=== 4` leaves **v5 with no invoice-type restriction at all** — a `SALE_RECEIPT` v5 declaring
   `invoice_type_code = REFUND` (or `VOID`) is ACCEPTED, bypassing every v4 refund invariant;
2. the new v5 partition check **stopped pinning the net/VAT split**, so a payload that under-declares
   VAT by 30.703 TND on the ruling's own worked example is ACCEPTED — the exact failure class D-1
   exists to remove, re-opened one layer up;
3. the forward-version gate is scoped to `(tenant, company, terminal)` but **not to `chain_context`**,
   and the device drains `ORDER BY chain_context ASC, sequence_number ASC` — so a pending pre-upgrade
   TRAINING sale drains *after* the first operational v5 and is silently quarantined.

None of the three requires re-authoring anything device-side; all three are contained fixes in
`FiscalPayloadConstraintValidator` / `SaleReceiptForwardVersionGate` plus their pins.

---

## What I verified GREEN (by execution, not by reading the handback)

| # | Claim | How verified | Result |
|---|---|---|---|
| V-1 | Worked example 640 − 50 → base 524.547 + VAT 65.453 = 590.000 | independent probe calling `allocateTransactionDiscount` directly from a temp worktree | **reproduced digit-for-digit**, incl. per-rate 184.375/184.375/92.188/63.609 and 35.031/23.969/6.453/0.000 |
| V-2 | Residue = 2 ulp, lands on the two largest remainders | same probe: shares `5.391 / 17.656 / 18.594 / 8.359`, sum 50.000 | **confirmed** (19 % .00075 and exempt .000625 take the ulps) |
| V-3 | Exempt / 0 % groups participate | probe | **confirmed** — 0 % carries `disc 5.391`, base 63.609, VAT 0.000 |
| V-4 | Line-level discounts untouched | `vatDiscountAllocation.ts:23-33` groups from `line_subtotal`/`line_vat`, which already contain them; the allocator only SUBTRACTS from the line sums | **confirmed by construction** |
| V-5 | FR 2-dp case | probe, 20 %/5.5 % + 10.00 remise, scale 2 | `76.92+15.39 / 26.25+1.44`, sum 120.00, both rates internally consistent |
| V-6 | Scale 0 (JPY) and 100 %-comp | probe | comp lands on **exactly** `net == vat == 0` per group; scale-0 sum 99 |
| V-7 | Golden SHA-256 `4343092a…` reproducible | `hashlib.sha256` over `expected_canonical_string` (3242 bytes), outside PHPUnit | **matches** `4343092af0b38704a2ca5f83cb84006db41c1c8c6d39a2dbc6cfb4aaed012391` |
| V-8 | `V3ReceiptHashComputer` NOT bumped, and safely so | `git diff dev...HEAD --name-only` shows the file untouched; its canonical input (`V3ReceiptHashComputer.php:74-110`) reads only `tax_rate` + `vat_amount` from `vatDetails`, plus `total`/`currency`/`payments`/vouchers/`exchange_group_id`/`audit`. `discount_allocated` is not in the input set | **claim holds** — historical rows re-hash identically |
| V-9 | v1–v4 parse forever | `FiscalEventPayloadRegistry.php:144` gives `[1,2,3,4,5]`; `SaleReceiptV5PostRemiseVatBaseTest::test_v3_still_accepts_the_pre_discount_base_forever` green | **confirmed** |
| V-10 | Device / PHP allocator parity | `decimal.ts:13` sets `Big.RM = 1` (half-up) and `bcdiv` rounds at scale; `TransactionDiscountVatAllocator::roundHalfUp()` (`:212-224`) adds half an ulp before truncating. Same algorithm, same rounding mode, `scale+4` intermediates on both sides | **parity holds** |
| V-11 | v5 breakdown ordering == v1 ordering (hash-relevant) | `SaleReceiptPayload.ts:345-346` sorts `rate|category` via `localeCompare`; `vatDiscountAllocation.ts:118` sorts the identical key the identical way | **confirmed** (probe order `0.00, 13.00, 19.00, 7.00` on both) |
| V-12 | `pos_receipts_totals` widened to a disjunction, VALIDATED | PG probe on a migrated throwaway DB: `CHECK (((total = subtotal+tax_amount-discount_amount+COALESCE(cash_rounding_adjustment,0)) OR (total = subtotal+tax_amount+COALESCE(cash_rounding_adjustment,0))))`, `convalidated = true` | **confirmed** |
| V-13 | `pos_receipt_payments CHECK (amount > 0)` KEPT and in force | same probe: `pos_receipt_payments_amount CHECK ((amount > 0::numeric))`, validated | **confirmed** |
| V-14 | `discount_allocated` additive, nullable, `decimal(12,3)` | `information_schema.columns` on PG: `numeric(12,3)`, `is_nullable = YES` | **confirmed** |
| V-15 | Migration self-guards on sqlite | `…widen_pos_receipts_totals_check…:70-72` returns early for non-pgsql; `NOT VALID` + savepoint-protected `VALIDATE`, re-raising anything that is not `23514` (`:96-113`) | **confirmed** |
| V-16 | Projection test clears `CompanyContext` before `apply()` (rule 20) | `PosReceiptV5DiscountVatBaseProjectionTest.php:299` calls `app(CompanyContext::class)->clear();` inside `project()` | **confirmed** |
| V-17 | Zero-tender comp: no tender leg on the wire | `receiptService.ts:401-411` filters zero legs before BOTH the canonical payload and `payments_json`; `buildReceiptData.ts` prints no tender row | **confirmed**; PG projection test proves the CHECK still bites (23514) |
| V-18 | Server-authored path fixed too | `ReceiptCreationService.php:491-530` — reconcile to gross, ventilate, then DERIVE `subtotal`/`totalTax` from the groups; `ReceiptVatDetail::create(array_merge($vatData, …))` at `:668` now carries `discount_allocated` | **confirmed** |
| V-19 | Z / X / EOD read the SEALED aggregates | `zReportService.ts:978-1032`, `endOfDayPreview.ts:264-384`, `reportApi.ts:590-628` all go through `readSealedReceiptView(receipt.canonical_bytes)`; the Z SELECT is `SELECT * FROM offline_receipts` (`zReportService.ts:182/189`) so the column IS present | **confirmed** |
| V-20 | Server Z/X stays internally coherent at v5 | `ReportGenerationService.php:1307-1309` — netSales += subtotal, taxAmount += tax_amount, gross += total. At v5 `524.547 + 65.453 == 590.000`; pre-D-1 it did NOT reconcile (`569 + 71 != 590`). D-1 **improves** this arm | **confirmed** |
| V-21 | Ledger arm era-aware, balanced, versioned by the sealed fact | `PosReceiptVatAllocator.php:143-152, 161-173, 199-215, 310-341`; the discriminator is `discount_allocated IS NULL`, mixed sets refused via `SealedBaseEraAmbiguous` | **confirmed**; 44 tests / 153 assertions green on PG |
| V-22 | Redelivery is safe under the new gate | `OutboxIngestor::handleConflict()` returns `IngestionResult::idempotent()` on a byte-identical re-delivery WITHOUT touching the stored row's parse status — so a legit v3 redelivered after the terminal is watermarked is NOT retro-quarantined | **confirmed** |
| V-23 | No float, no bare `getScale()` introduced | grepping the diff for `parseFloat` / `Number(` / `(float)` returns only the two pre-existing relocated `(0).toFixed(decimals)` lines and `Big.toFixed` | **clean** |

### Gates I re-ran myself

| Gate | Command | Result |
|---|---|---|
| Device D-1 suites | `vitest run --pool=forks --poolOptions.forks.maxForks=1` on the 4 new fiscal files | **31/31 green** |
| Device offline/print regression | 5 files incl. `saleHeadlineTotalsEndToEnd`, `receiptService`, `buildReceiptData` | **94/94 green** |
| Server unit (golden + PHP allocator) | sqlite | **11 tests / 38 assertions green** |
| Server feature D-1 + ledger | sqlite | **35 tests / 99 assertions green** |
| Server feature D-1 | **PG** `autoerp_test_d1g` @127.0.0.1:5433 | **19 tests / 50 assertions green** |
| Ledger arm (allocator + GL split + census) | **PG** | **44 tests / 153 assertions green** |
| Ingestion contract | `OutboxIngestorTest` + `FiscalEventIngestionEndpointTest` + `FiscalPayloadConstraintValidatorTest`, sqlite | **192 tests / 533 assertions green** |
| PHPStan L8 on the 5 heaviest lane files | `phpstan analyse` | **No errors** |
| Deptrac ratchet | `tools/deptrac-ratchet.php` | **PASS 183/183**, `deptrac.baseline.json` untouched vs dev |
| Feature-lane manifest | `tools/feature-lane-manifest-check.php` | **EXIT 0** — 1423 classes / 74 groups |
| Manifest union vs CURRENT dev | dev `ea2b5d628` = gated 1173 / Fiscal 80 / POS 155; lane = **1175 / 81 / 156** | **exact +2/+1/+1 — correct union at this dev tip** (re-union at merge if dev moves) |
| POS lint | `pnpm lint` | **84 warnings, 0 errors** (matches the claimed dev baseline; eslint-rule RuleTesters pass) |
| POS typecheck | `pnpm typecheck` | **clean** |

### Red-proofs — I reproduced all three from a throwaway worktree (never the lane)

| Tamper | Expected | Observed |
|---|---|---|
| PHP `splitAllocated` returns `['0','0']` (pre-D-1 behaviour) | 2 failures | **2 failures**, incl. `'0.000'` vs `'200.005'` on the full-comp case |
| `SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION = 99` | 8 of 12 | **2 errors + 6 failures**, exactly as claimed |
| Device allocator stops subtracting the allocated share | red | **9 of 17 device tests red** across all three v5 files |

The lane is honestly red-proved. The tests bind to the behaviour they claim to bind to.

---

## FINDINGS

### 1. [CRITICAL] A `SALE_RECEIPT` at `event_version = 5` may declare `invoice_type_code = REFUND` or `VOID`, and every v4 refund invariant is skipped

`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1233`
(also `:1349`, `:1397`, `:1440`) and `apps/pos/src/lib/fiscal/FiscalEventEngine.ts:1656`, `:1740`,
`:1758`, `:1803`.

The diff does two things at once: it adds `5` to the parseable set
(`FiscalEventPayloadRegistry.php:144`) and it narrows five refund guards from `$eventVersion >= 4`
to `$eventVersion === 4`. The narrowing is correct in isolation — a `>= 4` test would have handed v5
the 33-key refund set. But the result is that **v5 inherits no invoice-type restriction whatsoever**:
`assertEnum($payload, 'invoice_type_code', self::INVOICE_TYPE_CODES)` at `:1225` still admits
`{SALE, REFUND, VOID, TRAINING}`, and the v4 block that refuses `VOID` outright and requires
`REFUND` no longer fires.

**Proven by execution.** Taking the lane's own golden v5 payload, setting
`invoice_type_code = 'REFUND'` and attaching a well-formed `original_receipt_reference`:

```
KEYSET v5 REFUND: NULL
RESULT: v5 REFUND *** ACCEPTED *** (no refund invariants applied)
RESULT: v5 VOID   *** ACCEPTED ***
```

What is bypassed, all of it reachable only at v4 today: the `payload_void_authoring_prohibited`
refusal, `validateOriginalLineReferences()` (the strict parallel-array pin to `line_items[]`),
`validateRefundDestinationAndSettlementAllocation()`, `validateSingleCashLegPayment()`, and
`payload_v4_refund_transaction_discount_must_be_zero`. Downstream,
`PosCoreReceiptProjection::resolveReceiptType()` keys off `invoice_type_code`, so such a payload
projects as a **return** — negative revenue, a restocking movement, and an original-receipt
resolution that was never validated.

This hole did not exist before the diff: pre-D-1 a v5 envelope was refused outright by the
registry's supported-version set. It is opened by this lane.

The device engine has the mirror gap at `FiscalEventEngine.ts:1656` — the comment there
(`:1650-1653`) explicitly calls the VOID check a *"defense-in-depth boundary check … never trusted
to be redundant"*, and at v5 it is now absent. The device registry still refuses to author v5
REFUND/VOID, so the device side is defence-only, but the comment's own standard is not met.

**Fix.** Add an explicit v5 invoice-type clause next to the v4 one, on both sides:

```php
if ($eventVersion >= self::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION) {
    $invoiceType = $payload['invoice_type_code'] ?? null;
    if ($invoiceType !== 'SALE' && $invoiceType !== 'TRAINING') {
        throw new RuntimeException(
            'payload_invoice_type_invalid:event_version>=5 requires invoice_type_code=SALE|TRAINING; got '
            .var_export($invoiceType, true)
        );
    }
}
```

and the TS equivalent guarded by `eventVersion >= 5`. Pin both with a case in
`SaleReceiptV5PostRemiseVatBaseTest` (v5 REFUND refused, v5 VOID refused, v5 SALE and v5 TRAINING
accepted) and in the device `FiscalEventEngine` suite.

---

### 2. [CRITICAL] The v5 partition check no longer pins the net/VAT split — a payload under-declaring VAT by 30.703 TND on the ruling's own example is ACCEPTED

`apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:2902-2957`
(`validateVatPartitionGroupV5`).

At v1–v4 the partition check pinned each group's VAT **exactly** to the line roll-up
(`payload_partition_vat_mismatch`). At v5 that equality is replaced by a much weaker set:
`discNet >= 0`, `discVat >= 0`, `discNet + discVat == discount_allocated`, and
`gross == lineGross − discount_allocated`. The split between `discNet` and `discVat` is **completely
unconstrained** — the server explicitly refuses to divide by a rate (`:2905-2907`).

**Proven by execution.** Starting from the lane's own golden payload and re-splitting each group so
the whole allocated remise comes out of the VAT half wherever the group can carry it — same lines,
same `total = 590.000`, same `sum(discount_allocated) = 50.000`, same group grosses:

```
MIS-SPLIT subtotal=555.250 vat_total=34.750  (golden was 524.547 / 65.453)
RESULT: mis-split *** ACCEPTED *** — server does not pin the net/vat split
```

That is **30.703 TND of output VAT under-declared on a single ticket**, sealed into the chain and
read verbatim by `EloquentVatDataRepository`, with the server contract validator raising nothing.
The slack is bounded by `min(discount, sum(line_vat))` per receipt — i.e. it scales with the remise.

The handback's version-gate matrix (§5) claims the row *"v5 declared, PRE-discount base inside →
REFUSED `payload_total_arithmetic_mismatch`"*. That specific row IS true (I checked: 640.000 vs
590.000). But the matrix is **incomplete**: it only covers a wrong base that also breaks the total
identity. A wrong base that preserves the total identity — which is precisely what a mis-ventilating
device produces — is not covered by anything.

Note the asymmetry this creates: the ONE thing the server used to guarantee about the number that
goes on the VAT return (that it equals the line roll-up) has been traded away on exactly the receipts
D-1 targets.

**Fix.** The check does not have to recompute the device's VAT; it only has to bound the split.
Inside `validateVatPartitionGroupV5`, after `$discSplit` is proven equal to `$allocated`, compare
`$discVat` against the rate-implied VAT half of `$allocated` (half-up at `$scale`) and refuse a
deviation wider than one ulp — the band is needed because the device clamps the split inside the
group's own line sums (`vatDiscountAllocation.ts:254-261`). The cleanest implementation reuses
`TransactionDiscountVatAllocator::splitAllocated()`, which already computes exactly that pair and is
already in the codebase, and raises a new typed reason
(`payload_partition_discount_vat_split_out_of_band:rate=…:expected=…:got=…`). Pin it with the
mis-split payload above as a new case in `SaleReceiptV5PostRemiseVatBaseTest`.

---

### 3. [IMPORTANT] The forward gate is not scoped to `chain_context`, so a pending pre-upgrade TRAINING sale is silently quarantined after the first operational v5

`apps/api/app/Modules/Fiscal/Application/Services/SaleReceiptForwardVersionGate.php:71-76` — the
watermark query filters `tenant_id`, `company_id`, `terminal_id`, `event_type`, and nothing else.

The device drains its outbox `ORDER BY chain_context ASC, sequence_number ASC`
(`apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:117-118`). `'operational'` sorts before
`'training_operational'`. So on a terminal that takes the D-1 build while it still has unsynced
training-mode sales:

1. the post-upgrade **operational** v5 receipts drain first and set the terminal watermark to 5;
2. the pre-upgrade **`training_operational`** v3 receipts drain next;
3. the gate sees a v3 from a watermarked terminal and returns
   `sale_receipt_version_downgrade:` — they are stored and **never projected**.

Within a single `chain_context` the ordering is safe (v3 stragglers carry lower `sequence_number`
and drain first) — I checked, and that is why the common case works. The cross-context case is not
covered, and the consequence is a real sale sitting permanently unprojected: no `pos_receipts` row,
no GL entry, and — because stock is decremented **only** in `PosCoreReceiptProjection` — no stock
movement either.

**Fix.** One clause, and it does not weaken the gate (each chain is its own monotone sequence, and
the per-chain grain is exactly the grain the watermark argument is made on):

```php
->where('chain_context', $envelope->chainContext)
```

`FiscalEventEnvelope::$chainContext` already exists (`FiscalEventEnvelope.php:91`). Pin with a case
in `PosReceiptV5DiscountVatBaseProjectionTest`: a v5 watermark on `operational` must NOT refuse a v3
on `training_operational` for the same terminal.

**Related failure modes, for the record** (state them on the rollout row rather than fix them):
a terminal REINSTALL that keeps its `terminal_id` keeps its watermark, so a genuine build rollback
quarantines every subsequent sale; a terminal SWAP (new `terminal_id`) starts unwatermarked and gets
no protection at all; and a spoofed or malformed v5 envelope accepted into the chain poisons a real
terminal's watermark (the query does not filter `payload_parse_status`).

---

### 4. [IMPORTANT] The owner ruling is applied to `SALE_RECEIPT` only — `ACCOUNT_CHARGE` still seals VAT on the PRE-discount base

`apps/pos/src/lib/accountCharge/accountChargeCartMapper.ts:101-127` — the on-account path computes
`subtotal` and `vatTotal` from the line roll-up and applies `transactionDiscountAmount` to `total`
alone (`:115`). This is verbatim the defect D-1 was opened to remove, on a sibling event type that
is fed by the **same cart and the same transaction-discount UI**.

Consequences today:

- `TreasuryAccountChargeBridge.php:80-101` calls `GeneralLedgerService::createPOSChargeEntry()`,
  which credits `4457` with the **pre-remise** VAT and books the remise to `709` — internally
  balanced, but it over-credits VAT collected on any discounted on-account sale;
- the same cart now produces **two different taxable bases** depending on tender: post-remise if
  paid, pre-remise if charged to account. That is a defensibility problem in front of an inspector,
  not just an inconsistency.

`ACCOUNT_CHARGE` projects to `account_charge_receipts`
(`AccountChargeReceiptProjection.php:59-71`), not to `pos_receipt_vat_details`, so the DGI
declaration (`EloquentVatDataRepository.php:130-142`) does **not** currently read it — which is why
this is Important rather than Critical, and why it is arguably a separate pre-existing hole. But
D-1's ruling makes the divergence new and deliberate-looking.

**Fix.** Out of scope for this lane, but it must be BOOKED as a named follow-up lane with the same
owner ruling attached (an `ACCOUNT_CHARGE` version bump with a ventilated base +
`discount_allocated`), and the D-1 rollout note must say plainly that the ruling is not yet in force
on the on-account tender. Related, and already noted by the handback: `createPOSChargeEntry()` books
revenue at the pre-discount base with `709` as contra and will need the same era treatment.

---

### 5. [IMPORTANT] The gate runs a `MAX(event_version)` over the terminal's entire chain on EVERY pre-v5 `SALE_RECEIPT` ingest, with no supporting index

`SaleReceiptForwardVersionGate.php:71-76`. `fiscal_events` carries
`UNIQUE (tenant_id, company_id, terminal_id, chain_context, sequence_number)`
(`2026_05_14_100001_create_fiscal_events_table.php:92-95`) and four partial indexes, none of which
covers `event_type` or `event_version`. The query therefore does an index range scan over **every
event that terminal has ever authored**, then filters and aggregates — on the ingest hot path, for
every receipt from every not-yet-upgraded terminal, which is *all* of them until the build ships.

**Fix.** Turn it into an existence probe on a tiny partial index:

```sql
CREATE INDEX fiscal_events_sale_receipt_v5_watermark_idx
    ON fiscal_events (tenant_id, company_id, terminal_id, chain_context)
    WHERE event_type = 'SALE_RECEIPT' AND event_version >= 5;
```

and replace `->max('event_version')` with `->where('event_version', '>=', $threshold)->exists()`.
Semantically identical (the gate only ever compares against the threshold), constant-time, and it
makes the index the honest expression of what the watermark is.

---

### 6. [IMPORTANT] The lane's only two regression pins execute NOWHERE in CI

`grep -c "SaleReceiptV5PostRemiseVatBaseTest\|PosReceiptV5DiscountVatBaseProjectionTest"
.github/workflows/ci.yml` returns **0**. Both live in lanes parked behind the unflipped
`vars.SELF_HOSTED_RUNNER_READY` gate, and neither is named in a live `--filter` allowlist. The
handback flags this as a P1 residual; I am escalating it to a merge condition because these two
classes are the **entire** regression surface for the post-remise base, and
`PosReceiptV5DiscountVatBaseProjectionTest` is PG-meaningful by construction (it is the class that
proves the widened CHECK and the `amount > 0` CHECK are both still in force).

**Fix.** Add both to the `backend-test-pgsql` `--filter` allowlist, per the Q-7 / B-3 precedent
already recorded in the manifest notes for `TerminalClaimHardeningTest`.

---

### 7. [MINOR] The registry comment describes a gate mechanism that does not exist

`apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php:143-144`:

> *"What stops a NEW device authoring the old shape is the forward-only version gate on the device's
> recorded `app_version` (see `SaleReceiptForwardVersionGate`), not this list."*

`SaleReceiptForwardVersionGate` does not read `devices.app_version` — its own docblock (`:23-37`)
explains at length why it deliberately does **not**, and uses the per-terminal chain watermark
instead. The registry comment is the exact statement the gate was written to refute. On a fiscal
contract file this is the kind of comment that misleads the next reader into "fixing" the gate.

**Fix.** Replace `the device's recorded app_version` with `the per-terminal v5 chain watermark`.

---

### 8. [MINOR] `Subtotal` on the printed ticket silently changes meaning from HT to TTC, including on reprints of pre-D-1 receipts

`apps/pos/src/lib/buildReceiptData.ts:209-223` now derives the printed subtotal as
`total + discount_amount − rounding` on the server-receipt path. For a v5 receipt that is right
(`receipts.subtotal` is the net base, and printing it beside a Remise line would double-count).
But the same expression runs when **reprinting a v1–v4 receipt**, where `receipts.subtotal` was the
pre-discount NET: the reprint now shows `640.000` where the original ticket showed `569.000`.

The ticket stays internally consistent either way (`Subtotal − Remise == TOTAL` holds in the new
layout; the Rust test at `receipt_template.rs:1580-1585` asserts exactly that), so this is a
presentation change, not a fiscal one. But NF525 reprint fidelity is a defensible expectation, and
the `Subtotal` label is now TTC while the i18n string may still read as an HT subtotal in some
locales.

**Fix.** Either (a) accept it and re-label the line to make TTC explicit in every locale, or
(b) branch the derivation on whether the row carries a post-remise base (`discount_allocated`
present on its vat details) so historical reprints stay byte-faithful. State the choice on the
rollout row.

---

### 9. [MINOR] Per-group VAT can sit 1 ulp off `base × rate` because the split is subtracted rather than re-derived

`vatDiscountAllocation.ts:148-149` computes `net_r = sum(line_subtotal) − discNet` and
`vat_r = sum(line_vat) − discVat` rather than `net_r = net_ttc_r / (1 + rate)`. This is a deliberate
and well-argued choice (`:34-41`: it makes the discount-free case byte-identical to the pre-D-1
breakdown and keeps the server recomputation-free), and the ruling's own example lands exactly. But
the two derivations can differ by one ulp: my probe produced a 19 % group with `net 0.083 / vat 0.016`
where `0.083 × 19 % = 0.01577`. A ±1-centime deviation is universally tolerated in TN and FR VAT
practice, so this is a note, not a defect — but it should be written down in the precision contract
next to the `unit_price` section, because the next reviewer will re-derive it and think it is a bug.

---

## RULING requested by the brief §6 — "is *no 709 for an on-invoice remise* right?"

**Yes. Shape A is correct and the lane's reasoning holds.** Under the French PCG and the Tunisian
plan comptable, account `709 "Rabais, remises et ristournes accordés"` records RRR granted **hors
facture** — by avoir, after the sale. An RRR **déduite sur la facture** is deducted directly from the
sale, which is then recorded net; debiting `709` as well would deduct it twice. The owner ruling
makes the POS remise an on-invoice remise by definition (it reduces the taxable base ON the ticket),
so `Dr 53 590.000 / Cr 70x 524.547 / Cr 4457 65.453` is the doctrinally correct entry, and the
pre-D-1 `709` leg was correct *only* because the sealed base was not reduced. Keeping this
**version-aware rather than replacing it** (`PosReceiptVatAllocator.php:310-341`) is the right call,
and the refusal of a MIXED set rather than a guess is the right failure mode.

**One consequence to book, not a defect:** after D-1 there is **no GL trace of a POS transaction
remise at all**. Any management report that reads discount-granted off account `709` will read zero
for v5 receipts. That information now lives only in `pos_receipts.discount_amount` and
`pos_receipt_vat_details.discount_allocated`. Whoever owns discount reporting needs to be told.

---

## POS release-coupling row for the LEDGER

> **D-1 — post-remise VAT base (`SALE_RECEIPT` v5).** MIGRATION-BEARING + POS-BUILD-COUPLED.
> **Order: server first (both tenant migrations `2026_08_25_090000` and `2026_08_25_090100` applied
> fleet-wide), then the POS build.** The reverse strands every v5 receipt in quarantine
> (`envelope_event_version_mismatch`), and without migration `090100` a discounted v5 receipt fails
> `pos_receipts_totals` on PostgreSQL. Per-tenant pre-flight census (must be 0) is in the migration
> docblock. **A POS rollback after D-1 is NOT a supported operation**: once a terminal seals a v5
> receipt its chain watermark is permanent, and every subsequent pre-v5 receipt from that terminal is
> refused as `sale_receipt_version_downgrade` — stored, never projected, which means **no
> `pos_receipts` row, no GL entry and no stock decrement** for those sales (stock is authored only in
> `PosCoreReceiptProjection`). If the fleet must roll back, the D-1 server code has to roll back with
> it. Until finding 3 is fixed, ALSO drain every terminal's outbox to zero (including the
> `training_operational` chain) BEFORE it takes the build. The ruling is **not yet in force on the
> on-account tender** (finding 4).

---

## Merge conditions

**Blocking:** findings 1, 2, 3 — all three are contained server-side fixes plus pins; no device
re-authoring, no new canonical version, no change to the sealed bytes.
**Condition of merge (non-blocking on code):** finding 6 (name both classes in the live
`backend-test-pgsql` `--filter` allowlist) and finding 4 booked as a named lane with the owner
ruling attached.
**Re-union at merge:** the manifest raise is exact against dev `ea2b5d628` (1173/80/155 to
1175/81/156). If dev moves before merge, re-take the union.

**Hygiene note:** a throwaway database `autoerp_test_d1t` is still present on `127.0.0.1:5433`
(mine — `autoerp_test_d1g`, `autoerp_test_d1g2` — are dropped, verified absent). It is not this
review's; whoever owns it should drop it.

---

*Gate run 2026-08-25. Every command above was executed against the lane worktree read-only; all
tampering was done in a detached throwaway `git worktree` with its own cloned `vendor` (class
resolution verified inside it), removed afterwards. The lane worktree is untouched
(`git status --short` empty). No merge performed.*
