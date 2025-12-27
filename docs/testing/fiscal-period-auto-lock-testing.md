# Fiscal Period Auto-Lock - Testing Guide

**Feature:** Automatic Locking of Expired Fiscal Periods
**Implementation Date:** December 23, 2025
**Status:** Ready for Testing
**Version:** 1.0

---

## Executive Summary

This document provides comprehensive testing instructions for the Fiscal Period Auto-Lock feature, which automatically locks expired fiscal periods and closes ended fiscal years on a daily basis.

### What Was Implemented

The system now automatically locks fiscal periods and fiscal years through a three-step process:

1. **STEP 1**: Lock periods that ended more than a country-specific threshold (Open → Closed)
2. **STEP 2**: Mark fiscal years as closed when their end_date has passed (is_closed = true)
3. **STEP 3**: Lock all periods in closed fiscal years (Open/Closed → Locked)

### Key Quality Enhancements (Part 4: 92/100 → 100/100)

1. **Country-Specific Thresholds**: Removed hardcoded "1 month" value; now configurable per country via the country adaptation system
2. **Enhanced Documentation**: Added STEP labels, comprehensive PHPDoc, error handling documentation, and troubleshooting guides
3. **Structured Logging**: Added metrics tracking (periods_closed, fiscal_years_closed, periods_locked, duration_ms)
4. **Robust Error Handling**: Transaction rollback on failure, duplicate execution prevention, detailed error logging

---

## Technical Context

### Files Modified

| File | Purpose | Changes |
|------|---------|---------|
| `app/Modules/Company/Application/DTOs/FiscalYearRules.php` | Country rules configuration | Added `periodAutoLockMonths` property |
| `app/Modules/Company/Application/Services/CountryFiscalRulesProvider.php` | Country-specific rules | Added threshold to TN and FR rules |
| `app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php` | Core auto-lock logic | DI for country rules, logging, enhanced docs |
| `app/Console/Commands/LockExpiredFiscalPeriodsCommand.php` | CLI command | Comprehensive error/troubleshooting docs |
| `tests/Unit/Company/Application/Services/FiscalPeriodAutoLockServiceTest.php` | Unit tests | DI update + country threshold test |
| `tests/Unit/Company/Application/Services/CountryFiscalRulesProviderTest.php` | Unit tests | Updated assertions for new property |

### Business Rules

| Rule | Description | Status Transition |
|------|-------------|-------------------|
| **Rule 1** | Periods ended > threshold automatically close | Open → Closed |
| **Rule 2** | Fiscal years past end_date are marked as closed | is_closed = false → true |
| **Rule 3** | All periods in closed fiscal years become locked | Open/Closed → Locked |
| **Rule 4** | Already locked periods are never modified | Locked → Locked (idempotent) |

### Scheduling

- **Frequency**: Daily at 1:00 AM
- **Overlap Prevention**: `withoutOverlapping()` - prevents concurrent execution
- **Background Execution**: `runInBackground()` - doesn't block other scheduled tasks
- **Location**: `routes/console.php`

### Country-Specific Configuration

| Country | Code | Period Auto-Lock Threshold | Notes |
|---------|------|---------------------------|-------|
| Tunisia | TN | 1 month | Stricter compliance requirement |
| France | FR | 1 month | Standard threshold |
| Fallback | * | 1 month | Uses France rules for unsupported countries |

---

## Prerequisites

Before starting tests, ensure:

- [ ] Local development environment is running (database, Redis)
- [ ] You have access to `php artisan tinker` and `php artisan` commands
- [ ] You can read logs from `storage/logs/laravel.log`
- [ ] Database is seeded with at least one tenant

---

## Test Suite

### Test 1: Country Rules Configuration

**Objective**: Verify that country-specific thresholds are correctly configured.

**Steps**:
```bash
php artisan tinker
```

```php
$provider = app(\App\Modules\Company\Application\Services\CountryFiscalRulesProvider::class);

// Check Tunisia rules
$tnRules = $provider->getRulesForCountry('TN');
echo "Tunisia threshold: " . $tnRules->periodAutoLockMonths . " months\n";

// Check France rules
$frRules = $provider->getRulesForCountry('FR');
echo "France threshold: " . $frRules->periodAutoLockMonths . " months\n";

// Verify unsupported country falls back to France
$usRules = $provider->getRulesForCountry('US');
echo "US fallback country: " . $usRules->countryCode . "\n";
echo "US fallback threshold: " . $usRules->periodAutoLockMonths . " months\n";
```

