# TICKET — Honor `idempotency_key` on count submission

> Follow-up to the live-counting completion lane (mobile B4). Owner decision 2026-07-29: **DEFERRED to its own post-promotion lane.** It needs a column, and pushing `dev` auto-deploys staging including `tenants:migrate` — it must **not** ride the cash-rounding batch.

## Problem

The server does not honor `idempotency_key` on count submission. Mobile B4 now sends it on **every** submission; `SubmitCountRequest::rules()` has no such rule, so the field is silently dropped by validation and ignored.

Impact is bounded, not corrupting. Quantity is last-write-wins in `InventoryCountingItem::submitCount()`, so a retry cannot corrupt a count. The real exposure is narrower: a **lost response** causes the device to retry, reusing the original `counted_at_device` with a **fresh `device_now`** — the server recomputes skew from the new pair and raises a **false `clock_skew` flag** on a count that was already accepted.

## Required contract

- **Accept additively** — add the rule to `SubmitCountRequest::rules()`; existing clients that omit the key keep working unchanged.
- **Dedup by the key ALONE.** Replay metadata legitimately differs between the first attempt and the retry (`device_now` above), so the dedup predicate must not include it.
- **Return the ORIGINAL success response**, not a 409/422. A retry of an accepted submission is a success from the device's point of view; an error status would surface as a spurious failure on a count that landed.

## Precedent to copy

StockTransfer already implements exactly this shape — follow it rather than inventing a second pattern:

- `StoreStockTransferRequest.php:83` — `'idempotency_key' => ['nullable', 'string', 'max:128']`
- `StockTransferController.php:185` — passes `$request->input('idempotency_key')` through to the service command
- `StockTransferService.php:106-152` — idempotency check FIRST, scoped by `tenant_id` + `company_id` + `idempotency_key`; on hit, returns the **existing** record instead of erroring; on miss, persists the key alongside the new row

## Scope notes

- Requires a **new column** on the count-submission table plus its index — this is the reason for the deferral (staging auto-migrates on push to `dev`).
- No fiscal-payload surface is involved; the FISCAL-PAYLOAD FREEZE does not gate this work, but the lane must still land on its own after the cash-rounding promotion.

## Review

Opus gate (`inventory-costing-reviewer`) at merge. Tests by path only — never the full suite.
