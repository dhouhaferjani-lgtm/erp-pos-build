# Research B — Goods receipt against a purchase order: stock + costing truth

Scope: backend (`apps/api`) + web frontend (`apps/web`). Repo read at worktree
`/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow`, branch `dev`.
All citations are `path:line` relative to `apps/erp`. Read-only research; nothing modified.
Anything I could not verify by reading the line is marked **UNVERIFIED**.

---

## 0. The shape of the flow (one paragraph)

A goods receipt is **not** a `documents` row. It is a row in the tenant table
`goods_receipts` (+ `goods_receipt_lines`), created by
`GoodsReceiptService::createDraft()` and made real by `::post()`.
`receiveGoods()` is just `createDraft()` + `post()` inside one transaction
(`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:75-98`).
The purchase order itself is mutated in place: `document_lines.quantity_received`
accumulates, and the PO header flips `Confirmed → Received` only when **every**
line is fully received. Stock, WAC and the GR‑IR journal entry are all written
by `post()`.

---

## 1. `GoodsReceiptService` — public surface

File: `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php` (1009 lines).

Constants: `QUANTITY_SCALE = 4`, `COST_SCALE = 6`, `WORKING_SCALE = 10`
(`:37`, `:39`, `:41`).

Constructor deps (`:43-52`): `WeightedAverageCostService`, `LandedCostService`,
`BatchStockService`, `ProductCostLock`, `DocumentNumberingService`,
`ReceiptBatchCostAllocator`, `CurrencyScaleResolverInterface`, `GeneralLedgerService`.

### 1.1 `receiveGoods(...)` — `:65-99`

```php
public function receiveGoods(
    Document $purchaseOrder,
    array $receivedQuantities,          // line_id => paid qty (string)
    array $batchData = [],              // line_id => {batch_number, expiry_date, manufacturing_date?}
    array $freeQuantities = [],         // line_id => free qty (string)
    array $receivedUnitPrices = [],     // line_id => price override (string)
    ?string $priceOverrideReason = null,
    ?string $actorId = null,
    ?string $destinationLocationId = null,
): GoodsReceiptResult
```
Body (`:75-98`): one `DB::transaction`, `createDraft(...)` then `post(...)`, returns
`GoodsReceiptResult(freshOrder, freshReceipt)`. Because both halves share the
transaction, a `post()` failure rolls back the draft too — **no orphan draft row**
on the `receiveGoods` path.

### 1.2 `createDraft(...)` — `:107-201`

- `assertReceivablePurchaseOrder($po)` first (`:119`).
- Creates the `goods_receipts` row with `receipt_number => null`,
  `status => Draft`, `location_id => $destinationLocationId` (may be null),
  and `payload.batch_data = $batchData` (`:125-141`). **Batch data lives only in
  the receipt payload until post.**
- Per PO line (`:143-190`): skips a line when BOTH paid and free qty are `<= 0`
  (`:149-151`); skips lines with `product_id === null` (`:153-155`);
  `assertQuantitiesWithinRemaining()` (`:157`); price-override validation
  (`:160-166`, throws `received_unit_price must be greater than zero for line {id}.`);
  writes a `goods_receipt_lines` row with all cost columns NULL (`:168-188`).
- **`createDraft` does NOT validate batch data.** A batch-tracked product with no
  batch row is accepted here and only explodes at `post()` (see §3).
- If no line qualified: `throw new \DomainException('No items to receive. Please specify quantities to receive.')` (`:192-194`).

### 1.3 `post(GoodsReceipt $receipt, string $actorId, ?string $destinationLocationId = null, bool $failClosedGrir = false)` — `:203-292`

1. `lockForUpdate()` re-read of the receipt (`:207-210`).
2. Refuses non-Draft: `"Goods receipt {id} must be Draft before posting."` (`:212-214`) → **posting twice is refused**.
3. `assertReceivablePurchaseOrder()` (`:218`) and `assertCanApplyDraftPriceOverrides()` (`:219`).
4. `resolveDestinationLocation($po, $destinationLocationId ?? $receipt->location_id)`, then persists `location_id` on the receipt (`:222-224`).
5. If `landedCostService->hasAllocatedCosts($po)` → `reallocateCosts($po)` and reload lines (`:227-231`). **This is where a landed cost added after confirm gets folded in.**
6. `costLock->acquire(tenant, company, productIds, …)` (`:242-290`) — sorted product advisory locks.
7. Inside the lock: `$purchaseOrder->load('lines')` re-read (comment `:247-251` explains this is what makes the over-receive re-validation see post-serialization counters).
8. Allocates the **receipt number** here: `numberingService->generateForKey(tenant, company, 'goods_receipt', 'GRN')`, sets `status => Posted`, `received_by => actorId` (`:254-263`). So a draft has `receipt_number = null`; the GRN number is minted at post.
9. `receiptBatchCostAllocator->allocate(...)` (`:265-269`) then `processReceiptLines(...)` (`:271-283`).

### 1.4 `processReceiptLines(...)` — `:433-764` (private, the real engine)

Per PO line, in order:
- skip when both qtys `<= 0` (`:491-493`);
- **re-run** `assertQuantitiesWithinRemaining()` (`:496`);
- skip `product_id === null` (`:503-505`);
- product loaded **scoped by the PO's tenant + company** (`:515-518`) — a forged cross-tenant `product_id` resolves to null and the line is skipped (`:519-521`);
- **batch guard** (`:523-525`, see §3);
- cost basis: `landed_unit_cost ?? unit_price` (`:529`), price-override / freight-pool selection (`:532-547`);
- free qty leg first: `wacService->recordPurchase(..., landedUnitCost: '0', ...)` (`:570-579`), buffered `GoodsReceived` event (`:582-601`), `batchStockService->receiveBatchStock()` if a batch (`:604-612`), `free_quantity_received = bcadd(already, free, 4)` (`:614`);
- paid qty leg: `recordPurchase(..., landedUnitCost: $landedUnitCost, ...)` (`:619-628`), buffered event (`:631-650`), batch stock (`:653-661`), **`accrual_unit_cost` set once and only once** (`:666-668`), `quantity_received = bcadd(already, qty, 4)` (`:670`);
- `line->batch_id = $batch->id` when a batch was used (`:673-675`);
- **`line->location_id = $location->id`** — "the latest receipt destination owns the unreceived remainder for this PO line" (`:677-678`);
- `line->save()` (`:680`);
- upserts the `goods_receipt_lines` row with the computed costs, `movement_id`, `free_movement_id`, `price_override_*` (`:684-714`).

Then:
- `if (! $hasReceivedItems) throw new \DomainException('No items to receive. Please specify quantities to receive.')` (`:719-721`);
- `$fullyReceived = $this->isFullyReceived($purchaseOrder)` (`:724`);
- PO header update (`:727-734`):
  ```php
  'status'  => $fullyReceived ? DocumentStatus::Received : $purchaseOrder->status,
  'payload' => array_merge($po->payload ?? [], [
      'last_goods_receipt_at' => now()->toDateTimeString(),
      'fully_received'        => $fullyReceived,
      'goods_received_at'     => $fullyReceived ? now()->toDateTimeString() : null,
  ]),
  ```
  → **there is no "partially received" document status.** Partial receipt leaves the PO `Confirmed`; partiality is only computed on demand by `getReceiptStatus()`.
- `flushPendingGlPostings($pendingGlPostings)` LAST (`:755`), after every row lock — see §8.

### 1.5 `receiveAll(...)` — `:771-796`

Builds `receivedQuantities` = per-line `quantity − quantity_received` for the lines
with a positive remainder (`:775-782`) and `freeQuantities` likewise (`:786-793`), then:

```php
// GoodsReceiptService.php:795
return $this->receiveGoods($purchaseOrder, $receivedQuantities, [], $freeQuantities, [], null, $actorId, $destinationLocationId);
```

**`$batchData` is hard-coded `[]`.** That is the whole bug (§1.7).

### 1.6 Other public methods

| method | line | notes |
|---|---|---|
| `poLineIdsWithReceipts(array $poLineIds)` | `:802-818` | returns PO line ids that already have a `goods_receipt_lines` row. **No status filter** → a *draft* receipt already locks the PO lines against editing. Also no explicit tenant/company predicate (relies on database-per-tenant isolation). |
| `getReceiptStatus(Document $po)` | `:876-923` | returns `status` ∈ `not_received` / `partially_received` / `fully_received` (`:910-914`), totals, per-line breakdown incl. `free_quantity_*`. Note `percentage` at `:906-908` uses **float** casts (display-only). |
| `isFullyReceived(Document $po)` | `:928-942` | false if ANY line has `received < quantity` **or** `free_received < free_quantity` (`:936-938`). |
| `hasReceivedGoods(Document $po)` | `:947-959` | any positive received or free-received. |

Private helpers: `assertReceivablePurchaseOrder` `:294-303`,
`assertQuantitiesWithinRemaining` `:309-332`, `assertCanApplyDraftPriceOverrides` `:334-349`,
`draftInput` `:360-387`, `flushPendingGlPostings` `:400-417`,
`hasPositiveFreightPool` `:823-831`, `effectiveUnitCost` `:839-851`,
`landedUnitCostForReceipt` `:859-869`, `getDefaultLocation` `:966-982`,
`resolveDestinationLocation` `:984-1008`.

### 1.7 THE "receive-all without quantities fails for batch-tracked lines" BUG

**Guard (exposes the product UUID):**

```php
// apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:523-525
if (($product->requires_batch_tracking ?? false) && ! isset($batchData[$line->id])) {
    throw new \DomainException("Batch data is required for batch-tracked product {$product->id}");
}
```

**Controller branch that can never supply batch data:**

```php
// apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:841-843
} else {
    // Receive all remaining quantities
    $updatedDocument = $this->goodsReceiptService->receiveAll($documentModel, $user->id, $destinationLocationId);
}
```

`receiveAll()` calls `receiveGoods(..., [], ...)` (`GoodsReceiptService.php:795`),
so `$batchData` is `[]` and every batch-tracked line trips `:524`.

**Does the message expose the product UUID? YES** — `{$product->id}` is the product
UUID (products use `HasUuids`). The message is surfaced verbatim to the client:
`PurchaseOrderController.php:857-858` maps `\DomainException` to
`validationErrorResponse('GOODS_RECEIPT_FAILED', $e->getMessage())` → HTTP 422 with a
raw UUID and no product name/SKU. For a Tunisian parapharmacy (products default to
`requires_batch_tracking = true`, §3) the operator sees an unactionable UUID.

**Same defect on the conversion path**:
`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/PurchaseOrderToGoodsReceiptConverter.php:140` and `:143`
also pass `[]` for `$batchData` on BOTH branches — so even the "with quantities"
conversion branch cannot receive a batch-tracked product.

**Reachability from the API:** the `else` branch at `:841-843` is taken when
`save_as_draft` is falsy AND (`quantities` empty/absent) AND (`free_quantities`
empty/absent) — see the `elseif` at `:829`. So `POST /purchase-orders/{id}/receive`
with an empty body `{}` is the reproducer.

---

## 2. Over-receipt / under-receipt / what closes the PO

### 2.1 Over-receipt is REFUSED (hard)

```php
// apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:309-332
private function assertQuantitiesWithinRemaining(DocumentLine $line, string $qtyToReceive, string $freeQtyToReceive): void
{
    $alreadyReceived = (string) ($line->quantity_received ?? '0.00');
    $remaining = bcsub((string) $line->quantity, $alreadyReceived, self::QUANTITY_SCALE);   // :313

    if (bccomp($qtyToReceive, '0.00', self::QUANTITY_SCALE) > 0 && bccomp($qtyToReceive, $remaining, self::QUANTITY_SCALE) > 0) {   // :315
        throw new \DomainException(
            "Cannot receive more than ordered for line {$line->id}. ".
            "Ordered: {$line->quantity}, Already received: {$alreadyReceived}, Requested: {$qtyToReceive}"
        );   // :316-319
    }

    $alreadyFreeReceived = (string) ($line->free_quantity_received ?? '0.00');
    $freeRemaining = bcsub((string) ($line->free_quantity ?? '0.00'), $alreadyFreeReceived, self::QUANTITY_SCALE);   // :324

    if (bccomp($freeQtyToReceive, '0.00', self::QUANTITY_SCALE) > 0 && bccomp($freeQtyToReceive, $freeRemaining, self::QUANTITY_SCALE) > 0) {   // :326
        throw new \DomainException("Cannot receive more free quantity than ordered for line {$line->id}. …");   // :327-330
    }
}
```

