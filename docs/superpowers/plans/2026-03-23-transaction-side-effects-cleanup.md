# Transaction Side Effects Cleanup

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move all external side effects (event dispatches, job dispatches, file I/O) outside DB::transaction closures using `DB::afterCommit()` to prevent transaction rollbacks caused by non-DB failures.

**Architecture:** The correct pattern already exists in `StockReservationService.php` — wrap side effects in `DB::afterCommit()` inside the transaction closure. This ensures the side effect only fires after the transaction commits successfully. For file I/O (S3), restructure to upload before creating the DB record, or use afterCommit for cleanup.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL 16

**Reference pattern** (from `StockReservationService.php:148-163`):
```php
DB::transaction(function () use (...) {
    // ... DB operations ...

    DB::afterCommit(function () use ($reservation) {
        event(new ReservationCreated(
            reservationId: $reservation->id,
            // ...
        ));
    });

    return $reservation;
});
```

---

## File Map

| File | Action | Side Effects to Fix |
|------|--------|-------------------|
| `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php` | Modify | `event(InvoicePosted)` at L189, `event(InvoiceCancelled)` at L129 |
| `apps/api/app/Modules/POS/Application/Services/OrderManagementService.php` | Modify | 4 dispatches: `OrderSentToKitchen` L329, `OrderClosed` L369, `OrderLineStatusChanged` L456, `OrderReady` L532 |
| `apps/api/app/Modules/Product/Application/Services/ProductImageService.php` | Modify | `Storage::putFileAs` L50-56, `GenerateImageVariants::dispatch` L79, `Storage::delete` L128-135 |
| `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php` | Modify | `event(ReceiptVoided)` at L95 |
| `apps/api/app/Modules/Loyalty/Application/Services/MemberEnrollmentService.php` | Modify | `event(MemberEnrolledV2)` at L81 |
| `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` | Modify | `event(ReceiptCreated)` at L621 |

---

### Task 1: DocumentPostingService — Fiscal Event Dispatches (CRITICAL)

**Files:**
- Modify: `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php`

**Context:** This service handles fiscal document posting (invoices, credit notes) with hash chains. The `post()` method calls `postWithFiscalChain()` which dispatches `InvoicePosted` at line 189 inside the transaction. The `cancel()` method dispatches `InvoiceCancelled` at line 129 inside the transaction. Both events are for audit trail / event sourcing — they must NOT prevent the fiscal operation from completing.

- [ ] **Step 1: Read the full file to understand current structure**

Read `DocumentPostingService.php` in full. Understand:
- The `post()` method (L72-88) calls `postWithFiscalChain()` inside its transaction
- `postWithFiscalChain()` (L142-190) calls `$this->dispatchPostedEvent()` at L189
- The `cancel()` method (L115-136) calls `$this->dispatchCancellationEvent()` at L129
- Both dispatch methods fire Laravel `event()` calls

- [ ] **Step 2: Wrap dispatchPostedEvent in DB::afterCommit inside postWithFiscalChain**

In the `postWithFiscalChain()` method, find where `$this->dispatchPostedEvent(...)` is called (around L189). Wrap it in `DB::afterCommit()`:

```php
DB::afterCommit(function () use ($document, $postedAt) {
    $this->dispatchPostedEvent($document, $postedAt->toIso8601String());
});
```

Note: `DB::afterCommit()` works because this code runs inside the `DB::transaction()` from the `post()` method. The afterCommit callback is registered and fires when the outer transaction commits.

- [ ] **Step 3: Wrap dispatchCancellationEvent in DB::afterCommit inside cancel**

In the `cancel()` method, find where `$this->dispatchCancellationEvent(...)` is called (around L129). Wrap it:

```php
DB::afterCommit(function () use ($document) {
    $this->dispatchCancellationEvent($document);
});
```

- [ ] **Step 4: Run tests**

