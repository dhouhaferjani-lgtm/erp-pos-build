# WebSocket Real-Time Updates - Troubleshooting Guide

## Common Issues and Solutions

This guide covers common problems you might encounter with the WebSocket implementation and how to resolve them.

---

## Issue 1: WebSocket Connection Fails

### Symptoms

- Browser console shows: `WebSocket connection failed`
- DevTools Network tab shows WS connection in red
- No real-time updates appear

### Diagnosis

```bash
# Check if Reverb server is running
ps aux | grep reverb | grep -v grep

# Check Reverb port is listening
lsof -i :8080

# Test WebSocket endpoint manually
curl -i -N -H "Connection: Upgrade" \
     -H "Upgrade: websocket" \
     -H "Sec-WebSocket-Version: 13" \
     -H "Sec-WebSocket-Key: test" \
     http://localhost:8080/app/6vhbhb9lmyhxuixydxkq
```

### Solutions

**Solution 1: Start Reverb Server**

```bash
cd apps/api
php artisan reverb:start
```

**Solution 2: Check Port Availability**

```bash
# If port 8080 is in use, change it
# Update apps/api/.env:
REVERB_PORT=8090
REVERB_SERVER_PORT=8090

# Update apps/web frontend config if using .env:
VITE_WS_PORT=8090

# Restart Reverb
php artisan reverb:start
```

**Solution 3: Verify Firewall**

```bash
# macOS - Allow incoming connections
sudo /usr/libexec/ApplicationFirewall/socketfilterfw --add /usr/local/bin/php
sudo /usr/libexec/ApplicationFirewall/socketfilterfw --unblockapp /usr/local/bin/php

# Linux - Check iptables
sudo iptables -L -n | grep 8080
```

**Solution 4: Check Reverb Configuration**

```bash
cd apps/api
php artisan config:show broadcasting.connections.reverb
```

Expected output should show:
```
driver: "reverb"
key: "6vhbhb9lmyhxuixydxkq"
```

---

## Issue 2: 401 Unauthorized on Channel Subscription

### Symptoms

- Console shows: `WebSocket subscription error: 401 Unauthorized`
- DevTools Network shows POST to `/broadcasting/auth` returns 401
- Connection succeeds but subscription fails

### Diagnosis

```bash
# Check if user is authenticated
# In browser console:
console.log(document.cookie.includes('laravel_session'))

# Check if CSRF token is present
console.log(document.cookie.includes('XSRF-TOKEN'))

# Verify Sanctum middleware on auth endpoint
cd apps/api
php artisan route:list | grep broadcasting
```

### Solutions

**Solution 1: Verify User is Logged In**

```typescript
// In browser console
import { useAuthStore } from './stores/authStore'
const { user } = useAuthStore.getState()
console.log('User:', user)
```

If `user` is null:
- Redirect to login page
- Check Sanctum session cookie exists

**Solution 2: Check CORS Configuration**

**File:** `apps/api/config/cors.php`

```php
'paths' => [
    'api/*',
    'broadcasting/auth', // Must be present!
    'sanctum/csrf-cookie',
],

'supports_credentials' => true, // Must be true!
```

**Solution 3: Verify Sanctum Domain**

**File:** `apps/api/config/sanctum.php`

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
    '%s%s',
    'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
    env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST) : ''
))),
```

Add frontend domain:
```env
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000
```

**Solution 4: Clear Cookies and Re-login**

```javascript
// Browser console
document.cookie.split(";").forEach(c => {
  document.cookie = c.replace(/^ +/, "").replace(/=.*/, "=;expires=" + new Date().toUTCString() + ";path=/");
});
location.reload();
```

Then log in again.

---

## Issue 3: 403 Forbidden on Channel Subscription

### Symptoms

- Console shows: `WebSocket subscription error: 403 Forbidden`
- User is logged in (401 doesn't occur)
- Authorization fails

### Diagnosis

```bash
# Check channel authorization logic
cd apps/api
cat routes/channels.php | grep -A 5 "tenant\."
```

### Solutions

**Solution 1: Verify User Has Company Membership**

```php
// In Tinker
php artisan tinker