- **No tolerance band. Zero over-receipt allowed** — not even a rounding epsilon.
- Comparison is at scale 4 (`QUANTITY_SCALE`), so `10.00005` over a remaining of
  `10.0000` truncates to `10.0000` under `bccomp(..., 4)` and passes. (bccomp
  truncates operands to the given scale.)
- Called **twice**: once in `createDraft` (`:157`), once again inside
  `processReceiptLines` under the cost lock (`:496`). The second call is the
  serialization-safe one.
- Error message leaks the **PO line UUID** (`{$line->id}`), not the product name.
- Free quantities have their own independent ceiling (`free_quantity` column).

### 2.2 Accumulation of `received_quantity`

```php
// GoodsReceiptService.php:670
$line->quantity_received = bcadd($alreadyReceived, $qtyToReceive, self::QUANTITY_SCALE);
// GoodsReceiptService.php:614
$line->free_quantity_received = bcadd($alreadyFreeReceived, $freeQtyToReceive, self::QUANTITY_SCALE);
```
`$alreadyReceived` / `$alreadyFreeReceived` are read at `:498` / `:500`, i.e. AFTER
the `load('lines')` re-read inside the cost lock (`:252`) — so a second tranche
sees the first tranche's counter. Column: `document_lines.quantity_received`
`decimal(15,4)` (`apps/api/database/migrations/tenant/2025_12_13_081142_add_quantity_received_to_document_lines_table.php:21`);
`free_quantity_received` `decimal(15,4)`
(`apps/api/database/migrations/tenant/2026_07_02_100000_add_purchase_bonus_fields_to_document_lines.php:19-21`).

### 2.3 What closes the PO

```php
// GoodsReceiptService.php:928-942
public function isFullyReceived(Document $purchaseOrder): bool
{
    foreach ($purchaseOrder->lines as $line) {
        …
        if (bccomp($received, $qty, self::QUANTITY_SCALE) < 0 || bccomp($freeReceived, $freeQty, self::QUANTITY_SCALE) < 0) {
            return false;   // :936-938
        }
    }
    return true;
}
```
- Iterates **every** line, including service lines with `product_id === null` and
  non-physical products — which are SKIPPED by the receipt loop (`:503-505`, `:519-521`).
  ⚠️ **A PO that mixes a service line (qty > 0) with goods can therefore never reach
  `Received`**, because the service line's `quantity_received` stays 0 while
  `isFullyReceived()` still demands `received >= qty` for it. Worth probing (§14).
- On full receipt: `status => DocumentStatus::Received` (`:728`; enum value
  `apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:13`), and
  `payload.goods_received_at` is stamped (`:732`).
- On partial receipt `payload.goods_received_at` is set to **`null`** (`:732`), which
  matters for `LandedCostService::canModifyCosts()` (§7.4).

---

## 3. Lots / batches / expiry

### 3.1 How batch data is passed

Request field is **`batches`**, not `quantities[].batch_number`. It is a
line-id-keyed map:

```php
// apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php:31-34
'batches'                      => ['sometimes', 'array'],
'batches.*.batch_number'       => ['required_with:batches.*', 'string', 'max:255'],
'batches.*.expiry_date'        => ['required_with:batches.*', 'date'],
'batches.*.manufacturing_date' => ['nullable', 'date'],
```

Read at `PurchaseOrderController.php:786-787`, forwarded as the 3rd argument of
`createDraft` / `receiveGoods` (`:816`, `:834`). Stored in
`goods_receipts.payload['batch_data']` (`GoodsReceiptService.php:136-140`) and
re-read at post by `draftInput()` (`:377-378`).

**Shape:** `{"batches": {"<po_line_uuid>": {"batch_number": "L1", "expiry_date": "2027-01-31", "manufacturing_date": "2026-01-31"}}}`
— **exactly ONE batch per PO line.** There is no way to split one received line
across two lots in a single receipt.

Validation notes:
- `expiry_date` has **no `after:today`** rule → an already-expired lot can be received.
- `batch_number` is free text, max 255, no uniqueness check at the request layer.
- `required_with:batches.*` means an entry present with no `batch_number` is rejected;
  an absent line key is simply "no batch for that line".

### 3.2 Missing lot data for a batch-tracked product

`GoodsReceiptService.php:523-525` throws `Batch data is required for batch-tracked
product {uuid}` — but **only inside `post()`**. `createDraft()` has no such check,
so `save_as_draft: true` + batch-tracked product + no `batches` ⇒ **201-ish success**,
a persisted draft that can never be posted until… nothing, because there is **no
endpoint to edit a draft receipt** (§5). The only escape is `DELETE /goods-receipts/{id}`.

### 3.3 Batch creation

```php
// GoodsReceiptService.php:556-567
$batch = null;
if (isset($batchData[$line->id]) && ($product->requires_batch_tracking ?? false)) {
    $lineBatch = $batchData[$line->id];
    $batch = $this->batchStockService->findOrCreateBatch(
        companyId: $purchaseOrder->company_id,
        tenantId: $purchaseOrder->tenant_id,
        productId: (string) $product->id,
        batchNumber: $lineBatch['batch_number'],
        expiryDate: $lineBatch['expiry_date'],
        manufacturingDate: $lineBatch['manufacturing_date'] ?? null,
    );
}
```
Two consequences:
1. Batch data supplied for a **non**-batch-tracked product is **silently ignored** —
   the `&& requires_batch_tracking` conjunction drops it. No stock is lot-attributed
   and no error is raised.
2. `variantId` is NOT passed to `findOrCreateBatch()` here (compare
   `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:340-348`,
   which takes `?string $variantId = null`). For a product **with active variants**,
   `findOrCreateBatch` throws `MissingVariantException::forProduct($productId)`
   (`BatchStockService.php:349-355`) because `$variantId === null` and active variants
   exist. **A batch-tracked variant product cannot be received through this path.**
   *(Verified by reading both call site and callee; not executed.)*

`findOrCreateBatch` reuses an existing lot when `(company, product, batch_number, variant)`
matches (`BatchStockService.php:359-368`) — so **receiving the same batch number twice
tops up the SAME lot** rather than creating a duplicate. Note it does **not** update the
existing lot's `expiry_date`: a second receipt of `L1` with a different expiry keeps the
first expiry silently.

Stock is credited to the lot with:
```php
// GoodsReceiptService.php:653-661 (paid) and :604-612 (free)
$this->batchStockService->receiveBatchStock(
    tenantId: …, batchId: (int) $batch->id, locationId: (string) $location->id,
    quantity: $qtyToReceive, movementId: $movement->id,
);
```
`receiveBatchStock` (`BatchStockService.php:397-413`) records a `BatchMovement` and
updates/creates `BatchStock`; it explicitly does **not** touch aggregate `stock_levels`
(`BatchStockService.php:392-393`) — that is the WAC service's job. So the aggregate and
the lot ledger are written by two different services in the same transaction.

### 3.4 Default / phantom `DEFAULT` batch (the "W2-7" defect)

