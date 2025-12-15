# Smart Payment E2E Testing Guide

**Date:** 2025-12-10
**Status:** Ready for testing with real seeded data
**Test Approach:** No mocks - Real API calls with seeded database

---

## Overview

The Smart Payment E2E tests now use **real seeded data** instead of mocked API responses. This provides:
- ✅ True end-to-end testing with actual database
- ✅ Real API validation and error handling
- ✅ Accurate performance testing
- ✅ Detection of actual integration issues

---

## Prerequisites

### 1. Database Setup
```bash
cd apps/api

# Fresh database with all seeders
php artisan migrate:fresh --seed

# The DatabaseSeeder now automatically calls SmartPaymentTestDataSeeder
# This creates 4 test invoices: TEST-INV-001, TEST-INV-002, TEST-INV-003, TEST-INV-CREDIT
```

### 2. Server Setup
```bash
# Terminal 1: Start API server
cd apps/api
php artisan serve
# API will be available at http://localhost:8000

# Terminal 2: Start web development server
cd apps/web
pnpm dev
# Web app will be available at http://localhost:5173
```

### 3. Verify Seed Data
```bash
cd apps/api
php artisan tinker

# Check test invoices exist
\App\Modules\Document\Domain\Document::where('document_number', 'like', 'TEST-INV-%')->count()
# Should return: 4

# Get customer with test invoices
$customer = \App\Modules\Partner\Domain\Partner::whereHas('documents', function($q) {
    $q->where('document_number', 'like', 'TEST-INV-%');
})->first();

echo $customer->name;
# Should show customer name

exit
```

---

## Test Data Created by Seeders

### User Credentials
- **Email:** `test@example.com`
- **Password:** `password`
- **Role:** Manager (full operational access)

### Test Invoices (SmartPaymentTestDataSeeder)

| Invoice Number | Date | Due Date | Amount | Balance Due | Status | Notes |
|---------------|------|----------|--------|-------------|--------|-------|
| TEST-INV-001 | 45 days ago | 15 days overdue | €2,000.00 | €2,000.00 | Posted | For FIFO testing (oldest) |
| TEST-INV-002 | 20 days ago | Due in 10 days | €1,500.00 | €1,500.00 | Posted | For FIFO testing (second) |
| TEST-INV-003 | 5 days ago | Due in 25 days | €2,500.00 | €2,500.00 | Posted | For FIFO testing (newest) |
| TEST-INV-CREDIT | 10 days ago | Due in 20 days | €1,190.00 | €1,190.00 | Posted | For credit note testing |

**Total Open Balance:** €7,190.00

### Payment Methods & Repositories (DatabaseSeeder)
- Multiple payment methods (Cash, Bank Transfer, Check, etc.)
- Multiple repositories (Cash registers, Bank accounts, Safes)
- All seeded and ready to use

---

## Running E2E Tests

### Run All Smart Payment Tests
```bash
cd apps/web
pnpm exec playwright test e2e/smart-payment.spec.ts
```

### Run Tests in UI Mode (Best for Development)
```bash
pnpm exec playwright test e2e/smart-payment.spec.ts --ui
```

### Run Specific Test
```bash
# FIFO allocation test
pnpm exec playwright test e2e/smart-payment.spec.ts:24

# Manual allocation test
pnpm exec playwright test e2e/smart-payment.spec.ts:86

# Credit note test
pnpm exec playwright test e2e/smart-payment.spec.ts:180
```

### Run with Debug Mode
```bash
pnpm exec playwright test e2e/smart-payment.spec.ts --debug
```

### Run Only Smoke Tests
```bash
pnpm exec playwright test e2e/smart-payment.spec.ts --grep @smoke
```

---

## Test Scenarios

### 1. FIFO Payment Allocation
**Test:** Create payment with FIFO (First In First Out) allocation method

**Steps:**
1. Navigate to `/treasury/payments/new`
2. Enter payment amount: €5,000.00
3. Select payment method (any)
4. Select repository (any)
5. Select customer (with TEST-INV invoices)
6. Submit payment creation
7. Wait for allocation section to appear
8. Select FIFO method (should be default)
9. Click "Preview Allocation"
10. Verify preview shows correct allocation order
11. Click "Apply Allocation"
12. Verify navigation to payments list

**Expected Result:**
- Payment allocated to TEST-INV-001 (€2,000), TEST-INV-002 (€1,500), TEST-INV-003 (€1,500)
- Oldest invoice paid first

### 2. Manual Payment Allocation
**Test:** Create payment with manual invoice selection and custom amounts

**Steps:**
1. Create payment for €3,000.00
2. Select "Manual Selection" allocation method
3. Check TEST-INV-001 and enter €1,500.00
4. Check TEST-INV-002 and enter €1,500.00
5. Preview and apply allocation

**Expected Result:**
- Custom amounts allocated to selected invoices
- Total matches payment amount

### 3. Validation Error (Exceeds Payment)
**Test:** Verify validation when manual allocation exceeds payment amount

**Steps:**
1. Create payment for €2,000.00
2. Select "Manual Selection"
3. Check multiple invoices totaling > €2,000.00
4. Try to apply allocation

**Expected Result:**
- Error message displayed
- Apply button disabled
- Cannot submit invalid allocation