$user = \App\Modules\Identity\Domain\User::find('user-id');
$user->companyMemberships;

// Check if user belongs to the company
$user->companyMemberships()
    ->where('company_id', 'company-id')
    ->where('status', 'active')
    ->exists();
```

If false, add membership:
```php
\App\Modules\Company\Domain\UserCompanyMembership::create([
    'user_id' => $user->id,
    'company_id' => 'company-id',
    'tenant_id' => $user->tenant_id,
    'role' => \App\Modules\Company\Domain\Enums\MembershipRole::Viewer,
]);
```

**Solution 2: Check User Status**

```php
// In Tinker
$user->status; // Should be UserStatus::Active

// If suspended/inactive:
$user->update(['status' => \App\Modules\Identity\Domain\Enums\UserStatus::Active]);
```

**Solution 3: Verify Tenant ID Matches**

```php
// Check user's tenant
$user->tenant_id; // e.g., "abc123"

// Check product's tenant (should match)
$product = \App\Modules\Product\Domain\Product::find('product-id');
$product->tenant_id; // Should be "abc123"
```

If mismatch, user cannot access product in different tenant (expected behavior).

---

## Issue 4: Broadcast Event Not Firing

### Symptoms

- Purchase receipt completes successfully
- No WebSocket message received
- Backend tests pass
- Reverb server shows no activity

### Diagnosis

```bash
# Check if event is being dispatched
cd apps/api
tail -f storage/logs/laravel.log | grep "ProductCostPriceUpdated"

# Verify broadcast connection
php artisan tinker
\Illuminate\Support\Facades\Broadcast::routes();
config('broadcasting.default'); // Should return "reverb"
```

### Solutions

**Solution 1: Verify BROADCAST_CONNECTION**

```bash
cd apps/api
grep BROADCAST_CONNECTION .env
```

**Must be:**
```env
BROADCAST_CONNECTION=reverb
```

**NOT:**
```env
BROADCAST_CONNECTION=null  # Wrong!
BROADCAST_CONNECTION=redis # Wrong!
```

If incorrect:
```bash
sed -i '' 's/BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=reverb/' .env
php artisan config:clear
php artisan config:cache
```

**Solution 2: Check BroadcastServiceProvider is Registered**

```bash
cat bootstrap/providers.php | grep BroadcastServiceProvider
```

Should show:
```php
App\Providers\BroadcastServiceProvider::class,
```

If missing, add to `bootstrap/providers.php`:
```php
return [
    // ... other providers
    App\Providers\BroadcastServiceProvider::class,
];
```

**Solution 3: Verify Event is Triggered**

Add logging to `WeightedAverageCostService`:

```php
// In recordPurchase method, after line 107:
if ($priceUpdated) {
    \Log::info('Dispatching ProductCostPriceUpdated', [
        'product_id' => $product->id,
        'old_cost' => $currentCostPrice,
        'new_cost' => $newAvgCost,
    ]);

    event(new ProductCostPriceUpdated(...));
}
```

Then check logs:
```bash
tail -f storage/logs/laravel.log
```

**Solution 4: Test Broadcasting Manually**

```php
// In Tinker
php artisan tinker

// Manually dispatch event
$product = \App\Modules\Product\Domain\Product::first();

$event = new \App\Modules\Product\Domain\Events\ProductCostPriceUpdated(
    productId: $product->id,
    tenantId: $product->tenant_id,
    companyId: $product->company_id,
    productSku: $product->sku,
    oldCostPrice: '10.00',
    newCostPrice: '12.00',
    oldSalePrice: '15.00',
    newSalePrice: '18.00',
    reason: 'test',
    referenceDocument: null
);

event($event);

