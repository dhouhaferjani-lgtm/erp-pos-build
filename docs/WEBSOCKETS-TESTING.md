# WebSocket Real-Time Updates - E2E Testing Guide

## Overview

This guide walks you through end-to-end testing of the WebSocket real-time product cost updates feature.

**Goal:** Verify that when a purchase order is received and product costs are updated, the ProductDetailPage automatically refreshes with new prices in <1 second.

---

## Prerequisites

### Backend Requirements

✅ Laravel API server running on `http://localhost:8002`
✅ PostgreSQL database running and migrated
✅ Redis server running (for queue/cache)
✅ Test tenant and company set up
✅ Test user with company membership

### Frontend Requirements

✅ React app running on `http://localhost:5173`
✅ User logged in via Sanctum
✅ Current company selected in CompanyProvider

---

## Step 1: Start Reverb Server

### Terminal 1 - Start Reverb

```bash
cd apps/api
php artisan reverb:start
```

**Expected Output:**

```
   INFO  Server running...

  Local: http://0.0.0.0:8080
```

**✅ Success Indicator:** Server shows `Server running...` message

**Troubleshooting:**
- If port 8080 is in use: Change `REVERB_PORT` in `.env` and restart
- If "Connection refused": Check `REVERB_SERVER_HOST=0.0.0.0` in `.env`

---

## Step 2: Verify Broadcasting Configuration

### Check Configuration

```bash
cd apps/api
php artisan config:show broadcasting
```

**Expected Output:**

```
broadcasting.default: "reverb"
broadcasting.connections.reverb.driver: "reverb"
broadcasting.connections.reverb.key: "6vhbhb9lmyhxuixydxkq"
```

**✅ Success Indicator:** `default` should be `"reverb"`, not `"null"` or `"redis"`

### Verify .env Settings

```bash
grep -E "^(BROADCAST_|REVERB_)" apps/api/.env
```

**Expected Output:**

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=176244
REVERB_APP_KEY=6vhbhb9lmyhxuixydxkq
REVERB_APP_SECRET=qdza0mqp72k3weogs4ws
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

---

## Step 3: Verify Backend Tests Pass

```bash
cd apps/api
php artisan test --filter="ProductCostPriceUpdatedBroadcastTest|BroadcastProductEventsListenerTest|ProductChannelAuthorizationTest"
```

**Expected Output:**

```
PASS  Tests\Unit\Product\ProductCostPriceUpdatedBroadcastTest
✓ broadcast channel name includes tenant company product
✓ broadcast event name is correct
✓ broadcast payload structure
✓ broadcast payload excludes reference when null
✓ broadcast payload includes reference when present

PASS  Tests\Feature\Broadcasting\ProductChannelAuthorizationTest
✓ user can subscribe to product channel in their tenant and company
✓ user cannot subscribe to channel in different tenant
✓ user cannot subscribe to channel for company they are not member of
✓ inactive user cannot subscribe to channel

PASS  Tests\Feature\Product\BroadcastProductEventsListenerTest
✓ domain event triggers broadcast event
✓ broadcast event contains domain event data

Tests:  11 passed (29 assertions)
Duration: 2.26s
```

**✅ Success Indicator:** All 11 tests pass

---

## Step 4: Open Frontend and Monitor WebSocket Connection

### Terminal 2 - Frontend Dev Server

```bash
cd apps/web
pnpm dev
```

### Access Application

1. Open browser: `http://localhost:5173`
2. Log in with test user
3. Select a company from the company switcher
4. Open browser DevTools (F12)
5. Go to **Console** tab

### Expected Console Output

When navigating to a ProductDetailPage, you should see:

```
WebSocket connected
Subscribed to channel: private-tenant.abc123.company.def456.product.ghi789
```

**✅ Success Indicator:** WebSocket shows `connected` state

**Troubleshooting:**
- If "Connection refused": Verify Reverb server is running (Step 1)
- If "Unauthorized" (401): Verify user is logged in and has company membership
- If no console output: Check browser DevTools settings allow WebSocket logs

---

## Step 5: Navigate to Product Detail Page

### Steps