### 4. Create Credit Note
**Test:** Create credit note from posted invoice

**Steps:**
1. Navigate to `/sales/invoices`
2. Find and open TEST-INV-CREDIT
3. Click "Create Credit Note" button
4. Enter amount: €500.00
5. Select reason: "Product Return"
6. Enter notes: "Product returned by customer"
7. Submit

**Expected Result:**
- Credit note created successfully
- Invoice balance reduced
- Modal closes or navigates to credit note

### 5. Full Refund Button
**Test:** Use "Full Refund" button to auto-fill amount

**Steps:**
1. Open TEST-INV-CREDIT
2. Click "Create Credit Note"
3. Click "Full Refund" button

**Expected Result:**
- Amount field filled with €1,190.00 (full invoice amount)

---

## Manual Testing Checklist

### Before Testing
- [ ] Database seeded (`php artisan migrate:fresh --seed`)
- [ ] API server running (`php artisan serve` on port 8000)
- [ ] Web server running (`pnpm dev` on port 5173)
- [ ] Can log in as test@example.com / password
- [ ] Test invoices visible in `/sales/invoices`

### Payment Allocation Flow
- [ ] Can create new payment
- [ ] Allocation section appears after payment creation
- [ ] Can see open invoices for selected customer
- [ ] FIFO method works correctly
- [ ] Manual selection allows choosing specific invoices
- [ ] Manual selection allows entering custom amounts
- [ ] Preview shows correct allocation breakdown
- [ ] Apply allocation completes successfully
- [ ] Invoices show reduced balance after allocation

### Credit Note Flow
- [ ] "Create Credit Note" button visible on posted invoices
- [ ] Credit note form opens in modal
- [ ] Can enter amount and reason
- [ ] "Full Refund" button fills correct amount
- [ ] Validation prevents amount exceeding invoice total
- [ ] Credit note creation succeeds
- [ ] Invoice balance updated after credit note

---

## Troubleshooting

### Tests Failing: "Cannot find TEST-INV invoices"
**Solution:**
```bash
cd apps/api
php artisan migrate:fresh --seed
# Ensure SmartPaymentTestDataSeeder runs
```

### Tests Failing: "Payment allocation section not appearing"
**Possible Causes:**
1. Selected customer doesn't have open invoices
2. Translation keys don't match
3. API route for open invoices not working

**Debug:**
```bash
# Check API endpoint directly
curl http://localhost:8000/api/v1/partners/{partner-id}/open-invoices

# Check browser network tab for failed requests
```

### Tests Failing: "Modal not found"
**Possible Causes:**
1. Modal takes longer to render
2. Modal CSS classes changed
3. React rendering issue

**Debug:**
- Use `--ui` mode to see actual page state
- Check browser console for React errors
- Verify Modal component is rendering

### API Errors During Tests
**Solution:**
```bash
# Check API logs
cd apps/api
tail -f storage/logs/laravel.log

# Check API is accessible
curl http://localhost:8000/api/health
```

---

## Test Maintenance

### When to Re-seed Database
- Before running full test suite
- After schema changes
- If tests start failing unexpectedly
- Daily for development testing

### Updating Test Data
To modify test invoices, edit: `apps/api/database/seeders/SmartPaymentTestDataSeeder.php`

To add more test scenarios, create additional invoices with different:
- Amounts
- Due dates
- Partners
- Statuses

---

## CI/CD Integration (Future)

```yaml
# .github/workflows/e2e-tests.yml (example)
name: E2E Tests

on: [push, pull_request]

jobs:
  e2e:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: password
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install API dependencies
        run: cd apps/api && composer install

      - name: Run migrations and seed
        run: |
          cd apps/api
          php artisan migrate:fresh --seed
          php artisan serve &

      - name: Setup Node
        uses: actions/setup-node@v3
        with:
          node-version: '20'

      - name: Install web dependencies
        run: cd apps/web && pnpm install

      - name: Install Playwright
        run: cd apps/web && pnpm exec playwright install

      - name: Run E2E tests
        run: cd apps/web && pnpm exec playwright test e2e/smart-payment.spec.ts

      - name: Upload test results
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: playwright-results
          path: apps/web/test-results/
```

---

## Next Steps

1. **Run the full test suite:**
   ```bash
   cd apps/web
   pnpm exec playwright test e2e/smart-payment.spec.ts
   ```

2. **Fix any failing tests** - Tests may need selector adjustments

3. **Manual verification** - Test each flow manually to ensure UI works correctly

4. **Document any issues** - Report failures with screenshots and network logs

5. **Iterate** - Update tests based on actual application behavior

---

## Ready for Manual Testing

**You can now test manually using:**
- **URL:** http://localhost:5173
- **Login:** test@example.com / password
- **Test Data:** Look for invoices starting with "TEST-INV-"

**Test these flows:**
1. Create payment → Select customer with TEST-INV invoices → Allocate using FIFO
2. Create payment → Manual allocation to specific invoices
3. Open TEST-INV-CREDIT → Create credit note

**Report:**
- Any UI issues
- Confusing workflows
- Missing features
- Performance problems

---

**Document Version:** 1.0
**Last Updated:** 2025-12-10
**Status:** Ready for Testing