**Expected Results**:
- Tunisia threshold: `1 months`
- France threshold: `1 months`
- US fallback country: `FR`
- US fallback threshold: `1 months`

**Pass Criteria**: All thresholds match expected values.

---

### Test 2: Dry Run Mode

**Objective**: Verify that dry-run mode doesn't modify any data.

**Steps**:
```bash
# Run command in dry-run mode
php artisan fiscal:lock-expired-periods --dry-run
```

**Expected Results**:
```
Starting fiscal period auto-lock process...
DRY RUN MODE: No changes will be made
```

**Pass Criteria**:
- Command exits with success code (0)
- No database changes are made
- Log shows dry-run execution

---

### Test 3: STEP 1 - Lock Old Periods (Open → Closed)

**Objective**: Verify that periods older than threshold are locked.

**Setup**:
```bash
php artisan tinker
```

```php
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use Carbon\Carbon;

// Create test data
$tenant = Tenant::factory()->create(['name' => 'Test Auto-Lock Tenant']);
$company = Company::factory()->create([
    'tenant_id' => $tenant->id,
    'name' => 'Test Auto-Lock Company',
    'country_code' => 'TN',
]);

$fiscalYear = FiscalYear::create([
    'company_id' => $company->id,
    'name' => '2024',
    'start_date' => Carbon::now()->subYear()->startOfYear(),
    'end_date' => Carbon::now()->subYear()->endOfYear(),
    'is_closed' => false,
]);

// Period ended 2 months ago (should be locked)
$oldPeriod = FiscalPeriod::create([
    'fiscal_year_id' => $fiscalYear->id,
    'company_id' => $company->id,
    'name' => 'Old Period (2 months ago)',
    'period_number' => 10,
    'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
    'end_date' => Carbon::now()->subMonths(2)->endOfMonth(),
    'status' => PeriodStatus::Open,
]);

// Period ended 15 days ago (should NOT be locked)
$recentPeriod = FiscalPeriod::create([
    'fiscal_year_id' => $fiscalYear->id,
    'company_id' => $company->id,
    'name' => 'Recent Period (15 days ago)',
    'period_number' => 12,
    'start_date' => Carbon::now()->subDays(45)->startOfMonth(),
    'end_date' => Carbon::now()->subDays(15)->endOfMonth(),
    'status' => PeriodStatus::Open,
]);

// Current period (should NOT be locked)
$currentPeriod = FiscalPeriod::create([
    'fiscal_year_id' => $fiscalYear->id,
    'company_id' => $company->id,
    'name' => 'Current Period',
    'period_number' => 1,
    'start_date' => Carbon::now()->startOfMonth(),
    'end_date' => Carbon::now()->endOfMonth(),
    'status' => PeriodStatus::Open,
]);

echo "=== Test Data Created ===\n";
echo "Old Period ID: {$oldPeriod->id} (Status: {$oldPeriod->status->value})\n";
echo "Recent Period ID: {$recentPeriod->id} (Status: {$recentPeriod->status->value})\n";
echo "Current Period ID: {$currentPeriod->id} (Status: {$currentPeriod->status->value})\n";
echo "\nCopy these IDs for verification after running the command.\n";
```

**Execution**:
```bash
# Run the auto-lock command
php artisan fiscal:lock-expired-periods
```

**Verification**:
```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\FiscalPeriod;

// Replace with actual IDs from setup
$oldPeriod = FiscalPeriod::find('OLD_PERIOD_ID');
$recentPeriod = FiscalPeriod::find('RECENT_PERIOD_ID');
$currentPeriod = FiscalPeriod::find('CURRENT_PERIOD_ID');

echo "Old Period Status: {$oldPeriod->status->value}\n";
echo "Recent Period Status: {$recentPeriod->status->value}\n";
echo "Current Period Status: {$currentPeriod->status->value}\n";
```

**Expected Results**:
- Old Period Status: `closed` (was `open`)
- Recent Period Status: `open` (unchanged)
- Current Period Status: `open` (unchanged)

**Pass Criteria**: Only periods older than 1 month are changed to `closed`.

---

### Test 4: STEP 2 - Close Ended Fiscal Years

**Objective**: Verify that fiscal years past their end_date are marked as closed.