// Check Reverb server logs for activity
```

---

## Issue 5: Frontend Not Receiving Messages

### Symptoms

- Reverb server logs show message sent
- Browser WebSocket shows "connected"
- But no console logs or UI updates

### Diagnosis

```javascript
// Browser console - Check Echo instance
window.Echo
window.Echo.connector.pusher.connection.state // Should be "connected"

// Check subscribed channels
window.Echo.connector.channels // Should show product channel
```

### Solutions

**Solution 1: Verify Channel Name Format**

Check that channel name matches backend format:

**Backend:** `tenant.{tenantId}.company.{companyId}.product.{productId}`
**Frontend:** Must match exactly (Laravel prepends `private-`)

```typescript
// In useProductRealtime hook
console.log('Subscribing to:', channelName)
// Should output: "tenant.abc123.company.def456.product.ghi789"
```

**Solution 2: Check Event Name**

Backend broadcasts as: `product.cost-price-updated`
Frontend must listen to: `product.cost-price-updated`

```typescript
// Verify in useRealtimeChannel
console.log('Listening to event:', eventName)
// Should output: "product.cost-price-updated"
```

**Solution 3: Verify Echo Configuration**

```typescript
// Browser console
window.Echo.connector.options
```

Should show:
```javascript
{
  broadcaster: "reverb",
  key: "6vhbhb9lmyhxuixydxkq",
  wsHost: "localhost",
  wsPort: 8080,
  authEndpoint: "http://localhost:8002/broadcasting/auth"
}
```

**Solution 4: Check React Query Integration**

```typescript
// Add logging to useProductRealtime
const handleUpdate = useCallback((data) => {
  console.log('✅ WebSocket update received:', data)
  queryClient.invalidateQueries({ queryKey: ['product', productId] })
}, [productId, queryClient])
```

---

## Issue 6: Reverb Server Crashes or Hangs

### Symptoms

- Reverb server stops responding
- High memory/CPU usage
- Connection timeouts

### Diagnosis

```bash
# Check server process
ps aux | grep reverb

# Monitor resource usage
top -p $(pgrep -f reverb)

# Check server logs
cd apps/api
tail -f storage/logs/reverb.log
```

### Solutions

**Solution 1: Restart Reverb**

```bash
# Kill existing process
pkill -f "reverb:start"

# Start fresh
cd apps/api
php artisan reverb:start
```

**Solution 2: Increase Memory Limit**

```bash
# Run with higher memory
php -d memory_limit=512M artisan reverb:start
```

**Solution 3: Check for Memory Leaks**

```bash
# Monitor memory over time
watch -n 5 'ps aux | grep reverb'
```

If memory continuously grows:
- Check for unsubscribed channels not being cleaned up
- Review custom channel classes for static variable leaks

**Solution 4: Use Process Manager for Production**

**Install Supervisor:**

```bash
# Ubuntu/Debian
sudo apt-get install supervisor

# macOS
brew install supervisor
```

**Config:** `/etc/supervisor/conf.d/reverb.conf`

```ini
[program:reverb]
command=php /path/to/apps/api/artisan reverb:start
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/reverb.log
```

---

## Issue 7: Multiple Tabs Cause Duplicate Subscriptions

### Symptoms

- Opening multiple tabs subscribes multiple times to same channel
- Excessive WebSocket traffic
- Duplicate messages received

### Solutions

**Solution 1: Use Shared Worker (Advanced)**

Not currently implemented. Each tab maintains its own connection.

**Solution 2: Accept Multiple Subscriptions**

This is the current behavior and is acceptable:
- Each tab gets its own subscription
- Reverb handles this efficiently
- Only active tab needs to update UI

**Solution 3: Tab Communication (Future Enhancement)**

Use BroadcastChannel API to coordinate between tabs:

```typescript
const bc = new BroadcastChannel('product_updates')