1. Go to **Inventory → Products**
2. Search for a test product (e.g., "Brake Pad")
3. Click on the product to open ProductDetailPage
4. Note the current **Cost Price** and **Sale Price**

### Monitor WebSocket Subscription

**Browser DevTools → Network Tab → WS (WebSockets)**

You should see:
- Connection to `ws://localhost:8080/app/6vhbhb9lmyhxuixydxkq`
- Subscription message for channel `private-tenant.{id}.company.{id}.product.{id}`

**✅ Success Indicator:** WebSocket connection established and subscribed to product channel

---

## Step 6: Trigger Product Cost Update

### Option A: Via Purchase Order (Realistic Scenario)

1. Navigate to **Purchases → Purchase Orders**
2. Create a new purchase order for the product you're viewing
3. Set a different unit cost (e.g., if current cost is €10.00, set to €12.00)
4. Confirm the purchase order
5. **Receive** the purchase order (this triggers WAC calculation)

### Option B: Via Tinker (Quick Test)

**Terminal 3 - Tinker**

```bash
cd apps/api
php artisan tinker
```

```php
// Get a test product
$product = \App\Modules\Product\Domain\Product::first();

// Get a location
$location = \App\Modules\Company\Domain\Location::first();

// Trigger WAC update (simulates purchase receipt)
$wacService = app(\App\Modules\Inventory\Application\Services\WeightedAverageCostService::class);

$wacService->recordPurchase(
    product: $product,
    location: $location,
    quantity: 10.0,
    landedUnitCost: 15.50,  // New cost (different from current)
    reference: 'TEST-PO-001'
);

// This should trigger:
// 1. ProductCostPriceUpdated domain event
// 2. ProductCostPriceUpdatedBroadcast
// 3. WebSocket message to frontend
```

---

## Step 7: Verify Real-Time Update

### What Should Happen

**Within 1 second of receiving the purchase order:**

1. **Browser DevTools → Network → WS:**
   - New message received on WebSocket connection
   - Message type: `product.cost-price-updated`
   - Payload contains new cost and sale prices

2. **ProductDetailPage UI:**
   - Cost Price updates automatically (no page refresh!)
   - Sale Price updates automatically (calculated via margin)
   - No loading spinner or flash (smooth update)

3. **Browser Console:**
   - Log message: `Product query invalidated: product-{id}`
   - Log message: `Refetching product data...`

### Expected WebSocket Message

```json
{
  "event": "product.cost-price-updated",
  "data": {
    "productId": "9a1b2c3d-4e5f-6g7h-8i9j-0k1l2m3n4o5p",
    "productSku": "BRAKE-PAD-001",
    "oldCostPrice": "10.00",
    "newCostPrice": "15.50",
    "oldSalePrice": "13.00",
    "newSalePrice": "20.15",
    "reason": "purchase_receipt",
    "timestamp": "2025-12-22T21:45:30+00:00",
    "referenceDocument": "TEST-PO-001"
  }
}
```

**✅ Success Indicators:**
- ✅ WebSocket message received within 1 second
- ✅ UI updates without page refresh
- ✅ New prices match the message payload
- ✅ No console errors

---

## Step 8: Test Authorization (Security)

### Test 1: Different Tenant (Should Fail)

1. Create a user in a **different tenant**
2. Log in as that user
3. Try to access the product detail page from Step 5

**Expected:** WebSocket subscription fails with 403 Forbidden
**Reason:** User's tenant_id doesn't match channel's tenantId

### Test 2: Different Company (Should Fail)

1. Create a user in the **same tenant** but **different company**
2. Log in as that user
3. Try to access the product detail page

**Expected:** WebSocket subscription fails with 403 Forbidden
**Reason:** User doesn't have membership in the product's company

### Test 3: Inactive User (Should Fail)

1. Suspend the test user (set status = 'suspended')
2. Try to access the product detail page (may need to refresh session)

**Expected:** WebSocket subscription fails with 403 Forbidden
**Reason:** User account is not active

**✅ Success Indicator:** Authorization properly prevents unauthorized access

---

## Step 9: Test Concurrent Users

### Setup

1. Open two browser windows side-by-side
2. Log in as **User A** in Window 1
3. Log in as **User B** in Window 2 (same company)
4. Both users navigate to the **same product** detail page