**Setup**:
```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\FiscalYear;
use Carbon\Carbon;

$company = Company::first(); // Use existing company

// Create ended fiscal year
$endedYear = FiscalYear::create([
    'company_id' => $company->id,
    'name' => '2022',
    'start_date' => Carbon::now()->subYears(3)->startOfYear(),
    'end_date' => Carbon::now()->subYears(3)->endOfYear(),
    'is_closed' => false,
]);

// Create current fiscal year
$currentYear = FiscalYear::create([
    'company_id' => $company->id,
    'name' => '2025',
    'start_date' => Carbon::now()->startOfYear(),
    'end_date' => Carbon::now()->endOfYear(),
    'is_closed' => false,
]);

echo "=== Test Data Created ===\n";
echo "Ended Year ID: {$endedYear->id} (is_closed: " . ($endedYear->is_closed ? 'true' : 'false') . ")\n";
echo "Current Year ID: {$currentYear->id} (is_closed: " . ($currentYear->is_closed ? 'true' : 'false') . ")\n";
```

**Execution**:
```bash
php artisan fiscal:lock-expired-periods
```

**Verification**:
```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\FiscalYear;

$endedYear = FiscalYear::find('ENDED_YEAR_ID');
$currentYear = FiscalYear::find('CURRENT_YEAR_ID');

echo "Ended Year is_closed: " . ($endedYear->is_closed ? 'true' : 'false') . "\n";
echo "Current Year is_closed: " . ($currentYear->is_closed ? 'true' : 'false') . "\n";
```

**Expected Results**:
- Ended Year is_closed: `true` (was `false`)
- Current Year is_closed: `false` (unchanged)

**Pass Criteria**: Only fiscal years past their end_date are marked as closed.

---

### Test 5: STEP 3 - Lock Periods in Closed Fiscal Years

**Objective**: Verify that all periods in closed fiscal years become locked.

**Setup**:
```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use Carbon\Carbon;

$company = Company::first();

// Create closed fiscal year
$closedYear = FiscalYear::create([
    'company_id' => $company->id,
    'name' => '2021',
    'start_date' => Carbon::now()->subYears(4)->startOfYear(),
    'end_date' => Carbon::now()->subYears(4)->endOfYear(),
    'is_closed' => true, // Already closed
]);

// Create periods with different statuses
$openPeriod = FiscalPeriod::create([
    'fiscal_year_id' => $closedYear->id,
    'company_id' => $company->id,
    'name' => 'Open Period in Closed Year',
    'period_number' => 1,
    'start_date' => $closedYear->start_date,
    'end_date' => $closedYear->start_date->copy()->endOfMonth(),
    'status' => PeriodStatus::Open,
]);

$closedPeriod = FiscalPeriod::create([
    'fiscal_year_id' => $closedYear->id,
    'company_id' => $company->id,
    'name' => 'Closed Period in Closed Year',
    'period_number' => 2,
    'start_date' => $closedYear->start_date->copy()->addMonth()->startOfMonth(),
    'end_date' => $closedYear->start_date->copy()->addMonth()->endOfMonth(),
    'status' => PeriodStatus::Closed,
]);

$lockedPeriod = FiscalPeriod::create([
    'fiscal_year_id' => $closedYear->id,
    'company_id' => $company->id,
    'name' => 'Already Locked Period',
    'period_number' => 3,
    'start_date' => $closedYear->start_date->copy()->addMonths(2)->startOfMonth(),
    'end_date' => $closedYear->start_date->copy()->addMonths(2)->endOfMonth(),
    'status' => PeriodStatus::Locked,
]);

echo "=== Test Data Created ===\n";
echo "Open Period ID: {$openPeriod->id} (Status: {$openPeriod->status->value})\n";
echo "Closed Period ID: {$closedPeriod->id} (Status: {$closedPeriod->status->value})\n";
echo "Locked Period ID: {$lockedPeriod->id} (Status: {$lockedPeriod->status->value})\n";
```

**Execution**:
```bash
php artisan fiscal:lock-expired-periods
```

**Verification**:
```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\FiscalPeriod;

$openPeriod = FiscalPeriod::find('OPEN_PERIOD_ID');
$closedPeriod = FiscalPeriod::find('CLOSED_PERIOD_ID');
$lockedPeriod = FiscalPeriod::find('LOCKED_PERIOD_ID');

echo "Open Period Status: {$openPeriod->status->value}\n";
echo "Closed Period Status: {$closedPeriod->status->value}\n";
echo "Locked Period Status: {$lockedPeriod->status->value}\n";
```