// Only one tab subscribes to WebSocket
// Other tabs listen to BroadcastChannel
```

---

## Issue 8: CORS Errors

### Symptoms

- Console shows: `Access-Control-Allow-Origin` error
- WebSocket connection fails with CORS error
- Auth endpoint returns CORS error

### Solutions

**Update `config/cors.php`:**

```php
'paths' => [
    'api/*',
    'broadcasting/auth',
    'sanctum/csrf-cookie',
],

'allowed_origins' => [
    'http://localhost:5173',
    'http://localhost:3000',
],

'supports_credentials' => true,
```

**Restart Laravel:**
```bash
php artisan config:clear
php artisan serve
```

---

## Debugging Checklist

When troubleshooting, work through this checklist:

### Backend

- [ ] Reverb server is running (`php artisan reverb:start`)
- [ ] `BROADCAST_CONNECTION=reverb` in `.env`
- [ ] Config cache cleared (`php artisan config:clear`)
- [ ] BroadcastServiceProvider registered in `bootstrap/providers.php`
- [ ] Backend tests pass (11/11)
- [ ] Channel authorization route exists in `routes/channels.php`
- [ ] Event listener is registered

### Frontend

- [ ] Laravel Echo and Pusher.js installed
- [ ] Echo configuration correct (`lib/echo.ts`)
- [ ] User is logged in (Sanctum session exists)
- [ ] Current company is selected
- [ ] WebSocket connection shows "connected" in DevTools
- [ ] Correct channel name format
- [ ] Correct event name
- [ ] Query invalidation callback fires

### Network

- [ ] No firewall blocking port 8080
- [ ] CORS configured for frontend domain
- [ ] Sanctum stateful domains include frontend
- [ ] WebSocket connection in DevTools Network tab
- [ ] Auth endpoint returns 200 (not 401/403)

---

## Enabling Debug Mode

### Backend Debugging

**Add to `.env`:**
```env
LOG_LEVEL=debug
REVERB_LOG_LEVEL=debug
```

**Add logging to BroadcastProductEventsListener:**

```php
public function handleProductCostUpdated(ProductCostPriceUpdated $event): void
{
    \Log::debug('Broadcasting product update', [
        'product_id' => $event->productId,
        'channel' => sprintf('tenant.%s.company.%s.product.%s',
            $event->tenantId,
            $event->companyId,
            $event->productId
        ),
    ]);

    event(new ProductCostPriceUpdatedBroadcast($event));
}
```

### Frontend Debugging

**Add to Echo configuration:**

```typescript
const echo = new Echo({
  // ... other config
  enableLogging: true,  // Enable Pusher debug logs
  logToConsole: true,
})
```

**Add to useProductRealtime:**

```typescript
const handleUpdate = useCallback((data) => {
  console.log('🔔 Product update received:', {
    productId: data.productId,
    oldCost: data.oldCostPrice,
    newCost: data.newCostPrice,
    timestamp: data.timestamp,
  })
  queryClient.invalidateQueries({ queryKey: ['product', productId] })
}, [productId, queryClient])
```

---

## Getting Help

If you're still stuck after trying these solutions:

1. **Check Logs:**
   - Backend: `apps/api/storage/logs/laravel.log`
   - Reverb: Terminal output where `reverb:start` is running
   - Frontend: Browser DevTools Console

2. **Run Tests:**
   ```bash
   php artisan test --filter="ProductCostPriceUpdatedBroadcastTest|BroadcastProductEventsListenerTest|ProductChannelAuthorizationTest"
   ```

3. **Verify Configuration:**
   ```bash
   php artisan config:show broadcasting
   php artisan about
   ```

4. **Search Issues:**
   - Laravel Reverb: https://github.com/laravel/reverb/issues
   - Laravel Broadcasting: https://laravel.com/docs/broadcasting

5. **Ask for Support:**
   - Include: Laravel version, PHP version, OS
   - Attach: Relevant log excerpts
   - Describe: Expected vs. actual behavior

---

**Document Version:** 1.0
**Last Updated:** 2025-12-22
**Author:** Claude Sonnet 4.5 (via Claude Code)