### Test

1. In Window 1, trigger a purchase receipt (Step 6)
2. Observe **both windows** update simultaneously

**Expected:**
- Both users see the price update in <1 second
- No conflicts or race conditions
- Both UIs show identical data

**✅ Success Indicator:** Multiple users can subscribe to the same product channel

---

## Step 10: Test Reconnection After Disconnect

### Simulate Disconnect

1. **Stop Reverb server** (Ctrl+C in Terminal 1)
2. Observe frontend console: `WebSocket disconnected`
3. **Restart Reverb server:** `php artisan reverb:start`

**Expected:**
- Frontend automatically reconnects within 5-10 seconds
- Console shows: `WebSocket reconnected`
- Product page still works and receives updates

**✅ Success Indicator:** Automatic reconnection on server restart

---

## Common Test Scenarios

### Scenario 1: Bulk Import

**Test:** Import 100 products via CSV, each triggering cost updates

**Expected:**
- If viewing a product being imported, its price updates in real-time
- No performance degradation
- All WebSocket messages delivered

### Scenario 2: Multiple Products

**Test:** Open 5 product detail pages in different tabs

**Expected:**
- Each tab subscribes to its own channel
- Updates only affect the relevant tab
- Single WebSocket connection shared across tabs

### Scenario 3: Offline/Online

**Test:**
1. Open ProductDetailPage
2. Disconnect network (airplane mode or browser DevTools)
3. Trigger price update
4. Reconnect network

**Expected:**
- Product data is stale while offline
- Upon reconnection, data automatically refreshes
- User sees latest prices without manual refresh

---

## Performance Benchmarks

### Latency Targets

| Metric | Target | How to Measure |
|--------|--------|----------------|
| Event to broadcast | <50ms | Backend logs |
| Broadcast to frontend | <100ms | WebSocket timestamp vs. receive |
| Frontend invalidation | <10ms | React Query DevTools |
| UI update | <50ms | Visual observation |
| **Total end-to-end** | **<300ms** | Purchase receipt → UI update |

### Monitoring

**Backend Logs:**

```bash
tail -f storage/logs/laravel.log | grep "ProductCostPriceUpdated"
```

**Reverb Server Logs:**

Watch Terminal 1 for connection/subscription events

**Frontend DevTools:**

- **Network → WS:** Monitor WebSocket traffic
- **Console:** Check for error messages
- **Performance:** Record timeline to measure UI update speed

---

## Verification Checklist

Use this checklist to confirm E2E functionality:

- [ ] Reverb server starts without errors
- [ ] Backend tests pass (11/11)
- [ ] Frontend connects to WebSocket
- [ ] User subscribes to product channel
- [ ] WebSocket message received when cost updates
- [ ] ProductDetailPage updates without refresh
- [ ] Update appears in <1 second
- [ ] Authorization blocks unauthorized users
- [ ] Multiple users receive updates simultaneously
- [ ] Reconnection works after server restart
- [ ] No console errors or warnings
- [ ] Performance meets latency targets

---

## Success Criteria

**The implementation is successful if:**

1. ✅ Product cost updates appear on ProductDetailPage in <1 second
2. ✅ No page refresh or manual action required
3. ✅ All 11 backend tests pass
4. ✅ Authorization prevents unauthorized access
5. ✅ System handles 100+ concurrent users
6. ✅ Automatic reconnection after disconnects
7. ✅ No impact on existing functionality

---

## Next Steps After Testing

If all tests pass:

1. **Deploy to Staging:**
   - Update staging `.env` with production Reverb credentials
   - Start Reverb server with `--host=0.0.0.0 --port=8080`
   - Configure nginx/Apache reverse proxy for WebSocket traffic

2. **Monitor in Production:**
   - Set up Reverb server monitoring (uptime, connection count)
   - Add error tracking for WebSocket failures
   - Monitor latency metrics

3. **User Training:**
   - Inform users that prices update automatically
   - No need to refresh page after receiving POs
   - Real-time indicator in UI (optional enhancement)

---

**Document Version:** 1.0
**Last Updated:** 2025-12-22
**Author:** Claude Sonnet 4.5 (via Claude Code)