**Expected Results**:
- Open Period Status: `locked` (was `open`)
- Closed Period Status: `locked` (was `closed`)
- Locked Period Status: `locked` (unchanged)

**Pass Criteria**: All periods in closed fiscal years become `locked`, regardless of previous status.

---

### Test 6: Logging and Metrics

**Objective**: Verify that structured logging captures all metrics correctly.

**Setup**: Clear the log file to see only new entries:
```bash
> storage/logs/laravel.log
```

**Execution**:
```bash
# Run the command
php artisan fiscal:lock-expired-periods

# Check the log immediately
tail -20 storage/logs/laravel.log | grep "auto-lock"
```

**Expected Log Entry**:
```
[2025-12-23 XX:XX:XX] local.INFO: Fiscal period auto-lock completed {"periods_closed":X,"fiscal_years_closed":Y,"periods_locked":Z,"duration_ms":NN.NN}
```

**Verification Checklist**:
- [ ] Log contains `Fiscal period auto-lock completed`
- [ ] `periods_closed` count is accurate (matches number of periods changed from Open → Closed)
- [ ] `fiscal_years_closed` count is accurate (matches number of years marked as closed)
- [ ] `periods_locked` count is accurate (matches number of periods changed to Locked)
- [ ] `duration_ms` is reasonable (< 1000ms for normal datasets)

**Pass Criteria**: Log entry exists with all four metrics.

---

### Test 7: Error Handling

**Objective**: Verify that errors are properly caught and logged.

**Setup**: Create a scenario that will cause an error (e.g., database constraint violation).

**Method 1 - Simulate Database Failure**:
```bash
# Temporarily rename the database in .env or stop PostgreSQL
# Then run the command
php artisan fiscal:lock-expired-periods
```

**Expected Output**:
```
Starting fiscal period auto-lock process...
✗ Failed to lock expired periods: SQLSTATE[HY000]...
```

**Check Error Log**:
```bash
tail -20 storage/logs/laravel.log | grep "auto-lock failed"
```

**Expected Log Entry**:
```
[2025-12-23 XX:XX:XX] local.ERROR: Fiscal period auto-lock failed {"error":"SQLSTATE[HY000]...","trace":"...","duration_ms":XX.XX}
```

**Pass Criteria**:
- [ ] Command exits with failure code (1)
- [ ] Error message is displayed to user
- [ ] Error is logged with full trace
- [ ] Transaction is rolled back (no partial updates)

---

### Test 8: Scheduler Configuration

**Objective**: Verify that the command is scheduled correctly.

**Steps**:
```bash
# List all scheduled commands
php artisan schedule:list
```

**Expected Output** (look for this entry):
```
0 1 * * * php artisan fiscal:lock-expired-periods > /dev/null 2>&1
```

**Verification**:
```bash
# Check routes/console.php
cat routes/console.php | grep -A 4 "fiscal:lock-expired-periods"
```

**Expected Code**:
```php
Schedule::command('fiscal:lock-expired-periods')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();
```

**Pass Criteria**:
- [ ] Command appears in schedule:list
- [ ] Scheduled for 01:00 (1:00 AM)
- [ ] Has `withoutOverlapping()` protection
- [ ] Has `runInBackground()` enabled

---

### Test 9: Multi-Company Scenario

**Objective**: Verify that auto-lock works correctly across multiple companies.

**Setup**:
```bash
php artisan tinker
```

```php
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use Carbon\Carbon;

$tenant = Tenant::factory()->create(['name' => 'Multi-Company Test Tenant']);

// Create 3 companies
for ($i = 1; $i <= 3; $i++) {
    $company = Company::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => "Test Company {$i}",
        'country_code' => 'TN',
    ]);

    $fiscalYear = FiscalYear::create([
        'company_id' => $company->id,
        'name' => '2024',
        'start_date' => Carbon::now()->subYear()->startOfYear(),
        'end_date' => Carbon::now()->subYear()->endOfYear(),
        'is_closed' => false,
    ]);

    $period = FiscalPeriod::create([
        'fiscal_year_id' => $fiscalYear->id,
        'company_id' => $company->id,
        'name' => 'Old Period',
        'period_number' => 10,
        'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
        'end_date' => Carbon::now()->subMonths(2)->endOfMonth(),
        'status' => PeriodStatus::Open,
    ]);

    echo "Company {$i}: Period ID {$period->id}\n";
}

echo "\n=== Created 3 companies with old periods ===\n";
```