- The magic lot number is `BatchStockService::DEFAULT_BATCH_NUMBER = 'DEFAULT'`
  (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:31`;
  mirrored at `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:55`
  and `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:52`).
- **`GoodsReceiptService` never mints a `DEFAULT` lot.** It either uses the operator's
  batch or refuses (`:523-525`). The phantom minting lived in the *return/restore* arm:
  `FEFOInventoryService.php:600-613` documents that every customer return used to mint a
  fresh `DEFAULT` lot ranked `today + 365` because `outstandingShippedLots()` filtered on
  `SUM(all legs) < 0`, and a lot that **arrived through a goods receipt carries a POSITIVE
  leg for its whole received quantity** (`FEFOInventoryService.php:602-604` names
  `GoodsReceiptService → BatchStockService::receiveBatchStock() → recordBatchMovement()`
  as that positive leg). Fixed to "outstanding OUTBOUND" (`FEFOInventoryService.php:615-621`).
- The safe top-up helper is
  `BatchStockService::ensureDefaultBatchForUntrackedRemainder()` (`:255-303`) — it only
  tops the DEFAULT lot **up**, never shrinks it (`:246-251`), and the shrink is a manual
  operator command `inventory:repair-phantom-default-batches` (`:250`;
  `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:18-19`).
- The parapharmacy seeder calls that helper to mint one DEFAULT lot per batch-tracked
  product that holds seeded stock
  (`apps/api/database/seeders/ParapharmacySeeder.php:428-432`, `:1336-1360`) — the comment
  at `:1349-1355` records that re-running it used to be a live phantom-minting path.
- **FEFO implication for the campaign:** a seeded parapharmacy product already owns a
  `DEFAULT` lot with expiry `today + default_shelf_life_days`
  (`ParapharmacySeeder.php:1343-1347`). Receiving a real dated lot **adds a second lot**;
  which one FEFO picks depends on the two expiry dates. A received lot with an expiry
  **later** than the DEFAULT lot's will not be consumed first.

### 3.5 Batch/expiry-tracked by default? (parapharmacy)

- Column default is FALSE:
  `apps/api/database/migrations/tenant/2026_01_05_150003_add_batch_tracking_to_products_table.php:14`.
- But `Product::booted()` overrides it from the **vertical**:
  `apps/api/app/Modules/Product/Domain/Product.php:186-212` — if the attribute was not
  supplied explicitly (`:189-191`) and the product `is_physical` (`:201-205`), it reads
  `config("verticals.{$vertical}.product_defaults.requires_batch_tracking")` (`:207-210`).
- `apps/api/config/verticals.php:335-343`: `'parapharmacy' => [… 'product_defaults' => ['requires_batch_tracking' => true]]`.
  Same for `pharmacy` (`:48-50`). `mechanic` / `restaurant` / `coffee_shop` are `false`
  (`:19`, `:80`, `:110`).
- So **on a parapharmacy tenant every physical product created without an explicit flag
  is batch-tracked** ⇒ the §1.7 bug hits essentially every PO.

---

## 4. Receiving location

Resolution order (`GoodsReceiptService::resolveDestinationLocation`, `:984-1008`):

```php
if ($destinationLocationId !== null) {
    $location = Location::query()
        ->where('company_id', $purchaseOrder->company_id)          // :991
        ->whereExists(… companies.tenant_id = $po->tenant_id …)    // :992-997
        ->where('is_active', true)                                  // :998
        ->find($destinationLocationId);                             // :999
    if ($location === null) {
        throw new \DomainException('Receiving destination is not available for this company.');   // :1001
    }
    return $location;
}
return $purchaseOrder->location ?? $this->getDefaultLocation($purchaseOrder);   // :1007
```

`getDefaultLocation()` (`:966-982`): first `locations.is_default = true`, else the first
location of the company, else `throw new \RuntimeException('No location configured for
company. Please set up at least one location.')` (`:978`) → the controller maps
`\RuntimeException` to HTTP **500** `CONFIGURATION_ERROR`
(`PurchaseOrderController.php:859-865`).

Sources of `location_id`, in precedence order at post time
(`GoodsReceiptService.php:222`): explicit `post()` argument → the draft's stored
`location_id` → PO header `location` → company default.

Per-request checks (two layers):
1. HTTP layer, **membership** check only:
   `PurchaseOrderController.php:800-806` calls
   `locationContext->validateLocationAccess($destinationLocationId, $this->companyContext->requireCompanyId(), $user)`
   and returns HTTP **403** `LOCATION_FORBIDDEN` on `\RuntimeException`. The same check
   exists on the draft-post route: `GoodsReceiptController.php:111-121`.
   `LocationContext::canAccessLocation` (`apps/api/app/Modules/Company/Services/LocationContext.php:224-239`)
   returns **true for any location id** when the membership's `allowed_location_ids` is
   `null` (`:233-235`) — it does NOT check the location actually belongs to the company.
2. Service layer, **ownership** check: `resolveDestinationLocation` above → a foreign
   company's location id survives layer 1 and is rejected at layer 2 with HTTP **422**
   `GOODS_RECEIPT_FAILED` / `Receiving destination is not available for this company.`

**Can you receive to a second location?** Yes — pass `location_id` in the receive body
(`ReceiveGoodsRequest.php:39`, `'location_id' => ['nullable','uuid']`). Note the side
effect at `GoodsReceiptService.php:677-678`: the PO **line**'s `location_id` is rewritten
to the latest receipt destination, so the unreceived remainder follows the last tranche.
Receiving tranche 1 to Location A and tranche 2 to Location B leaves the line pointing at B.

Receipt-level `location_id` column added by
`apps/api/database/migrations/tenant/2026_07_16_120000_add_location_id_to_goods_receipts.php`.

---

## 5. Draft receipt, then post

- **Not a `documents` row.** `goods_receipts` table
  (`apps/api/database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php:14-32`),
  model `apps/api/app/Modules/Inventory/Domain/GoodsReceipt.php:41-76`.
- Status enum has exactly two cases:
  `apps/api/app/Modules/Inventory/Domain/Enums/GoodsReceiptStatus.php:9-10` — `draft`, `posted`.
  **There is no `cancelled` status.**
- Draft creation: `POST /purchase-orders/{id}/receive` with `save_as_draft: true`
  (`ReceiveGoodsRequest.php:38`; branch at `PurchaseOrderController.php:812-828`).
- `receipt_number` is NULL while draft and minted at post
  (`GoodsReceiptService.php:130`, `:254-260`); the unique index is partial
  `WHERE receipt_number IS NOT NULL`
  (`apps/api/database/migrations/tenant/2026_07_06_110000_goods_receipt_draft_columns.php:25-29`).
  Cost columns are nullable on a draft (`:19-23`).
- **What `post` does**: everything in §1.3/§1.4 — stock movements, WAC blend, lot credits,
  PO counters, PO status, GRN number, GR-IR journal entries.
- **Can a draft be edited? NO.** The only goods-receipt routes are
  (`apps/api/app/Modules/Inventory/Presentation/routes.php:156-177`):
  `GET /goods-receipts`, `GET /goods-receipts/{receipt}`, `GET /goods-receipts/{receipt}/pdf`,
  `POST /goods-receipts/{receipt}/post`, `DELETE /goods-receipts/{receipt}`.
  **No PATCH/PUT.** Correcting a draft = delete + recreate.
- **Can a draft be cancelled? Deleted, yes.**
  `GoodsReceiptController::destroy` (`apps/api/app/Modules/Inventory/Presentation/Controllers/GoodsReceiptController.php:139-158`)
  refuses non-draft with 422 `GOODS_RECEIPT_DELETE_FAILED` / `Only draft goods receipts can be deleted.` (`:143-150`),
  then hard-deletes lines + header in a transaction (`:152-155`) and returns 204.
  ⚠️ It is a **hard delete with no audit row**.
- **A posted receipt cannot be reversed here.** No cancel/reverse endpoint exists on
  `GoodsReceiptController`. (Supplier returns are a separate lane —
  `apps/api/database/migrations/tenant/2026_08_08_160000_create_supplier_goods_return_notes_tables.php`. **UNVERIFIED** in detail, out of scope.)
- PDF: only for posted receipts (`GoodsReceiptController.php:82-89`, 422 `GOODS_RECEIPT_PDF_NOT_POSTED`).
- Draft receipts DO lock the PO lines against editing (`poLineIdsWithReceipts` has no
  status filter, `GoodsReceiptService.php:802-818`; enforced at
  `PurchaseOrderController.php:539-553` → 422 `PO_LINES_LOCKED_BY_RECEIPTS`).

---

## 6. Price override on receipt (`goods-receipt.edit-price`)

Enforced in **three** places:

1. **FormRequest (prohibits the field entirely)**
```php
// apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php:22-36
$user = $this->user();
$canEditPrice = $user !== null && $user->can('goods-receipt.edit-price');
…
'received_unit_prices'   => $canEditPrice ? ['sometimes', 'array'] : ['prohibited'],
'received_unit_prices.*' => ['numeric', 'regex:/^\d+(\.\d{1,3})?$/'],
```
⇒ without the permission the request is rejected with a **422 validation error**
(Laravel `prohibited`), **not** a 403 and **not** a silent ignore.
Note `quantities` becomes `required_with:received_unit_prices` (`:27`).
Note the price regex is **non-negative only** (no `-?`) and max 3 dp.

2. **Service, at post time**
```php
// apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:334-349
private function assertCanApplyDraftPriceOverrides(GoodsReceipt $receipt, string $actorId): void
{
    $hasPriceOverride = $receipt->lines->contains(fn (GoodsReceiptLine $line): bool => $line->received_unit_price !== null);
    if (! $hasPriceOverride) { return; }
    $actor = $actorId !== '' ? User::find($actorId) : null;
    if ($actor === null || ! $actor->can('goods-receipt.edit-price')) {
        throw new \DomainException('User is not allowed to apply goods receipt price overrides.');
    }
}
```
Called from `post()` (`:219`) ⇒ a draft created by a privileged user cannot be posted by
an unprivileged one. Surfaces as HTTP **422** `GOODS_RECEIPT_POST_FAILED`
(`GoodsReceiptController.php:125-131`).

3. **Value guard** (both in `createDraft` `:160-166` and `processReceiptLines` `:532-541`):
   `received_unit_price must be greater than zero for line {id}.` ⇒ **an override to 0 is refused.**

### What the override changes

```php
// GoodsReceiptService.php:542-547
$baseUnitCost = $hasReceivedPriceOverride
    ? CurrencyScale::bcround((string) $receivedUnitPrice, self::COST_SCALE)
    : ($hasBatchFreightPool
        ? CurrencyScale::bcround((string) $line->unit_price, self::COST_SCALE)
        : $oldBasis);                                  // $oldBasis = landed_unit_cost ?? unit_price (:529-530)
$landedUnitCost = $this->landedUnitCostForReceipt($qtyToReceive, $baseUnitCost, $batchFreightShare);   // :547
```
- It replaces the **inventory cost basis for this receipt only**. It is **NOT** written
  back to `document_lines.unit_price` — nothing in `processReceiptLines` writes
  `$line->unit_price`. The PO stays at its ordered price; only the stock valuation moves.
- Audit trail persisted on the receipt line (`:704-707`): `price_override_by`,
  `price_override_at`, `price_override_old_basis` (= `$oldBasis`, `:530`),
  `price_override_reason`.
- It also becomes the **408 GR-IR accrual amount** (`accrual_unit_cost`, `:666-668`, `:695`)
  and therefore the GL amount (§8).
- It changes the freight allocation basis: `ReceiptBatchCostAllocator::allocate` uses
  `$receivedUnitPrices[$lineId] ?? $line->unit_price` as the per-line value weight
  (`apps/api/app/Modules/Inventory/Application/Services/ReceiptBatchCostAllocator.php:54-56`).

**Permission grants**: `goods-receipt.edit-price` is declared at
`apps/api/database/seeders/RolesAndPermissionsSeeder.php:148` and granted to
`admin` (all, `:559`) and `manager` (`:571`). Not granted to `cashier` (`:667-700`).

---

## 7. Costing

### 7.1 WAC update on receipt

Service: `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`,
method `recordPurchase()` (`:149-358`). Called twice per line — free leg with
`landedUnitCost: '0'` (`GoodsReceiptService.php:574`) and paid leg with the computed
landed cost (`:623`).

Formula (`WeightedAverageCostService.php:236-253`):
```
currentValue   = companyQty × product.cost_price                  // :236
newCompanyQty  = companyQty + qty                                 // :241 (scale 4)
newValue       = currentValue + qty × landedUnitCost              // :242
newAvgCost     = newValue / newCompanyQty  (rounded to COST_SCALE) // :251-253
```
Key properties:
- The blend denominator is **company-wide owned quantity**, not the receiving location's:
  `companyOwnedQuantity()` (`:100-127`) sums every `stock_level` row for the product
  (all variants, all locations) plus in-transit, with `FOR UPDATE` row locks
  (`:195-213` explains why).
- **WAC is product-grain**, variants share one cost (`:195-198`).
- Scales: `COST_SCALE = 6` at rest (`:47`), quantities scale 4,
  `workingScale() = max(currencyScale + 4, COST_SCALE + 1)` (`:77-80`) — 7 for TND.
  Costs are deliberately NOT truncated to the currency scale at each write (`:37-46`).
- Writes: `stock_movements` row `MovementType::Receipt` with
  `quantity_before/after`, `unit_cost`, `total_cost`, `avg_cost_before/after`,
  `reference = $po->document_number`, `reference_type = 'Document'`,
  `reference_id = $po->id` (`:263-284`, args from `GoodsReceiptService.php:619-627`);
  `stock_levels.quantity` incremented (`:287-288`); `products.cost_price = newAvgCost`,
  `products.last_purchase_cost` (only when landed cost > 0, `:292-294`), `cost_updated_at` (`:295-296`).
- **Side effect: it auto-updates the sale price.** `marginService->updateSalePrice($product)`
  (`:300`) and emits `ProductCostPriceUpdated` after commit when it changed (`:316-337`).
  A receipt can therefore silently change retail prices.
- Lock order: advisory (`ProductCostLock`) → `stock_levels` → `products` (`:160-223`),
  `attempts: 3` on the outer transaction (`:357`).
- **Free units at cost 0 drag the WAC down**: the free leg blends `qty × 0` into the
  numerator while adding to the denominator.

Per-receipt landed cost math in `GoodsReceiptService`:
```php
// :859-869
landedUnitCostForReceipt(qty, baseUnitCost, batchFreightShare)
  = round((qty × baseUnitCost + batchFreightShare) / qty, COST_SCALE=6)
// :839-851
effectiveUnitCost(receivedQty, freeQty, landedUnitCost)
  = round(receivedQty × landedUnitCost / (receivedQty + freeQty), 6)   // stored on the receipt line only
```
`effective_unit_cost` is **reporting only** — the WAC blend uses `landedUnitCost` for the
paid units and `0` for the free ones, which is arithmetically the same average but a
different pair of movements.

### 7.2 `LandedCostService`

File `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php`.

- `allocateCostsAndTaxes()` (`:152-238`) is the primary entry, called **once, at PO confirm**:
  `apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:145`.
- `reallocateCosts()` (`:245-277`) is called **at post** when `hasAllocatedCosts()`:
  `GoodsReceiptService.php:227-231`.
- The cost pool: `landedCostAdditionalCostsTotal()` (`:86-96`) sums
  `document_additional_costs.amount` **excluding reversed** (`whereNull('reversed_at')`, `:89`)
  and only rows whose `application_path` is NULL or `LandedCost` (`:90-94`).
- Split is **by value only** — `allocatePositiveShares()` (`:303-316`) weights by
  `line_total` (`lineTotals()`, `:283-293`) via `ProportionalMoneyAllocator`.
  `LandedCostSplitMethod` exists as an enum with `by_value` / `by_quantity`
  (`apps/api/app/Modules/Document/Domain/Enums/LandedCostSplitMethod.php:9-10`)
  but **nothing in `LandedCostService` reads it** — by-quantity is not implemented on
  this path. Allocation is skipped entirely when subtotal ≤ 0 or the pool ≤ 0 (`:311-313`).
- Per-line result: `document_lines.allocated_costs` and `document_lines.landed_unit_cost`
  (`:125-127`, `:222-226`), where
  ```php
  // :345-351
  landed_unit_cost = round((line_total + allocated_cost + non_recoverable_tax) / quantity, COST_SCALE=6)
  ```
  falling back to `unit_price` when quantity ≤ 0 (`:341-343`).

**The stale comment at `:191`:**
```php
// LandedCostService.php:190-191
// Calculate line-specific non-recoverable tax (VAT based on line's tax_rate).
// This tax is already in line_total, but we track it separately for inventory costing.
```
This is **factually wrong for a purchase order line.** `line_total` is computed by
`DocumentLine::computeLineTotal()` (`apps/api/app/Modules/Document/Domain/DocumentLine.php:280-302`)
as `quantity × unit_price` minus discount — **no tax term** — and that is the value the PO
controller persists (`PurchaseOrderController.php:110-116`, `:122`). The arithmetic at
`:345-349` *adds* `nonRecoverableTax` on top of `line_total`, which is the correct landed
cost for a non-recoverable tax; the comment claims the opposite. Risk: anybody "fixing the
double count" the comment describes would silently drop non-recoverable VAT out of
inventory cost. Flag it, do not act on it.

### 7.3 `DocumentAdditionalCostController`

File `apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php`.

| endpoint | method | route line | permission |
|---|---|---|---|
| `GET /documents/{document}/additional-costs` | `index` `:32-40` | `apps/api/app/Modules/Document/Presentation/routes.php:369-371` | `can:documents.view` |
| `POST /documents/{document}/additional-costs` | `store` `:42-75` | `:373-375` | `can:purchase-orders.update` |
| `PATCH /documents/{document}/additional-costs/{cost}` | `update` `:77-104` | `:377-379` | `can:purchase-orders.update` |
| `DELETE /documents/{document}/additional-costs/{cost}` | `destroy` `:106-114` | `:381-383` | `can:purchase-orders.update` |
| `GET /documents/{document}/landed-cost-breakdown` | `landedCostBreakdown` `:116-164` | `:385-387` | `can:documents.view` |

`store` validation (`:46-61`): `cost_type` ∈ `transport,shipping,insurance,customs,handling,other`;
`amount` `required|numeric|min:0|regex:/^\d+(\.\d{1,3})?$/`; optional same-tenant
`expense_document_id`. **No `split_method` field** — consistent with by-value-only allocation.

⚠️ **Adding a cost does NOT re-allocate.** `store`/`update`/`destroy` never touch
`LandedCostService`; they only insert/update the row (`:63-70`). The re-allocation happens
lazily at `GoodsReceiptService::post()` (`:227-231`). So between "add freight" and
"post a receipt" the PO lines carry stale `landed_unit_cost`.

⚠️ **The breakdown preview uses float math and a different pool.**
`landedCostBreakdown` (`:121-140`) casts to `float`, `round(..., 2)`, and sums
`additionalCosts()->sum('amount')` with **no `reversed_at` / `application_path` filter** —
unlike `LandedCostService::landedCostAdditionalCostsTotal()` (`:88-95`). And it rounds
landed unit cost to **2 dp** (`:138`) while the persisted value is 6 dp
(`LandedCostService.php:351`). The preview can legitimately disagree with what is booked.

### 7.4 Partial receipt + later landed cost

- `LandedCostService::canModifyCosts()` (`:452-458`) returns
  `! isset($payload['goods_received_at'])`. After a PARTIAL receipt that key is written as
  **`null`** (`GoodsReceiptService.php:732`), and `isset(null)` is `false` ⇒ **costs remain
  modifiable after a partial receipt.** (Nothing in the additional-cost controller actually
  calls `canModifyCosts()` — grep shows the only definition; **UNVERIFIED** whether any
  other caller enforces it.)
- Adding freight after tranche 1 and posting tranche 2 triggers `reallocateCosts()`
  (`GoodsReceiptService.php:227-231`), which rewrites `landed_unit_cost` for the **whole
  ordered quantity**. Tranche 2 therefore lands at the new blended cost while tranche 1's
  stock is already valued at the old one — the freight for the units already received is
  **never** capitalised into inventory.
- `ReceiptBatchCostAllocator` (`:28-75`) exists to cap that: per line it takes
  `allocated_costs × (receivedQty / orderedQty)` (`:48-52`) into a pool, then re-splits the
  pool across received value (`:67`). But it only runs when `allocated_costs > 0` at the
  moment of the post.
- The immutability tripwire is `accrual_unit_cost`, set **only on the first paid receipt**
  for a line (`GoodsReceiptService.php:666-668`) with the comment at `:663-665`:
  `SupplierInvoicePostingService::post()` asserts against it to detect post-receipt
  landed-cost reallocations that would leave a 408 residue.

### 7.5 `CheckCogsCoverageCommand`

`apps/api/app/Modules/Accounting/Presentation/Console/CheckCogsCoverageCommand.php`,
signature `accounting:check-cogs-coverage` (`:70`), description
"Run the seven DPA inventory/COGS coverage checks (D-a through D-g)." (`:73`).

It is a **detector, never a repairer** (`:30-37`): nightly, per tenant, per active company,
it reports documents that fell between the goods lane and the money lane, logs structured
context and **exits non-zero** so the scheduler's `onFailure()` fires.
Checks (`:38-62`): D-a costed COGS movements with no movement-keyed GL entry;
D-b above-watermark COGS movements with null/zero cost; D-c invoiced-before-delivery;
D-d delivered-not-invoiced; D-e non-COGS `requiresGLEntry()` movements with no GL entry;
D-f **goods lines that moved no stock** — explicitly including "posted goods receipt
carrying a physical product line for which NO `stock_movements` row exists" (`:50-55`),
queried at `:431-447` joining `goods_receipt_lines` to `goods_receipts` filtered to
`GoodsReceiptStatus::Posted` (`:447`), reported under `'arm' => 'goods_receipt'` (`:476`);
D-g return-note lines that fell back to current cost.

This is the command that would catch a silently-skipped receipt line (`GoodsReceiptService.php:503-505`, `:519-521`).

---

## 8. GL side of the receipt — **YES, a goods receipt POSTS a journal entry**

It is **not** invoice-only. Every paid AND free leg raises
`App\Modules\Inventory\Domain\Events\GoodsReceived`, buffered at
`GoodsReceiptService.php:582-601` (free) and `:631-650` (paid), flushed after the last row
lock by `flushPendingGlPostings()` (`:400-417`, called at `:755`).

Listener wiring: `apps/api/app/Providers/EventServiceProvider.php:145-148`
```php
// Procurement-to-Pay: GR-IR accrual on goods receipt
GoodsReceived::class => [
    PostGrIrOnGoodsReceipt::class,
],
```

Listener `apps/api/app/Modules/Accounting/Listeners/PostGrIrOnGoodsReceipt.php:31-54`:
calls `createGoodsReceiptGrIrEntry(...)` and **swallows every `\Throwable`**, logging
`PostGrIrOnGoodsReceipt: failed to post GR-IR entry` (`:41-52`) — "failure to post GL must
not block the goods receipt". So on the PO-receive path **stock can land with no 408**.
`GoodsReceiptController::post`'s docblock says exactly that (`:96-99`): "GR-IR accrual on
this path is fire-and-forget (swallowing listener); a GL failure leaves stock without 408 —
detectable via `procurement:grir-drift`."

Entry shape — `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2044-2134`:
- Idempotent on `(source_type='goods_receipt', source_id=<movement_id>)`, checked twice
  (`:2052-2054` and again inside the transaction `:2086-2088`).
- `amount = bcround(unitCost × receivedQty, scale)` at the currency scale (`:2064-2068`);
  **returns null (no entry) when amount ≤ 0** (`:2070-2072`) ⇒ **free/bonus units post NO
  journal entry** (their unit cost is `'0'`).
- Debit `SystemAccountPurpose::Inventory`, credit `SystemAccountPurpose::GoodsReceivedNotInvoiced`
  (408) (`:2074-2075`, `:2106-2125`). `partner_id` is null on both legs. Description
  `'Goods Receipt GR-IR accrual'` (`:2098`). Source key is the **stock movement id**, not
  the receipt id (`:2102`).
- **No VAT leg** — TVA is deductible only at invoice receipt (`PostGrIrOnGoodsReceipt.php:19`).
- `Account::findByPurposeOrFail` (`:2074-2075`) throws when the chart of accounts lacks
  Inventory or 408 → swallowed by the listener → silent no-GL.

`failClosedGrir` (the 4th `post()` arg, default `false`) makes the call direct and
non-swallowing (`GoodsReceiptService.php:405-415`). The only caller passing `true` is
`apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php:134` (positional).
**The PO-receive path never passes it** (`PurchaseOrderController.php:831-843`,
`GoodsReceiptController.php:124`) ⇒ PO receipts are always fire-and-forget on GL.

---

## 9. Free quantities / bonus units

**The feature exists**, as PO-line `free_quantity` / `free_quantity_received`
(`apps/api/database/migrations/tenant/2026_07_02_100000_add_purchase_bonus_fields_to_document_lines.php:19-21`).

Gate at the receive endpoint:
```php
// apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:808-810
if (is_array($freeQuantities) && count($freeQuantities) > 0 && ! $this->purchaseBonusGate->enabledFor($this->companyContext->requireCompany())) {
    return $this->validationErrorResponse('GOODS_RECEIPT_FAILED', 'free_quantities is not enabled for this company.');
}
```
`PurchaseBonusGate::enabledFor` (`apps/api/app/Modules/Procurement/Application/PurchaseBonusGate.php:17-37`):
requires the tenant to have module `PurchaseBonus` (`:25-27`) AND the company's
`country_code` to appear in `config('procurement.bonus_quantity_countries')`, default
`['TN']` (`:30-36`; `apps/api/config/procurement.php:17`).
Same gate on create/update requests (`CreateDocumentRequest.php:63`,
`UpdateDocumentRequest.php:60`) and supplier invoices (`CreateSupplierInvoiceRequest.php:71`).

**For a Tunisian parapharmacy tenant it is ON**: `PurchaseBonus` is a default module for
the vertical (`apps/api/config/verticals.php:360`) and TN is the default allowlisted country.

Effect on cost:
- Free units are received at `landedUnitCost: '0'` (`GoodsReceiptService.php:574`) →
  they enter `stock_levels` and the WAC denominator at zero value, **lowering the WAC**.
- `stock_movements` row for the free leg carries `unit_cost = 0`, `total_cost = 0`.
- `goods_receipt_lines.effective_unit_cost` records the true blended cost
  `paidQty × landedUnitCost / (paidQty + freeQty)` (`:839-851`, stored at `:696-700`) —
  a reporting figure, not the WAC input.
- **No GL entry** for the free leg (amount 0 → `GeneralLedgerService.php:2070-2072`).
- Free-only receipt is allowed: the loop's skip condition (`:491-493`) is an AND, and
  `hasReceivedItems` is set for a free-only line (`:716`).
- Free quantities have their own over-receipt ceiling (`:326-331`).

Route-layer: `free_quantities.*` accepts 4 dp and, note, a leading minus
(`ReceiveGoodsRequest.php:30`, regex `/^-?\d+(\.\d{1,4})?$/`).

---

## 10. Idempotency — **there is none on the PO-receive route**

- `ReceiveGoodsRequest` has **no `idempotency_key`** field (whole rules array `:26-40`).
- `PurchaseOrderController::receive` (`:766-867`) performs no dedupe lookup.
- The **only** protection against a duplicate POST is `assertQuantitiesWithinRemaining()`
  (`GoodsReceiptService.php:315`, `:326`). Therefore:
  - Re-POSTing the exact same partial payload while a remainder still exists **DOUBLE-COUNTS
    STOCK** — two `stock_receipts`, two `stock_movements`, two WAC blends, two GR-IR entries
    (their `source_id` is the *movement* id, which differs per attempt, so the GL idempotency
    at `GeneralLedgerService.php:2052` does not help).
  - Re-POSTing after the line is fully received fails with 422
    `Cannot receive more than ordered for line {uuid}.`
  - Re-POSTing the same *batch number* tops up the same lot (`BatchStockService.php:359-368`) —
    so the lot ledger and the aggregate both double, consistently.
- **Contrast:** the standalone-receipt lane DOES have an idempotency key —
  `apps/api/app/Modules/Procurement/Presentation/Requests/CreateStandaloneReceiptRequest.php:24`
  (`'idempotency_key' => ['required','string','max:64']`), claimed against
  `procurement_idempotency_keys` (`apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php:68-90`, `:137-143`).
  That is `POST /goods-receipts/standalone`, a different route
  (`apps/api/app/Modules/Procurement/Presentation/routes.php:40-42`).
- `POST /goods-receipts/{receipt}/post` **is** idempotent-by-refusal: the second call
  hits the non-Draft guard (`GoodsReceiptService.php:212-214`) → 422.

---

## 11. Permissions per route

| route | file:line | `can:` |
|---|---|---|
| `POST /purchase-orders/{po}/receive` | `apps/api/app/Modules/Document/Presentation/routes.php:303-305` | `purchase-orders.receive` |
| `GET /purchase-orders/{po}/receipt-status` | `:307-309` | `purchase-orders.view` |
| `GET /purchase-orders/{po}/receipt-lines` | `:282-285` | `documents.view` |
| `POST /purchase-orders/{po}/confirm` | `:299-301` | `purchase-orders.confirm` |
| `GET /goods-receipts` | `apps/api/app/Modules/Inventory/Presentation/routes.php:156-158` | `inventory.view` |
| `GET /goods-receipts/{receipt}` | `:159-162` | `inventory.view` |
| `GET /goods-receipts/{receipt}/pdf` | `:164-167` | `inventory.view` |
| `POST /goods-receipts/{receipt}/post` | `:169-172` | `purchase-orders.receive` |
| `DELETE /goods-receipts/{receipt}` | `:174-177` | `purchase-orders.receive` |
| `POST /goods-receipts/standalone` | `apps/api/app/Modules/Procurement/Presentation/routes.php:40-42` | `goods-receipt.create-standalone` |
| `POST /documents/{doc}/additional-costs` | `apps/api/app/Modules/Document/Presentation/routes.php:373-375` | `purchase-orders.update` |
| `GET /documents/{doc}/landed-cost-breakdown` | `:385-387` | `documents.view` |

Extra field-level gate: `goods-receipt.edit-price` (§6).

Role grants (`apps/api/database/seeders/RolesAndPermissionsSeeder.php`):
- permission list declares `goods-receipt.edit-price` `:148` and `goods-receipt.create-standalone` `:149`;
- `admin` gets everything (`:559`);
- `manager` gets `purchase-orders.receive`, `goods-receipt.edit-price`,
  `goods-receipt.create-standalone` (`:571`);
- **`cashier` (`:667-700`) has `inventory.view` (`:679`) but NOT `purchase-orders.receive`,
  NOT `purchase-orders.update`, NOT `goods-receipt.edit-price`.**
  ⇒ cashier: **403** on `POST …/receive`, `POST /goods-receipts/{id}/post`,
  `DELETE /goods-receipts/{id}`, `POST …/additional-costs`;
  **200** on `GET /goods-receipts` and `GET /goods-receipts/{id}` (inventory.view);
  **403** on `GET …/receipt-status` (needs `purchase-orders.view`, which cashier lacks).

Module gating: none of the receive routes carry a `module:` middleware. `BatchExpiry` and
`PurchaseBonus` are default modules for parapharmacy (`apps/api/config/verticals.php:355`, `:360`).


---

## 12. Web frontend

### 12.1 Routes and components

| path | guard | component | route def |
|---|---|---|---|
| `/purchases/receipts` | `<RequirePermission moduleKey="purchases">` | `GoodsReceiptListPage` | `apps/web/src/routes/index.tsx:968-976` (lazy import `:63`) |
| `/purchases/receipts/new` | `<RequirePermission permission="goods-receipt.create-standalone">` | `StandaloneReceiptPage` | `apps/web/src/routes/index.tsx:977-986` (lazy import `:64`) |
| `/purchases/orders/:id` | `purchase-orders.view` | `PurchaseOrderDetailPage` | `apps/web/src/routes/index.tsx:948-955` |

All under the `purchases` parent route (`apps/web/src/routes/index.tsx:838`).

Components:
- `apps/web/src/features/purchases/GoodsReceiptListPage.tsx` — 3 tabs (pending / received / drafts), "Receive All" button, drafts list, GRN PDF.
- `apps/web/src/features/purchases/components/ReceiveGoodsDialog.tsx` — **the one and only receive form** (`ReceiveGoodsDialog` `:104`, inner `ReceiveGoodsForm` `:119`).
- `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx` — second entry point, renders the same dialog at `:698-705`.
- `apps/web/src/features/documents/components/DocumentActionBar.tsx:148-151`, `:262-270` — the "Receive Goods" action, shown for `type === 'purchase_order' && status === 'confirmed' && !goods_received`.
- `apps/web/src/features/purchases/StandaloneReceiptPage.tsx` — the receipt-first (no PO) lane.

Only **two** call sites POST `/purchase-orders/{id}/receive`:
`GoodsReceiptListPage.tsx:266` and `PurchaseOrderDetailPage.tsx:173`.

Other endpoints: `GET /purchase-orders/{id}` before opening the dialog (`GoodsReceiptListPage.tsx:362`);
`GET /purchase-orders/{id}/receipt-status` (`PurchaseOrderDetailPage.tsx:137`);
`GET /goods-receipts?status=draft|posted` (`GoodsReceiptListPage.tsx:233`, `:244`, `:255`);
`GET /goods-receipts/{id}/pdf` (`:301`); `POST /goods-receipts/{id}/post` (`:327`);
`DELETE /goods-receipts/{id}` (`:347`); `POST /goods-receipts/standalone` (`StandaloneReceiptPage.tsx:162`).

### 12.2 THE CRUCIAL ANSWER — the web screen ALWAYS sends `quantities`; the receive-all bug is API-only

Payload contract, `apps/web/src/features/purchases/components/ReceiveGoodsDialog.tsx:17-25`:
```ts
export interface ReceiveGoodsRequest {
  quantities: Record<string, string>
  save_as_draft?: boolean
  free_quantities?: Record<string, string>
  received_unit_prices?: Record<string, string>
  price_override_reason?: string
  batches?: Record<string, ReceiveBatchPayload>   // { batch_number, expiry_date }
  location_id?: string
}
```

Builder `buildRequest(saveAsDraft)` — `ReceiveGoodsDialog.tsx:207-250`. Key lines:
```ts
// :220-232
if (hasPaidQuantity) { quantities[line.id] = quantity }
if (hasFreeQuantity) { freeQuantities[line.id] = freeQuantity }
if ((hasPaidQuantity || hasFreeQuantity) && isBatchTracked(line) && state !== undefined) {
  batches[line.id] = { batch_number: state.batchNumber.trim(), expiry_date: state.expiryDate }
}
// :239-249
return {
  quantities,                                         // ← ALWAYS present (unconditional key)
  ...(resolvedDestinationLocationId !== '' ? { location_id: resolvedDestinationLocationId } : {}),
  ...(saveAsDraft ? { save_as_draft: true } : {}),
  ...(Object.keys(freeQuantities).length > 0 ? { free_quantities: freeQuantities } : {}),
  ...(Object.keys(receivedUnitPrices).length > 0 ? { received_unit_prices: receivedUnitPrices } : {}),
  ...(… priceOverrideReason … ),
  ...(Object.keys(batches).length > 0 ? { batches } : {}),
}
```

- **"Receive All" is not a direct POST.** `GoodsReceiptListPage.tsx:827-840` — the button labelled
  `t('inventory:goodsReceipt.receiveAll')` calls `handleReceiveClick(po)` (`:832`), which fetches
  `/purchase-orders/{id}` and opens the modal (`:360-373`). Every submit goes through `buildRequest`.
- Lines are prefilled to their **full remaining** quantity (`initialReceiveState` `:81-94`,
  `remainingQuantity` `:65-67`) — the default submit is "receive all remaining" expressed as an explicit map.
- **The one edge:** a line whose *paid* remaining is 0 but whose *free* remaining is > 0 is still
  receivable (`receivableLines` filter `:130-136` is an OR), so `quantities` can serialize as `{}`
  while `free_quantities` is populated. **This still does NOT reach `receiveAll`**, because the
  server's branch condition is an OR on free quantities:
  ```php
  // apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:829
  } elseif ((is_array($quantities) && count($quantities) > 0) || (is_array($freeQuantities) && count($freeQuantities) > 0)) {
  ```
  ⇒ it takes the `receiveGoods` branch (`:831-840`) with the batches map intact.
- `canSubmit` (`:184-189`) requires `hasPositiveQuantity` (`:154-158`), so an all-zero submit is
  impossible from the UI.

**Conclusion: the receive-all / missing-batch-data defect (§1.7) is reachable only from the raw API
(empty body `{}`), the document-conversion path, or a non-web client — never from the current web UI.**
A Playwright scenario for it must call the API directly.

### 12.3 Batch / expiry inputs

- Rendered only when `isBatchTracked(line)` → `line.requires_batch_tracking === true`
  (`ReceiveGoodsDialog.tsx:77-79`, guard `:404`).
- `batch_number` text input `:406-413` (`aria-label` = `` `${t('purchaseOrders.receive.batchNumber')} ${label}` ``, `:411`).
- `expiry_date` `<input type="date">` `:414-422` (`aria-label` `:420`).
- **No `manufacturing_date` field** in the PO receive dialog (it exists only on the invoice-first
  supplier-invoice form: `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.tsx:521`).
- **Client-side validation blocks the submit**: `hasMissingBatchData` (`:173-182`) requires a non-blank
  trimmed `batchNumber` AND a non-empty `expiryDate` for any batch-tracked line with a positive paid
  or free quantity; it feeds `canSubmit` (`:188`) which disables both footer buttons (`:449`, `:454`)
  and short-circuits `submitRequest` (`:252-255`).
  ⇒ The UI never posts empty batch fields; the API guard is the second line of defence only.
- The UI also client-side blocks over-receipt: `hasInvalidQuantity` (`:160-171`) rejects
  `quantity > remaining` and `freeQuantity > remainingFree`, and negatives.

### 12.4 Price-override UI gating

- `apps/web/src/features/purchases/components/ReceiveGoodsDialog.tsx:126` — `const { hasPermission } = usePermissions()`
- `:128` — `const canEditReceiptPrice = hasPermission('goods-receipt.edit-price')`
- `:378-396` — ternary: editable `<MoneyInput>` at `:381-388` (`aria-label` =
  `` `${t('purchaseOrders.receive.deliveredUnitPrice')} ${label}` ``), else a read-only display
  `:390-396` with helper text `t('purchaseOrders.receive.priceEditReadOnly')` (`:394`).
- `received_unit_prices` is populated only from that input and only for lines with a positive paid
  quantity (`:222-225`).
- `price_override_reason` textarea only renders once a delivered price was typed
  (`hasEditedUnitPrice` `:150-152`, block `:430-440`).
- Variance badge computed `:269-286`, rendered `:398-403`.

### 12.5 Location selector

- `useTransactionLocations()` (`ReceiveGoodsDialog.tsx:127`;
  `apps/web/src/features/locations/hooks/useTransactionLocations.ts:7-17` →
  `GET /company/locations/transaction-destinations`,
  `apps/web/src/features/locations/api/locations.ts:83-93`).
- Rendered as a `<Select>` at `:290-302`, `aria-label={t('purchaseOrders.receive.destination')}` (`:295`),
  only `location.isActive` options (`:298`).
- Default chain (`:141-148`): local state seeded from `purchaseOrder.location_id` → else the PO's
  `location_id` → else `locations.find(isDefault)` → else the first location. It is **not** taken from
  a global current-location context.

### 12.6 Draft UI

- Save-as-draft is a **button**, not a toggle: `ReceiveGoodsDialog.tsx:446-453`,
  `onClick={() => { submitRequest(true) }}` (`:450`), label `t('purchaseOrders.receive.saveDraft')` (`:452`).
- Drafts tab: `GoodsReceiptListPage.tsx:548-567`; data `GET /goods-receipts?status=draft&per_page=100`
  (`:241-250`); count badge from a `per_page=1` query (`:230-239`), rendered `:527-531` and `:562-566`;
  list body `:576-671`; empty state `:578-583`.
- Post draft: button `:645-654` → `handlePostDraftReceipt` `:437-441` → `window.confirm(t('inventory:goodsReceipt.confirm.postDraft'))` → mutation `:325-343` → `POST /goods-receipts/{id}/post` (`:327`).
- Delete draft: button `:655-665` → `handleDeleteDraftReceipt` `:431-435` → `window.confirm(t('inventory:goodsReceipt.confirm.deleteDraft'))` → mutation `:345-358` → `DELETE /goods-receipts/{id}` (`:347`).
- Print GRN (posted only): button `:810-824` → `GET /goods-receipts/{id}/pdf` (`:301`).

### 12.7 data-testids — there are essentially NONE on these screens

Exhaustive result of grepping `data-testid` across `apps/web/src/features/purchases/`,
`apps/web/src/features/documents/`, `apps/web/src/components/organisms/LandedCostBreakdown/`,
`apps/web/src/components/organisms/AdditionalCostsForm/`:

| testid | file:line |
|---|---|
| `invoice-receipts` | `apps/web/src/features/purchases/GoodsReceiptListPage.tsx:480` |

**Zero** testids in: `ReceiveGoodsDialog.tsx`, `StandaloneReceiptPage.tsx`,
`PurchaseOrderDetailPage.tsx`, `DocumentActionBar.tsx`,
`PurchaseOrderLandedCostBreakdown.tsx`, `components/costing/LandedCostBreakdown.tsx`,
`components/organisms/LandedCostBreakdown/LandedCostBreakdown.tsx`,
`components/organisms/AdditionalCostsForm/AdditionalCostsForm.tsx`.
`Modal.tsx` has `role="dialog"` (`:141`) and `aria-modal="true"` (`:142`) but no testid.

**Playwright must use roles / accessible names.** Verified handles:

| target | selector | source |
|---|---|---|
| dialog | `getByRole('dialog')` | `Modal.tsx:141` |
| dialog title | text `sales:purchaseOrders.receiveGoodsTitle` | `ReceiveGoodsDialog.tsx:108` |
| destination select | `aria-label` = `purchaseOrders.receive.destination` | `:295` |
| paid qty input | `aria-label` = `"<quantity label> <productName|description>"` | `:356` |
| free qty input | `aria-label` per line | `:369` |
| delivered price | `aria-label` per line | `:387` |
| batch number | `aria-label` per line | `:411` |
| expiry date | `aria-label` per line | `:420` |
| override reason | `aria-label` | `:437` |
| Cancel / Save draft / Save and post | `getByRole('button', {name: t(common:cancel / purchaseOrders.receive.saveDraft / purchaseOrders.receive.saveAndPost)})` | `:444`, `:452`, `:455` |
| Receive All (list) | text `inventory:goodsReceipt.receiveAll` | `GoodsReceiptListPage.tsx:838` |
| tabs | `:508` pending, `:533` received, `:548` drafts | |
| draft Post / Delete | `:645` / `:655` | |
| Print GRN | `aria-label` = `` `${t('inventory:goodsReceipt.printGrn')} ${receiptNumber}` `` | `:819` |

Adjacent testids that DO exist, on the supplier-invoice / invoice-first receiving screens
(`apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.tsx`):
`manual-line-batch-number-{i}` `:509`, `manual-line-batch-expiry-{i}` `:515`,
`manual-line-batch-manufacturing-{i}` `:521`, `manual-line-quantity-{i}` `:538`,
`manual-line-unit-price-{i}` `:552`, `invoice-first-delivered` `:850`,
`invoice-first-location-id` `:883`, `source-purchase-order` `:736`.

### 12.8 Landed cost / additional costs UI

- `apps/web/src/features/documents/components/PurchaseOrderLandedCostBreakdown.tsx` — container.
  `useLandedCostBreakdown(documentId)` (`:26`) → `GET /documents/{id}/landed-cost-breakdown`
  (`apps/web/src/features/documents/hooks/useAdditionalCosts.ts:169-181`).
  ⚠️ **Returns `null` on error (`:41-43`) and `null` when there are no allocations or
  `total_additional_costs === 0` (`:46-48`)**, and it is only mounted when
  `purchaseOrder.status === 'received'` (`apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:650-652`).
  A Playwright assertion on this panel will silently see nothing in most states.
- Live presenter: `apps/web/src/components/organisms/LandedCostBreakdown/LandedCostBreakdown.tsx`
  (headers `:78`, `:81`, `:84`, `:88`, `:92`, `:95`; totals `:135`; currency hardcoded `fr-TN` `:37`).
- `apps/web/src/features/documents/components/costing/LandedCostBreakdown.tsx` **appears to be dead code**
  (exported from `apps/web/src/features/documents/components/costing/index.ts:2` but no importer found
  outside its own test). Different props and i18n namespace.
- Add-cost form: `apps/web/src/components/organisms/AdditionalCostsForm/AdditionalCostsForm.tsx` —
  cost types `['shipping','customs','insurance','handling','other']` (`:23`), new-cost select `:161-169`,
  description `:176-182`, amount `type=number step=0.01 min=0` `:188-195`, Add button `:198-207`
  (disabled when amount ≤ 0, `:202`), remove `aria-label={t('inventory:additionalCosts.removeCost')}` `:143`.
  **No split-method selector** (consistent with the by-value-only backend, §7.2).
  Mounted from the PO **edit form** (`apps/web/src/features/documents/DocumentForm.tsx:718`), not the receive screen.
  ⚠️ Its toasts are **hardcoded English**, not i18n: `'Additional cost created'`
  (`apps/web/src/features/documents/hooks/useAdditionalCosts.ts:81`), `'Additional cost updated'` `:110`,
  `'Additional cost deleted'` `:137` — assert on the literal strings.

### 12.9 i18n keys worth asserting

- `inventory:goodsReceipt.draftSaved` (`GoodsReceiptListPage.tsx:273`)
- `inventory:goodsReceipt.successMessageWithReceipt` "Goods received successfully under {{receiptNumber}}" (`:275`)
- `inventory:goodsReceipt.successMessage` (`:276`)
- `inventory:goodsReceipt.messages.draftPosted` (`:330`), `…draftDeleted` (`:350`)
- `inventory:goodsReceipt.confirm.postDraft` / `.deleteDraft` — native `window.confirm` (`:438`, `:432`)
- `sales:documents.messages.goodsReceivedWithReceipt` / `.goodsReceived` (`PurchaseOrderDetailPage.tsx:189-191`)
- ⚠️ **Receive ERRORS are raw server messages, not i18n**:
  `GoodsReceiptListPage.tsx:291-296` reads `error.response.data.error.message ?? data.message ?? error.message`;
  `PurchaseOrderDetailPage.tsx:194-196` uses `getErrorMessage(error)`.
  ⇒ a Playwright assertion on the §1.7 bug would literally match
  `Batch data is required for batch-tracked product <uuid>` on screen.

---

## 13. Existing tests (fixtures to mirror)

### 13.1 The shared backend fixture spine

Five of the six goods-receipt Feature tests share a hand-rolled `setUp` — no factories for
tenant/company/location/PO, everything is `Model::create`. Canonical:
`apps/api/tests/Feature/Inventory/GoodsReceiptTest.php`.

| step | line | note |
|---|---|---|
| `use RefreshDatabase;` | `:43` | base `Tests\TestCase` |
| `Tenant::create([… status: TenantStatus::Active, plan: SubscriptionPlan::Professional, vertical: Vertical::Retail])` | `:59-65` | **`vertical` is the batch-tracking switch** |
| `Company::create([… country_code:'TN', currency:'TND', locale:'fr_TN', timezone:'Africa/Tunis'])` | `:67-77` | TND ⇒ currency scale 3 |
| `app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id); $this->seed(RolesAndPermissionsSeeder::class);` | `:79-80` | **team id BEFORE the seeder** |
| `User::create(...)` + `givePermissionTo(['inventory.view','inventory.adjust','inventory.transfer','inventory.receive','purchase-orders.receive'])` | `:82-95` | |
| `UserCompanyMembership::create([user_id, company_id, role:'admin'])` | `:97-101` | |
| `app(CompanyContext::class)->setCompanyId($company->id)` | `:103` | **required** — `WeightedAverageCostService::scale()` calls `getScale()` with no currency |
| `Location::create([… type:'warehouse', is_active:true, is_default:true])` | `:105-112` | `is_default: true` everywhere |
| `Partner::create([… type: PartnerType::Supplier, tax_status: PartnerTaxStatus::REGISTERED])` | `:114-120` | |

Variants:
- `apps/api/tests/Feature/Inventory/GoodsReceiptPriceOverrideTest.php:53-113` — `Vertical::Parapharmacy`;
  the user gets **only** `goods-receipt.edit-price` (`:87`).
- `apps/api/tests/Feature/Inventory/GoodsReceiptDestinationTest.php:55-105` — TWO locations via
  helper `location(string $suffix, bool $default = false)` (`:227-237`); `locationA` default (`:96`),
  `locationB` not (`:97`).
- `apps/api/tests/Feature/Accounting/GoodsReceiptGlTest.php:58-90` — no user, no permission seeder;
  instead `app(ChartOfAccountsService::class)->seedForCompany($company)` (`:79`) and
  `Account::findByPurposeOrFail(company, SystemAccountPurpose::Inventory / …GoodsReceivedNotInvoiced)` (`:82`, `:86`).
- `apps/api/tests/Feature/Accounting/CheckCogsCoverageCommandTest.php:59-68` — trait
  `Tests\Traits\BuildsDeliveryPolicyFixtures` + `CountryDocumentSettingsSeeder`.
- `apps/api/tests/Feature/Document/DocumentAdditionalCostTest.php:46-127` — the odd one:
  `PermissionSeeder`, then it mirrors every `web`-guard permission onto a `sanctum` guard (`:73-79`),
  creates an `Administrator` sanctum role (`:82-86`). Company is **FR / EUR** (`:57-67`).
- `apps/api/tests/Unit/Inventory/WeightedAverageCostServiceTest.php:44-80` — the only one using
  factories (`Tenant::factory()`, `Company::factory()`, `Product::factory()`), `Location::create`
  manually (`:66-74`), service built by hand with `Tests\Traits\WithCurrencyScale::mockCurrencyScale()`.

### 13.2 PO creation & confirmation in backend tests

**No backend test drives `POST /purchase-orders/{id}/confirm`.** They all insert
`Document` + `DocumentLine` directly with `'status' => DocumentStatus::Confirmed`.
Canonical helper `createConfirmedPO` at `apps/api/tests/Feature/Inventory/GoodsReceiptTest.php:145-193`
(takes `DocumentStatus $status = DocumentStatus::Confirmed` so the Draft/Cancelled rejection cases
reuse it — `:726-729`, `:748-751`).
Document fields `:147-162` (`type: DocumentType::PurchaseOrder`, `fiscal_category: FiscalCategory::NonFiscal`,
`fiscal_status: FiscalStatus::Draft`, `document_number: 'PO-…'`, `currency:'TND'`, `location_id`).
Line fields `:171-183` (`quantity`, `quantity_delivered:'0.0000'`, `quantity_received:'0.0000'`,
`unit_price`, `line_total`, `allocated_costs:'0.0000'`).
Richer costing-capable line shape (needs `landed_unit_cost` + `price_entry_mode`):
`apps/api/tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php:623-640`.

### 13.3 Product creation & `requires_batch_tracking`

- Implicit / vertical-derived: `GoodsReceiptTest.php:126-138` omits the key;
  `:493-496` flips the tenant to `Vertical::Pharmacy` and asserts the product becomes batch-tracked;
  `:593-609` a non-physical product stays false.
- Explicit `false` (because the tenant is `Vertical::Parapharmacy`, which would default to true):
  `GoodsReceiptPriceOverrideTest.php:290-303`, `PurchaseBonusGoodsReceiptTest.php:198-211`,
  `GoodsReceiptLedgerWriteTest.php:646-659`.
- Variants: `apps/api/tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php:146-159`
  (`ProductVariant::create`), threaded onto the PO line as `variant_id` (`:193`).

### 13.4 Receive payloads used in tests

Service-level (positional args, see §1.1):
- simplest: `GoodsReceiptTest.php:216` — `$service->receiveGoods($po, $receivedQty)`
- with batch: `GoodsReceiptTest.php:504-514`
- price override: `GoodsReceiptPriceOverrideTest.php:122-130` (`…, [], [], [$line->id => '5.200'], 'Delivery note unit price', $user->id`)
- paid + free + override: `GoodsReceiptPriceOverrideTest.php:179-187`
- free-only: `GoodsReceiptLedgerWriteTest.php:302` — `receiveGoods($po, [], [], [$line->id => '2.0000'], [], null, $user->id)`
- destination (8th arg): `GoodsReceiptDestinationTest.php:123`
- `receiveAll`: `GoodsReceiptTest.php:255`, `GoodsReceiptLedgerWriteTest.php:337`
- draft/post pair: `GoodsReceiptLedgerWriteTest.php:178-186` then `:233`

HTTP-level:
- batch via HTTP: `GoodsReceiptTest.php:544-556`
- free-only: `PurchaseBonusGoodsReceiptTest.php:126-129` — `['quantities' => [$line->id => '0.0000'], 'free_quantities' => [$line->id => '1.0000']]`
- draft: `GoodsReceiptLedgerWriteTest.php:407-410`
- destination on draft-post → 403: `GoodsReceiptDestinationTest.php:206-212`

### 13.5 Assertion highlights (what the suite already pins)

- `GoodsReceiptTest.php` (18 tests): full receipt → `Received` + `payload.fully_received` (`:199`);
  `receiveAll` (`:246`); partial keeps `Confirmed` (`:271`); 3-tranche completion (`:304`);
  over-receipt `/Cannot receive more than ordered/` (`:350`, `:371`);
  **WAC blend 10@10 + 10@20 ⇒ 15** (`:404`, pre-seeds a `StockLevel` at `:410-417`);
  WAC = purchase cost with no prior stock (`:438`); one `StockMovement` per receipt (`:460`);
  batch creation + `BatchStock` (`:491`); HTTP partial + batch (`:531`);
  `Batch data is required` (`:576`); non-physical not batch-tracked (`:593`);
  `getReceiptStatus` percentages 0/40/100 (`:615`, `:634`, `:660`); draft/cancelled PO rejected (`:722`, `:744`);
  non-PO rejected (`:766`); empty/zero quantities `/No items to receive/` (`:798`, `:815`);
  route middleware pinned (`:840`); tenant isolation (`:858`).
- `GoodsReceiptPriceOverrideTest.php`: audit columns + WAC on override (`:116`); no-override baseline (`:153`);
  paid+free blend `effective_unit_cost '4.727273'` vs `product.cost_price '4.727272'` and
  **free movement created BEFORE the paid movement** (`:173`, `:196-206`);
  `PriceEntryMode::Total` (`:210`); USD half-up rounding (`:240`); zero override rejected (`:270`).
- `GoodsReceiptDestinationTest.php`: two tranches to two locations, incl. the stock-matrix
  `incoming` read via `GET /api/v1/inventory/stock-matrix?include=incoming&location_ids[]=…`
  (helper `:215-225`); omitted destination → PO default (`:159`);
  out-of-scope destination → **403** after narrowing `allowed_location_ids` (`:183`, `:206-208`).
- `PurchaseBonusGoodsReceiptTest.php`: free-only leaves `last_purchase_cost` unchanged (`:119`);
  20+1 ⇒ `cost_price '4.761904'` (`:146`); free over-receive ⇒ 422 `GOODS_RECEIPT_FAILED` (`:182`).
- `GoodsReceiptLedgerWriteTest.php` (15 tests): GRN number `/^GRN-\d{4}-0001$/` (`:127`);
  draft writes zero `StockMovement`s (`:170`); post → GR-IR JE keyed on `movement_id` and
  **no JE for `free_movement_id`** (`:209`, `:265-272`); second receipt = `GRN-…-0002` (`:276`);
  free-only line (`:294`); half-up rounding to 6 dp (`:314`); converter path (`:344`);
  over-receipt rolls the whole receipt back — `assertDatabaseCount('goods_receipts', 0)` (`:361`);
  response `meta.goods_receipt.*` (`:379`, `:398`, `:420`);
  **two stale drafts of 7 on a 10-qty line: the second `post()` throws** (`:447`);
  cross-company 404s (`:475`); delete draft 204 / delete posted 422 (`:555`, `:582`).
- `GoodsReceiptServiceVariantTest.php`: variant-scoped `StockLevel` (`:223`), product-level (`:289`),
  mixed (`:348`), and the **product-grain WAC regression** — 5 RED + 7 BLUE then a RED purchase ⇒
  `cost_price 10.8000`, not 11.50 (`:420`).
- `GoodsReceiptGlTest.php`: 2-line JE, no VAT leg, `partner_id` null, hash chain verified (`:121`);
  half-up (`:168`); two partials → 2 JEs (`:214`); full service path (`:259`); idempotency (`:362`).
- `CheckCogsCoverageCommandTest.php:796` — the D-f goods-receipt arm: a posted receipt line with no
  `stock_movements` row ⇒ exit **1** with `arm === 'goods_receipt'`; attach a movement ⇒ exit 0.
  Fixture `goodsReceiptWithLine()` at `:973-1003`.
- `LandedCostBcmathTest.php`: 30-line reconciliation (`:104`); keyed collection (`:199`);
  zero-base last line (`:245`); **MTP-PUR-17: 10×10 + 5×10 with freight 30 ⇒ allocated
  `'20.000000'`/`'10.000000'`, landed `'12.000000'` both, then a real `receiveGoods` gives
  `cost_price 12.000000`** (`:302`, `:349-354`); truncation (`:421`).
- `LandedCostServiceTest.php` (Unit, no DB): `new LandedCostService($this->mockCurrencyScale(), new ProportionalMoneyAllocator)` (`:25`); float-arg helpers (`:28`-`:120`).
- `DocumentAdditionalCostTest.php`: GET (`:129`), POST 201 amount `'75.500'` (`:146`),
  PATCH (`:167`), DELETE 204 (`:189`), breakdown `quantity_decimals === 3` (`:206`),
  cross-document 404 (`:237`), validation (`:267`, `:278`).
- WAC-specific: `apps/api/tests/Unit/Inventory/WeightedAverageCostServiceTest.php` (22 tests, e.g.
  `:89` `calculateNewWAC('1','0.100000','2','0.200000') === '0.166666'` — truncation, `assertSame`);
  `apps/api/tests/Feature/Inventory/WacBcmathTest.php` (`:98` 100 receipts stay `'0.100000'`;
  `:190` 50 receipts at `bcdiv('1','3',10)` stay `'0.333333'` with no downward drift).
- Batch/receipt adjacents: `apps/api/tests/Feature/Inventory/BatchChainE2ETest.php:164`;
  `apps/api/tests/Feature/Inventory/ReceiptBatchAllocationTest.php:114`, `:138`, `:165`;
  `apps/api/tests/Feature/Procurement/ReceiveGoodsRequestTest.php:119-212` (7 request-validation tests:
  4-dp qty ceiling, `prohibited` `received_unit_prices`, 3-dp price ceiling, negatives,
  prices-without-quantities, and that the permission is seeded to admin+manager only).

### 13.6 `apps/web/e2e/purchasing/additional-costs.spec.ts` — fully mocked, no backend

- `import { test, expect } from '../fixtures'` (`:1`). The fixture `authenticatedPage`
  (`apps/web/e2e/fixtures.ts:20-98`) **does not log in**: it `page.route`-stubs
  `**/api/v1/auth/me` (`:24-38`), `**/api/v1/user/companies` (`:41-60`),
  `**/api/v1/locations` (`:63-69`, `mockLocations` at `:169-206` — `location-1` Main Shop default,
  `location-2` Warehouse), `**/api/v1/dashboard/stats` (`:72-88`), then `page.goto('/')` and
  `localStorage.setItem('autoerp-auth', …)` (`:91-94`, `mockAuthState` `:4-17`,
  `token: 'fake-jwt-token'`).
- Spec mocks: `mockPurchaseOrder` (`:4-40`, `po-1`, status `draft`, 2 lines with
  `allocated_costs:'0.00'`, `landed_unit_cost:null`), `mockAdditionalCosts` (`:42-57`, 100 + 50),
  `mockSuppliers` (`:59-62`). Routes stubbed at `:67`, `:77`, `:85`, `:163`, `:240`, `:297`.
- Navigation is always `page.goto('/purchasing/orders/po-1')`.
- Selectors: **no testids**; `getByText(/additional costs/i)` `:96`,
  `getByLabel(/type/i).first().selectOption('insurance')` `:137`,
  `getByPlaceholder(/description/i)` `:140`, `getByPlaceholder(/0.00/i)` `:143`,
  `getByRole('button', {name:/add/i})` `:146`, `{name:/remove/i}` `:186`, `{name:/confirm/i}` `:318`,
  `getByText(/150/)` `:215`, `getByText('75.00')` / `'57.50'` / `'28.75'` `:272-276`.
- ⚠️ Two of the six tests (`:152` remove, `:279` confirm) wrap assertions in
  `if (await …isVisible())` ⇒ **they pass vacuously when the control is absent.**
- Config: `apps/web/playwright.config.ts` — `testDir: './e2e'`, `baseURL: 'http://localhost:5173'`,
  chromium only, `webServer: pnpm dev`.

### 13.7 `apps/web/e2e/money-campaign/*` — the REAL-STACK pattern to mirror

This is the suite to copy for wave-2, not the mocked one.

- Login: `apps/web/e2e/money-campaign/helpers.ts:50-58` `loginAsRole(page, role)` — dismisses cookie
  consent via `addInitScript` on `autoerp-cookie-consent` (`:37-41`), fills
  `getByLabel(/email address/i)` + `getByLabel(/^password$/i)`, clicks `getByRole('button', {name:/sign in/i})`,
  waits for URL ≠ `/login` AND `getByRole('button', {name:/profile/i})` (proof `CompanyProvider` mounted).
- Credentials `helpers.ts:23-28` — `owner@pharmabio.tn` / `password` (+ manager, cashier, accountant, viewer, technician).
- API seeding: `helpers.ts:104-148` `apiRequest(page, method, path, body)` — a same-origin `fetch`
  inside `page.evaluate`, reading the bearer token from `localStorage['autoerp-auth'].state.token`,
  `X-Company-Id` from `localStorage['autoerp-company'].state.currentCompanyId`, and `X-XSRF-TOKEN`
  from the cookie. Base path `/api/v1`.
- Fixed ids `apps/web/e2e/money-campaign/w2b-support.ts:34-42` — `TENANT_DB`, `COMPANY_ID`,
  `WAREHOUSE_LOCATION_ID` (WH-01, non-POS), `SHOP1/SHOP2_LOCATION_ID`, `PIECE_UNIT_ID` (0 dp),
  `KG_UNIT_ID` (3 dp), `VAT19_TAX_CONFIG_ID`, `OWNER_USER_ID`.
- Product creation `apps/web/e2e/money-campaign/w4-support.ts:155-173` —
  `POST /products {…, requires_batch_tracking: opts.requiresBatchTracking ?? false}`;
  the comment at `:150-153` records exactly our §1.7/§3.5 issue: parapharmacy defaults it to true,
  so costing cases must pass `false` or the receive 422s on "Batch data is required".
- PO + receive `w4-support.ts:206-257` (`poAndReceive`):
  `POST /purchase-orders` → optional `POST /documents/{poId}/additional-costs` **before confirm** →
  `POST /purchase-orders/{poId}/confirm` → `GET /purchase-orders/{poId}` for the line id →
  `POST /purchase-orders/{poId}/receive {quantities, free_quantities?, received_unit_prices?, price_override_reason?}`.
  The header comment at `:228-231` and `purchasing-landed-cost.spec.ts:10-15` state the rule:
  **landed costs allocate at confirm and re-allocate at receipt; a cost added after confirm leaves
  `allocated_costs` at 0 until a receipt runs.**
- Evidence reads: `costPrice(page, productId)` = `GET /products/{id}` → `data.cost_price`
  (`w4-support.ts:176-179`); `persistedLandedCosts(poId)` **shells out to `psql`**
  (`w4-support.ts:112-146`) because `GET /documents/{id}/landed-cost-breakdown` returns floats
  (`purchasing-landed-cost.spec.ts:17-21` — matches §7.3 above). Money helpers are BigInt-in-millimes
  (`w4-support.ts:55-107`); no `toBeCloseTo` anywhere.
- Standalone receipt: `w4-support.ts:269-288`.
- Existing scenarios in `apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts`:
  `:52` PUR-14 paid 10@10 + free 2 ⇒ WAC `8.333333`; `:69` PUR-15 bonus gate;
  `:110` PUR-16 zero-value bonus line; `:178` PUR-17 freight 30 over 100/150;
  `:223` PUR-18 3 equal lines pool 10 ⇒ `['3.333000','3.333000','3.334000']`;
  `:254` PUR-19 costs frozen after full receipt; `:294` PUR-20 cost patched before receipt;
  `:336` PUR-21 override without permission ⇒ ≥400 and no cost written;
  `:357` PUR-22 authorized override persists the audit trio and WAC blends.
- `apps/web/e2e/money-campaign/purchasing-receipt-validation.spec.ts`:
  `:43` PUR-23 4-dp price rejected, `:96` PUR-24 5-dp qty rejected, `:109` PUR-25 zero price,
  `:138` PUR-26 negative price, `:161` PUR-27 empty state, `:187` PUR-28 payable balance,
  `:223` PUR-29 standalone receipt seeds WAC with no PO, `:239` PUR-30 standalone permission + idempotency.
- `apps/web/e2e/money-campaign/inventory-costing.spec.ts`: INV-01…INV-09 (`:39`, `:56`, `:70`, `:81`,
  `:122`, `:204`, `:231`, `:255`, `:278`).
- Concurrency helpers: `helpers.ts:189-202` `withTwoSessions`, `:224-231` `raceTwo`.

---

## 14. EDGE CASES WORTH PROBING

Each anchored to a code line. Ordered roughly by expected value.

1. **Receive-all with no body on a batch-tracked PO (the headline bug).**
   `POST /purchase-orders/{id}/receive` body `{}`.
   → `PurchaseOrderController.php:841-843` → `receiveAll` → `GoodsReceiptService.php:795` (`$batchData = []`)
   → throw at `:524`. Expect **422** with a raw product UUID in the message
   (`PurchaseOrderController.php:857-858`). **API-only — the web UI cannot reach it** (§12.2).
   Also probe the conversion path `PurchaseOrderToGoodsReceiptConverter.php:140` (the *with-quantities*
   branch is equally batch-blind).

2. **Draft a receipt for a batch-tracked product with no batch data, then try to post it.**
   `save_as_draft: true` + no `batches` → `createDraft` has no batch check (`:107-201`) ⇒ **succeeds**.
   Then `POST /goods-receipts/{id}/post` → `:524` ⇒ 422 forever, and **there is no PATCH route to
   fix the draft** (`apps/api/app/Modules/Inventory/Presentation/routes.php:156-177`).
   The only exit is `DELETE /goods-receipts/{id}`. A stuck draft also **locks the PO lines against
   editing** (`GoodsReceiptService.php:802-818` has no status filter; enforced at
   `PurchaseOrderController.php:547-552`).

3. **Over-receipt by one unit** — `quantities: {line: "<ordered+1>"}`.
   → `GoodsReceiptService.php:315-319`, 422 `Cannot receive more than ordered for line <line-uuid>.`
   Confirm the whole receipt rolls back (no `goods_receipts` row) — pinned by
   `apps/api/tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php:361`.
   Also probe the FREE ceiling separately (`:326-331`) and the UI's own block (`ReceiveGoodsDialog.tsx:160-171`).

4. **Quantity at 4 vs 5 decimals.** `10.0001` accepted (`ReceiveGoodsRequest.php:28` regex `\d{1,4}`),
   `10.00001` rejected. Then check the 5th-decimal blind spot: with `remaining = 10.0000`,
   `10.00005` truncates to `10.0000` at `bccomp(..., QUANTITY_SCALE=4)` (`GoodsReceiptService.php:315`)
   and passes the ceiling — does `stock_levels.quantity` (`decimal(15,4)`) then round or truncate?
   Also probe a **negative** quantity: the regex allows `-?` (`ReceiveGoodsRequest.php:28`, `:30`)
   but `bccomp(qty,'0',4) <= 0` skips the line (`:149`, `:491`), so an all-negative payload should
   surface `No items to receive` (`:193` / `:720`), NOT a negative stock movement.

5. **Expired lot.** `batches[line] = {batch_number:'EXP1', expiry_date:'2020-01-01'}`.
   `ReceiveGoodsRequest.php:33` has only `date` — **no `after:today`** — and
   `BatchStockService::findOrCreateBatch` (`:340-387`) sets `is_expired => false` unconditionally
   (`:378`). Expect the receipt to succeed with an already-expired lot on hand. Then check what
   FEFO does with it (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
   deliberately excludes expired/recalled lots — see the note at `BatchStockService.php:416-422`)
   ⇒ stock you can see but cannot sell.

6. **Same batch number received twice with a DIFFERENT expiry.**
   `findOrCreateBatch` returns the existing lot on a `(company, product, batch_number, variant)` hit
   (`BatchStockService.php:359-368`) and **never updates `expiry_date`**. Expect the second expiry to
   be silently discarded and both quantities to pile onto the first lot.

7. **Receive after cancel.** Set the PO to `DocumentStatus::Cancelled`
   (`apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:14`) then receive.
   → `assertReceivablePurchaseOrder` (`GoodsReceiptService.php:300-302`) throws
   `Purchase order must be confirmed before receiving goods` ⇒ 422.
   Note the message is the *draft* wording, not "cancelled" — the converter has a dedicated message
   (`PurchaseOrderToGoodsReceiptConverter.php:130-132`) that this path does not use.
   Also probe **receive after `Received`**: the same guard fires (status is `Received`, not `Confirmed`)
   — so a fully-received PO is closed to further receipts by the status guard, not by the qty ceiling.

8. **Receive to a location of another company.**
   Layer 1 (`PurchaseOrderController.php:800-806` / `LocationContext.php:224-239`) passes when the
   membership's `allowed_location_ids` is `null`, because it only checks membership, not ownership.
   Layer 2 (`GoodsReceiptService.php:990-1002`) rejects → **422** `Receiving destination is not
   available for this company.` Contrast with the **403** `LOCATION_FORBIDDEN` you get when
   `allowed_location_ids` is narrowed (pinned by
   `apps/api/tests/Feature/Inventory/GoodsReceiptDestinationTest.php:183`, `:206-212`).
   Also probe an **inactive** location (`is_active` filter at `:998`) ⇒ 422.

9. **Receive the same payload twice (no idempotency).**
   `ReceiveGoodsRequest.php:26-40` has no `idempotency_key`; `PurchaseOrderController::receive`
   (`:766-867`) has no dedupe. On a PO with remaining ≥ 2× the payload, expect
   **double stock, two `stock_movements`, two WAC blends, two GR-IR journal entries**
   (the GL idempotency at `GeneralLedgerService.php:2052` keys on the *movement* id, which differs).
   Contrast: `POST /goods-receipts/{id}/post` twice ⇒ 422 (`GoodsReceiptService.php:212-214`);
   `POST /goods-receipts/standalone` has a real `idempotency_key`
   (`apps/api/app/Modules/Procurement/Presentation/Requests/CreateStandaloneReceiptRequest.php:24`).

10. **Receipt with 0 qty on ALL lines.** `{"quantities": {"<line>": "0"}}`.
    → the `elseif` at `PurchaseOrderController.php:829` is TRUE (`count($quantities) > 0`), so
    `receiveGoods` runs, every line is skipped (`:149-151`), and `createDraft` throws
    `No items to receive. Please specify quantities to receive.` (`:193`) ⇒ 422.
    ⚠️ Contrast with the empty-body case (edge 1) which takes a **different** branch — same-looking
    request, different failure.

11. **Landed cost added AFTER a partial receipt.**
    Receive 5 of 10 → `payload.goods_received_at` is written as **`null`** (`:732`) so
    `LandedCostService::canModifyCosts()` (`:452-458`, `isset(null) === false`) still says yes.
    `POST /documents/{poId}/additional-costs` (freight) → receive the other 5 →
    `post()` calls `reallocateCosts()` (`:227-231`), which rewrites `landed_unit_cost` for the
    **whole ordered qty**. Expect: tranche 2 capitalises freight, tranche 1's stock does not, and
    `ReceiptBatchCostAllocator` (`:48-52`) prorates only the received share.
    Also check `document_lines.accrual_unit_cost` is **still** tranche 1's value (`:666-668`) —
    that is the 408 residue tripwire.

12. **Landed cost added after a FULL receipt.** `goods_received_at` is a real timestamp (`:732`) ⇒
    `canModifyCosts()` false — but **nothing calls it**: `DocumentAdditionalCostController::store`
    (`:42-75`) has no such check. Expect the cost row to be created anyway with no effect on
    inventory (no further `post()` to trigger `reallocateCosts`). Pinned as PUR-19 in
    `apps/web/e2e/money-campaign/purchasing-landed-cost.spec.ts:254`.

13. **Price override to 0 / negative / 4 decimals.**
    `"0"` → `\DomainException('received_unit_price must be greater than zero for line …')`
    (`GoodsReceiptService.php:162-164` and `:537-539`) ⇒ 422.
    Negative → rejected by the regex `/^\d+(\.\d{1,3})?$/` (`ReceiveGoodsRequest.php:36`, no `-?`) ⇒ 422.
    4 dp → same regex ⇒ 422 (pinned by `apps/api/tests/Feature/Procurement/ReceiveGoodsRequestTest.php`).
    Without the permission → `prohibited` (`ReceiveGoodsRequest.php:35`) ⇒ **422, not 403, not a silent ignore.**

14. **Cashier attempts to receive.** `cashier` lacks `purchase-orders.receive`
    (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:667-700`) ⇒ **403** on
    `POST …/receive`, `POST /goods-receipts/{id}/post`, `DELETE /goods-receipts/{id}`.
    But it HAS `inventory.view` (`:679`) ⇒ **200** on `GET /goods-receipts` and `GET /goods-receipts/{id}`.
    And it lacks `purchase-orders.view` ⇒ **403** on `GET …/receipt-status`.

15. **PO mixing a goods line and a service line.**
    Service lines (`product_id === null` at `:503-505`, or `! isPhysical()` at `:519-521`) are skipped
    by the receipt loop, but `isFullyReceived()` (`:930-939`) still demands `quantity_received >= quantity`
    for every line. Expect a PO that can **never** reach `Received` — it stays `Confirmed` forever
    with 100% of the goods received. High-value probe.

16. **Free (bonus) quantity behaviour.**
    - Free-only receipt (`quantities` empty, `free_quantities` populated) → still takes the
      `receiveGoods` branch (`PurchaseOrderController.php:829`), creates ONE movement at
      `unit_cost 0` (`:570-579`), **no journal entry** (`GeneralLedgerService.php:2070-2072`),
      and drags the WAC down.
    - Same request on a non-TN company or a tenant without the `PurchaseBonus` module →
      422 `free_quantities is not enabled for this company.` (`PurchaseOrderController.php:808-810`,
      `PurchaseBonusGate.php:17-37`).
    - Movement ORDER: free before paid — pinned at
      `apps/api/tests/Feature/Inventory/GoodsReceiptPriceOverrideTest.php:196-206`.

17. **Batch-tracked product that has ACTIVE VARIANTS.**
    `GoodsReceiptService.php:559-566` calls `findOrCreateBatch` **without** `variantId`, and
    `BatchStockService.php:349-355` throws `MissingVariantException::forProduct($productId)` when
    `$variantId === null` and active variants exist. Expect a hard failure on any variant-bearing
    batch-tracked product. *(Read from both sides; not executed — verify empirically.)*

18. **Batch data supplied for a NON-batch-tracked product.**
    `GoodsReceiptService.php:557` requires BOTH `isset($batchData[...])` AND `requires_batch_tracking`
    ⇒ the batch is **silently dropped**, no lot, no error, stock lands untracked. Confirm the response
    is a clean 200 with no lot created.

19. **`stock_levels` row missing / no default location.**
    `getDefaultLocation()` throws `\RuntimeException` (`:978`) → **HTTP 500** `CONFIGURATION_ERROR`
    (`PurchaseOrderController.php:859-865`), not a 422. Worth probing on a second company created
    without a location.
    Related detector: `accounting:check-cogs-coverage` D-f arm reports posted receipt lines with no
    `stock_movements` row (`CheckCogsCoverageCommand.php:50-55`, `:431-447`).

20. **GL failure is swallowed.** Break the chart of accounts (remove the 408 / Inventory purpose) →
    `Account::findByPurposeOrFail` throws inside `createGoodsReceiptGrIrEntry`
    (`GeneralLedgerService.php:2074-2075`) → the listener logs and continues
    (`PostGrIrOnGoodsReceipt.php:41-52`). Expect: **stock lands, no journal entry, HTTP 200**.
    Only `accounting:check-cogs-coverage` / `procurement:grir-drift` surface it.

21. **Two tranches to two different locations.**
    Beyond the pinned assertions (`GoodsReceiptDestinationTest.php:107`), probe the side effect at
    `GoodsReceiptService.php:677-678`: the PO **line**'s `location_id` is rewritten to the LAST
    destination, so the remaining 3 units are now "expected at" location B even though the operator
    only redirected one tranche.

22. **A receipt silently changes retail prices.**
    `WeightedAverageCostService.php:300` calls `marginService->updateSalePrice($product)` on every
    `recordPurchase`, emitting `ProductCostPriceUpdated` (`:316-337`). Probe whether receiving at a
    higher price silently moves the shelf price of a parapharmacy SKU.

23. **`landed-cost-breakdown` preview vs what is booked.**
    `DocumentAdditionalCostController::landedCostBreakdown` (`:121-140`) uses float math,
    `round(..., 2)`, and sums `additionalCosts()->sum('amount')` with **no `reversed_at` /
    `application_path` filter** — unlike `LandedCostService::landedCostAdditionalCostsTotal()`
    (`:88-95`). Add a reversed cost, or a non-landed-cost `application_path`, and the preview should
    disagree with the persisted 6-dp `landed_unit_cost`. The money-campaign suite already works
    around this by reading Postgres directly (`apps/web/e2e/money-campaign/w4-support.ts:112-146`).

24. **The landed-cost panel silently disappears.**
    `apps/web/src/features/documents/components/PurchaseOrderLandedCostBreakdown.tsx:41-48` returns
    `null` on error AND when `total_additional_costs === 0`, and it is only mounted when the PO
    status is `received` (`PurchaseOrderDetailPage.tsx:650-652`). Any Playwright assertion on it
    must first assert the PO reached `received`, or it will pass/fail for the wrong reason.

25. **Draft delete leaves no trace.** `GoodsReceiptController::destroy` (`:139-158`) hard-deletes
    lines + header with no audit row and no event. Probe whether the GRN sequence skips a number
    (it does not — the number is minted at post, `GoodsReceiptService.php:254-260`), but do check
    that a deleted draft releases the PO-line edit lock (`poLineIdsWithReceipts`, `:802-818`).