Run: `cd apps/api && php artisan test --filter=Document`
Expected: All existing tests PASS

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php
git commit -m "fix(documents): move fiscal event dispatches to afterCommit to prevent rollbacks"
```

---

### Task 2: OrderManagementService — Kitchen/Order Event Dispatches (HIGH)

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/OrderManagementService.php`

**Context:** This service manages POS orders (F&B flow). Four methods dispatch events inside transactions: `sendToKitchen()`, `closeOrder()`, `updateLineStatus()`, `bumpOrder()`. These events notify the kitchen display system — they must not prevent order state changes.

- [ ] **Step 1: Read the full file to understand current structure**

Read `OrderManagementService.php`. Identify all 4 event dispatches inside transactions:
- `sendToKitchen()` ~L329: `OrderSentToKitchen::dispatch($order->id)`
- `closeOrder()` ~L369: `OrderClosed::dispatch($order->id, $receipt->id)`
- `updateLineStatus()` ~L456: `OrderLineStatusChanged::dispatch(...)`
- `bumpOrder()` ~L532: `OrderReady::dispatch($order->id)`

- [ ] **Step 2: Fix sendToKitchen — wrap dispatch in afterCommit**

```php
DB::afterCommit(function () use ($order) {
    OrderSentToKitchen::dispatch($order->id);
});
```

- [ ] **Step 3: Fix closeOrder — wrap dispatch in afterCommit**

```php
DB::afterCommit(function () use ($order, $receipt) {
    OrderClosed::dispatch($order->id, $receipt->id);
});
```

- [ ] **Step 4: Fix updateLineStatus — wrap dispatch in afterCommit**

```php
DB::afterCommit(function () use ($order, $lineId, $fromStatus, $newStatus) {
    OrderLineStatusChanged::dispatch($order->id, $lineId, $fromStatus->value, $newStatus->value);
});
```

- [ ] **Step 5: Fix bumpOrder — wrap dispatch in afterCommit**

```php
DB::afterCommit(function () use ($order) {
    OrderReady::dispatch($order->id);
});
```

- [ ] **Step 6: Run tests**

Run: `cd apps/api && php artisan test --filter=Order`
Expected: All existing tests PASS