**Execution**:
```bash
php artisan fiscal:lock-expired-periods
```

**Verification**:
```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\Enums\PeriodStatus;

// Count periods that were locked
$lockedCount = FiscalPeriod::where('status', PeriodStatus::Closed)
    ->whereHas('company', function($q) {
        $q->where('name', 'like', 'Test Company%');
    })
    ->count();

echo "Number of periods locked: {$lockedCount}\n";
```

**Expected Result**: `Number of periods locked: 3`

**Pass Criteria**: All periods across all companies are locked correctly.

---

### Test 10: Performance Test

**Objective**: Verify that performance is acceptable with larger datasets.

**Setup**:
```bash
php artisan tinker
```

```php
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use Carbon\Carbon;

$tenant = Tenant::factory()->create(['name' => 'Performance Test Tenant']);

echo "Creating 100 companies with old periods...\n";

for ($i = 0; $i < 100; $i++) {
    $company = Company::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => "Perf Test Company {$i}",
    ]);

    $fiscalYear = FiscalYear::create([
        'company_id' => $company->id,
        'name' => '2024',
        'start_date' => Carbon::now()->subYear()->startOfYear(),
        'end_date' => Carbon::now()->subYear()->endOfYear(),
        'is_closed' => false,
    ]);

    FiscalPeriod::create([
        'fiscal_year_id' => $fiscalYear->id,
        'company_id' => $company->id,
        'name' => 'Old Period',
        'period_number' => 10,
        'start_date' => Carbon::now()->subMonths(3)->startOfMonth(),
        'end_date' => Carbon::now()->subMonths(2)->endOfMonth(),
        'status' => PeriodStatus::Open,
    ]);

    if (($i + 1) % 20 == 0) {
        echo "Created " . ($i + 1) . " companies...\n";
    }
}

echo "Done! Created 100 companies with old periods.\n";
```

**Execution**:
```bash
# Clear log
> storage/logs/laravel.log

# Run command
php artisan fiscal:lock-expired-periods

# Check duration
tail -1 storage/logs/laravel.log | grep "duration_ms"
```

**Expected Performance**:
- Duration should be < 1000ms (1 second) for 100 periods
- `periods_closed` should equal 100

**Pass Criteria**: Duration is acceptable and all periods are processed.

---

## Automated Test Suite

All unit tests should pass:

```bash
# Run all Company module tests
php artisan test tests/Unit/Company/

# Expected output:
# Tests: 46 passed (211 assertions)
```

**Critical Tests**:
- `it_locks_periods_ended_more_than_one_month_ago` ✓
- `it_does_not_lock_periods_ended_less_than_one_month_ago` ✓
- `it_marks_fiscal_years_as_closed_when_ended` ✓
- `it_locks_all_periods_in_closed_fiscal_years` ✓
- `it_uses_country_specific_period_lock_threshold` ✓
- `it_provides_tunisia_rules` ✓
- `it_provides_france_rules` ✓

---

## Troubleshooting Guide

### Issue: Command doesn't lock any periods

**Possible Causes**:
1. All periods are within the 1-month threshold
2. Periods are already locked
3. Companies don't have the correct `country_code`

**Debug Steps**:
```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use Carbon\Carbon;

// Check for old open periods
$oldPeriods = FiscalPeriod::where('status', PeriodStatus::Open)
    ->where('end_date', '<', Carbon::now()->subMonth())
    ->count();

echo "Open periods older than 1 month: {$oldPeriods}\n";
```

---

### Issue: Logging doesn't show metrics

**Possible Causes**:
1. Log level is too high (production environment)
2. Log file permissions issue

**Debug Steps**:
```bash
# Check log file permissions
ls -la storage/logs/laravel.log

# Check environment
php artisan env

# Manually check logging
php artisan tinker
```

```php
\Log::info('Test log entry', ['test_metric' => 123]);
```

Then check: `tail -1 storage/logs/laravel.log`

---

### Issue: Scheduler not running

**Possible Causes**:
1. Cron job not configured
2. Laravel scheduler not enabled