- [ ] **Step 7: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Services/OrderManagementService.php
git commit -m "fix(orders): move kitchen/order event dispatches to afterCommit"
```

---

### Task 3: ProductImageService — File I/O and Job Dispatch (HIGH)

**Files:**
- Modify: `apps/api/app/Modules/Product/Application/Services/ProductImageService.php`

**Context:** This service handles product image upload/delete. The `upload()` method uploads to S3 AND dispatches a variant generation job inside the transaction. The `delete()` method deletes files from storage inside the transaction. File I/O should not block DB transactions.

- [ ] **Step 1: Read the full file to understand current structure**

Read `ProductImageService.php`. Understand:
- `upload()` (L36-86): S3 upload at L50-56, DB create at L62, variant dispatch at L79-83
- `delete()` (L126-151): Storage delete at L128-135, DB delete after

- [ ] **Step 2: Fix upload() — move S3 upload before transaction, wrap dispatch in afterCommit**

Restructure `upload()`:
1. Upload file to S3 BEFORE the transaction
2. If the transaction fails, clean up the uploaded file
3. Wrap `GenerateImageVariants::dispatch()` in `DB::afterCommit()`

```php
public function upload(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
{
    // Upload to S3 BEFORE transaction
    $filename = ...;
    $path = Storage::disk('s3')->putFileAs(...);

    try {
        $image = DB::transaction(function () use ($product, $path, $filename, $alt) {
            // ... create ProductImage record ...

            DB::afterCommit(function () use ($image) {
                GenerateImageVariants::dispatch(
                    $image->id,
                    $image->storage_path,
                    $image->storage_disk
                );
            });

            return $image;
        });
    } catch (\Throwable $e) {
        // Clean up S3 file if transaction failed
        Storage::disk('s3')->delete($path);
        throw $e;
    }

    return $image;
}
```

- [ ] **Step 3: Fix delete() — move storage deletion to afterCommit**

Wrap all `Storage::disk()->delete()` calls in `DB::afterCommit()`:

```php
DB::afterCommit(function () use ($disk, $storagePath, $variantPaths) {
    $disk->delete($storagePath);
    foreach ($variantPaths as $variantPath) {
        $disk->delete($variantPath);
    }
});
```

This way, files are only deleted after the DB record is successfully deleted.

- [ ] **Step 4: Run tests**

Run: `cd apps/api && php artisan test --filter=ProductImage`
Expected: All existing tests PASS

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Product/Application/Services/ProductImageService.php
git commit -m "fix(images): move S3 operations and job dispatch outside transaction"
```

---

### Task 4: ReceiptVoidService — Void Event Dispatch (HIGH)

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php`

**Context:** The `voidReceipt()` method (L57-105) dispatches `event(new ReceiptVoided(...))` at L95 inside the transaction. The void event is for audit trail — it must not prevent the void operation.

- [ ] **Step 1: Read the file and wrap event in afterCommit**

Change the `event(new ReceiptVoided(...))` call at L95 to:

```php
DB::afterCommit(function () use ($receipt, $voidedBy, $reason) {
    event(new ReceiptVoided(
        receiptId: $receipt->id,
        companyId: $receipt->company_id,
        voidedBy: $voidedBy,
        reason: $reason,
    ));
});
```

Check the exact constructor arguments by reading the file.

- [ ] **Step 2: Run tests**

Run: `cd apps/api && php artisan test --filter=Receipt`
Expected: All existing tests PASS

- [ ] **Step 3: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php
git commit -m "fix(receipts): move void event dispatch to afterCommit"
```

---

### Task 5: MemberEnrollmentService — Enrollment Event Dispatch (MEDIUM)

**Files:**
- Modify: `apps/api/app/Modules/Loyalty/Application/Services/MemberEnrollmentService.php`

**Context:** The `enroll()` method (L61-98) dispatches `event(new MemberEnrolledV2(...))` at L81 inside the transaction.

- [ ] **Step 1: Read the file and wrap event in afterCommit**

Change the `event(new MemberEnrolledV2(...))` call at L81 to use `DB::afterCommit()`. Check the exact arguments by reading the file.

- [ ] **Step 2: Run tests**

Run: `cd apps/api && php artisan test --filter=Loyalty`
Expected: All existing tests PASS

- [ ] **Step 3: Commit**

```bash
git add apps/api/app/Modules/Loyalty/Application/Services/MemberEnrollmentService.php
git commit -m "fix(loyalty): move enrollment event dispatch to afterCommit"
```

---

### Task 6: ReceiptCreationService — Receipt Created Event (MEDIUM)

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`

**Context:** The `createReceipt()` method (L113-634) is a massive 521-line transaction. At L621, it dispatches `event(new ReceiptCreated(...))` inside the transaction.

- [ ] **Step 1: Read the relevant section (around L610-634) and wrap event in afterCommit**

Change the `event(new ReceiptCreated(...))` call near L621 to use `DB::afterCommit()`. Check exact arguments.

- [ ] **Step 2: Run tests**

Run: `cd apps/api && php artisan test --filter=Receipt`
Expected: All existing tests PASS

- [ ] **Step 3: Commit**

```bash
git add apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php
git commit -m "fix(receipts): move receipt created event to afterCommit"
```

---

### Task 7: Final Verification

- [ ] **Step 1: Run full test suite**

Run: `cd apps/api && php artisan test`
Expected: No new failures

- [ ] **Step 2: Grep to confirm no remaining violations**

Run: `grep -rn 'event(new\|::dispatch(' apps/api/app/Modules/ | grep -v afterCommit | grep -v test`
Review any remaining matches to confirm they are either outside transactions or correctly placed.

- [ ] **Step 3: Run PHPStan**

Run: `cd apps/api && ./vendor/bin/phpstan`
Expected: No new errors