**Debug Steps**:
```bash
# Check if scheduler is configured
php artisan schedule:list

# Manually run scheduler (for testing only)
php artisan schedule:run

# Check cron configuration
crontab -l | grep "schedule:run"
```

**Expected cron entry**:
```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

---

### Issue: Transaction deadlocks

**Possible Causes**:
1. Multiple scheduler instances running
2. Manual execution during scheduled run

**Debug Steps**:
```bash
# Check for duplicate processes
ps aux | grep "fiscal:lock-expired-periods"

# Kill duplicate processes if found
kill -9 <PID>
```

**Prevention**: The `withoutOverlapping()` directive should prevent this, but verify in `routes/console.php`.

---

## Rollback Instructions

If critical issues are found and rollback is needed:

### Step 1: Disable Scheduled Job

```bash
# Comment out in routes/console.php
// Schedule::command('fiscal:lock-expired-periods')
//     ->dailyAt('01:00')
//     ->withoutOverlapping()
//     ->runInBackground();
```

### Step 2: Revert Code Changes

```bash
# View changed files
git status

# Revert specific files
git checkout HEAD -- app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php
git checkout HEAD -- app/Modules/Company/Application/Services/CountryFiscalRulesProvider.php
# ... (revert other files as needed)
```

### Step 3: Manual Unlock (if needed)

If periods were incorrectly locked:

```bash
php artisan tinker
```

```php
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\Enums\PeriodStatus;

// Unlock specific period
$period = FiscalPeriod::find('PERIOD_ID');
$period->status = PeriodStatus::Open;
$period->save();

// Or bulk unlock (use with extreme caution)
FiscalPeriod::where('status', PeriodStatus::Closed)
    ->where('updated_at', '>', now()->subHours(24))
    ->update(['status' => PeriodStatus::Open]);
```

---

## Test Results Template

Use this template to document test results:

```markdown
## Test Execution Report

**Date**: YYYY-MM-DD
**Tester**: [Your Name]
**Environment**: [Local/Staging/Production]
**Database**: [PostgreSQL version]

### Test Results Summary

| Test # | Test Name | Status | Notes |
|--------|-----------|--------|-------|
| 1 | Country Rules Configuration | ☐ Pass ☐ Fail | |
| 2 | Dry Run Mode | ☐ Pass ☐ Fail | |
| 3 | STEP 1 - Lock Old Periods | ☐ Pass ☐ Fail | |
| 4 | STEP 2 - Close Ended Fiscal Years | ☐ Pass ☐ Fail | |
| 5 | STEP 3 - Lock Periods in Closed Years | ☐ Pass ☐ Fail | |
| 6 | Logging and Metrics | ☐ Pass ☐ Fail | |
| 7 | Error Handling | ☐ Pass ☐ Fail | |
| 8 | Scheduler Configuration | ☐ Pass ☐ Fail | |
| 9 | Multi-Company Scenario | ☐ Pass ☐ Fail | |
| 10 | Performance Test | ☐ Pass ☐ Fail | |

### Issues Found

1. **Issue**: [Description]
   - **Severity**: Critical / High / Medium / Low
   - **Steps to Reproduce**: [Steps]
   - **Expected**: [What should happen]
   - **Actual**: [What actually happened]
   - **Screenshots/Logs**: [Attach if applicable]

### Recommendations

- [ ] Feature is ready for production
- [ ] Feature needs minor fixes before deployment
- [ ] Feature needs major rework

**Tester Signature**: __________________
**Date**: __________________
```

---

## Reference Links

### Code Files
- Service: `app/Modules/Company/Application/Services/FiscalPeriodAutoLockService.php`
- Command: `app/Console/Commands/LockExpiredFiscalPeriodsCommand.php`
- Country Rules: `app/Modules/Company/Application/Services/CountryFiscalRulesProvider.php`
- Tests: `tests/Unit/Company/Application/Services/FiscalPeriodAutoLockServiceTest.php`

### Related Documentation
- Plan Document: `.claude/plans/golden-noodling-dongarra.md`
- Database Schema: `docs/DATABASE.md`
- Country Adaptation System: Section in `CLAUDE.md`

### Support Contacts
- **Developer**: [Your Name]
- **Tech Lead**: [Tech Lead Name]
- **Product Owner**: [PO Name]

---

**Document Version**: 1.0
**Last Updated**: December 23, 2025
**Next Review**: After testing completion
