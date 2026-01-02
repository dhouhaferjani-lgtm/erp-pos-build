# Company Selection & Multi-Company State Management

**Document Version:** 1.0
**Last Updated:** 2026-01-02
**Status:** Production Ready

---

## Overview

The company selection system enables users to seamlessly switch between multiple companies while maintaining their selection across page refreshes. This is a foundational feature for multi-company tenants where a single user has access to multiple companies (e.g., a group of related businesses, franchises, or holding companies).

### Key Requirements

1. **Persistent Selection**: Selected company must survive page refreshes
2. **Immediate Updates**: Switching companies updates the UI immediately without refresh
3. **Currency Accuracy**: All currency displays must reflect the selected company's currency
4. **Race-Free**: No race conditions between state hydration and data fetching
5. **Conflict-Free**: No interference from framework persistence mechanisms

---

## Architecture

### State Management Stack

```
┌─────────────────────────────────────────────────────────┐
│                  CompanyProvider                        │
│  - Fetches companies from API (React Query)             │
│  - Calls store.setCompanies() with fetched data         │
│  - Defaults to first company if none selected           │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│                   companyStore (Zustand)                │
│  - Manages current company selection                    │
│  - Stores list of available companies                   │
│  - Handles manual localStorage persistence              │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│            localStorage (Manual Persistence)            │
│  Key: 'autoerp-company-selection'                       │
│  Value: company UUID (plain string)                     │
│  Separate from Zustand persist middleware               │
└─────────────────────────────────────────────────────────┘
```

### Data Flow

**Initial Load (First Time User):**
```
1. CompanyProvider fetches companies from API
   ↓
2. companyStore.setCompanies(companies)
   ↓
3. No localStorage entry found → currentCompanyId remains null
   ↓
4. CompanyProvider detects null → calls setCurrentCompany(companies[0].id)
   ↓
5. Store updates state + writes to localStorage
```

**Page Refresh (Returning User):**
```
1. CompanyProvider fetches companies from API
   ↓
2. companyStore.setCompanies(companies)
   ↓
3. Store reads 'autoerp-company-selection' from localStorage
   ↓
4. Validates company ID exists in fetched companies
   ↓
5. Restores currentCompanyId from localStorage
   ↓
6. CompanyProvider detects non-null ID → skips default
```

**Company Switch (User Action):**
```
1. User selects company from dropdown
   ↓
2. Call companyStore.setCurrentCompany(companyId)
   ↓
3. Store validates company exists in list
   ↓
4. Updates currentCompanyId in state
   ↓
5. Writes to localStorage 'autoerp-company-selection'
   ↓
6. All components using useCompany() re-render immediately
```

---

## Implementation Details

### companyStore (Zustand)

**Location:** `apps/web/src/stores/companyStore.ts`

**Key Design Decisions:**

1. **Manual localStorage Persistence**: Uses a separate key (`autoerp-company-selection`) instead of relying on Zustand persist middleware to avoid race conditions.

2. **Validation on Restore**: When reading from localStorage, validates that the company ID still exists in the fetched companies list (handles cases where company was deleted).

3. **Synchronous Read**: Reads from localStorage synchronously in `setCompanies()` to ensure selection is restored before CompanyProvider makes decisions.

4. **Zustand Persist Disabled**: Uses `partialize: () => ({})` to disable automatic persistence while keeping the persist middleware structure for future use.

**State Interface:**
```typescript
interface CompanyState {
  currentCompanyId: string | null  // Selected company UUID
  companies: Company[]             // Available companies
  isLoading: boolean              // Loading state
}

interface Company {
  id: string
  name: string
  legalName: string
  taxId: string | null
  countryCode: string
  currency: string      // ISO 4217 (EUR, TND, USD, etc.)
  locale: string        // BCP 47 (en, fr, ar, etc.)
  timezone: string      // IANA (Europe/Paris, Africa/Tunis, etc.)
}
```

**Key Methods:**
```typescript
setCompanies(companies: Company[]): void
  // Sets available companies
  // Attempts to restore selection from localStorage
  // Validates restored company still exists
  // Updates isLoading to false

setCurrentCompany(companyId: string): void
  // Validates company exists in companies array
  // Updates currentCompanyId in state
  // Persists to localStorage 'autoerp-company-selection'
  // Logs success/error to console

getCurrentCompany(): Company | null
  // Returns full Company object for current selection
  // Returns null if no selection or ID not found

reset(): void
  // Resets to initial state
  // Called on logout
```

### CompanyProvider (React Query)

**Location:** `apps/web/src/features/company/CompanyProvider.tsx`

**Responsibilities:**

1. **Fetch Companies**: Calls `GET /user/companies` to get list of accessible companies
2. **Initialize Store**: Calls `setCompanies()` when data arrives
3. **Default Selection**: If no company selected, defaults to first company after a tick
4. **Loading UI**: Shows loading spinner while fetching
5. **Error Handling**: Shows error message if no companies available
6. **Reset on Logout**: Clears store when user logs out

**Key Implementation:**
```typescript
// Fetch companies
const { data, isLoading, isError, error } = useQuery({
  queryKey: ['user', 'companies'],
  queryFn: async () => {
    const response = await api.get<CompaniesApiResponse>('/user/companies')
    return response.data.data.map(mapCompanyResponse)
  },
  retry: 1,
  staleTime: 1000 * 60 * 10, // 10 minutes
  enabled: isAuthenticated && !isAdminRoute,
})

// Update store when data arrives
useEffect(() => {
  if (data) {
    setCompanies(data)
    // Default to first if none selected
    setTimeout(() => {
      const state = useCompanyStore.getState()
      if (!state.currentCompanyId && data.length > 0) {
        setCurrentCompany(data[0].id)
      }
    }, 0)
  }
}, [data, setCompanies, setCurrentCompany])
```

**Why setTimeout()?** Ensures Zustand persist middleware has finished its initialization cycle before we check the state. This prevents race conditions where we might default to first company before localStorage restoration completes.

### useCompany Hook

**Location:** `apps/web/src/hooks/useCompany.ts`

**Convenience hook** that combines store access with company lookup:

```typescript
export function useCompany() {
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const getCurrentCompany = useCompanyStore((state) => state.getCurrentCompany)
  const setCurrentCompany = useCompanyStore((state) => state.setCurrentCompany)

  return {
    currentCompanyId,
    currentCompany: getCurrentCompany(),
    setCurrentCompany,
  }
}
```

**Usage in Components:**
```typescript
import { useCompany } from '@/hooks/useCompany'
import { formatCurrency } from '@/lib/formatCurrency'

function AccountBalance({ amount }: { amount: number }) {
  const { currentCompany } = useCompany()
  const { i18n } = useTranslation()

  if (!currentCompany) {
    return <span>{amount}</span>
  }

  return (
    <span>
      {formatCurrency(amount, currentCompany.currency, i18n.language)}
    </span>
  )
}
```

---

## Critical Bug History

### Bug #1: Hardcoded Currency (2026-01-02)

**Symptom:** Chart of Accounts always displayed USD currency symbols regardless of selected company.

**Root Cause:** AccountTreeView component was using `config.currency` from CompanyConfigContext instead of `currentCompany.currency` from the selected company.

**Impact:** High - Financial displays showed incorrect currency symbols, confusing users.

**Fix:** Changed AccountTreeView to use:
```typescript
const { currentCompany } = useCompany()  // Was: useCompanyConfig()

// In render:
formatCurrency(amount, currentCompany.currency, i18n.language)
```

**Lesson:** Company config (vertical settings) is different from selected company (multi-company selection). Always use selected company for locale-specific data like currency.

### Bug #2: Selection Not Persisting (2026-01-02)

**Symptom:** After hard refresh (Cmd+R), company selection reverted to first company instead of maintaining user's selected company.

**Root Cause:** Race condition between Zustand persist middleware hydration and CompanyProvider calling setCompanies(). The middleware would initialize localStorage with empty state, overwriting the manual persistence.

**Impact:** Critical - Users lost their company context on every refresh, requiring reselection.

**Investigation Timeline:**
1. Tried adding `_hasHydrated` flag with `onRehydrateStorage` callback → Failed (wrong callback signature)
2. Fixed callback signature → Still failed (_hasHydrated kept resetting)
3. Discovered persist middleware was managing same localStorage key → Conflict
4. Disabled auto-persistence with `partialize: () => ({})` → Still had conflicts
5. **Solution:** Used completely separate localStorage key for manual persistence

**Fix:**
```typescript
// Use separate key from Zustand persist
const MANUAL_STORAGE_KEY = 'autoerp-company-selection'

// In setCompanies:
const stored = localStorage.getItem(MANUAL_STORAGE_KEY)
if (stored) {
  currentCompanyId = stored  // Plain string, not JSON
}

// In setCurrentCompany:
localStorage.setItem(MANUAL_STORAGE_KEY, companyId)
```

**Lesson:** When using Zustand persist middleware, don't manually manage the same localStorage key it uses. The middleware controls the key lifecycle and will overwrite manual changes. Use a separate key for manual persistence or fully commit to middleware-based persistence.

---

## Testing Strategy

### Unit Tests

**Location:** `apps/web/src/stores/__tests__/companyStore.test.ts`

**Coverage:**
- ✅ Initial state (null selection, empty companies, loading)
- ✅ setCompanies updates state and loading flag
- ✅ setCurrentCompany validates and persists
- ✅ getCurrentCompany returns selected company object
- ✅ Restore from localStorage on setCompanies
- ✅ Validate restored company still exists
- ✅ Clear selection if company deleted
- ✅ Handle localStorage errors gracefully
- ✅ Reset clears all state

**Regression Tests (Critical User Journeys):**
- ✅ Currency displays correctly for selected company
- ✅ Selection persists across simulated page refresh
- ✅ Switching companies updates immediately
- ✅ First-time user defaults to first company
- ✅ No interference from Zustand persist middleware

**Edge Cases:**
- ✅ Empty companies array
- ✅ Rapid company switching
- ✅ Companies with duplicate names
- ✅ Invalid company ID in localStorage
- ✅ localStorage quota exceeded errors

**Run Tests:**
```bash
cd apps/web
pnpm test src/stores/__tests__/companyStore.test.ts
```

### Manual Testing Checklist

**Before Release:**
- [ ] Login as multi-company user
- [ ] Verify company selector shows all companies
- [ ] Select France company → Verify EUR currency in Chart of Accounts
- [ ] Select Tunisia company → Verify TND currency displays immediately
- [ ] Hard refresh (Cmd+R) → Verify Tunisia company persists
- [ ] Verify TND currency still displays after refresh
- [ ] Switch back to France → Verify EUR currency
- [ ] Hard refresh → Verify France company persists
- [ ] Logout and login → Verify selection cleared (defaults to first)
- [ ] Check browser console for localStorage errors

---

## Performance Considerations

### localStorage Read Performance

- **When:** Read once per page load in `setCompanies()`
- **Type:** Synchronous read (blocking)
- **Impact:** Negligible (~1ms for small string)
- **Optimization:** Plain string storage (not JSON) for faster parse

### localStorage Write Performance

- **When:** On every company switch
- **Type:** Synchronous write (blocking)
- **Impact:** Negligible (~1ms for UUID string)
- **Frequency:** Low (user action, not automated)

### State Updates

- **Zustand Performance:** Optimized with selective subscriptions
- **Re-render Scope:** Only components using `useCompany()` re-render
- **Memo Strategy:** Company object reference stable until switch

### React Query Caching

- **Cache Duration:** 10 minutes (staleTime)
- **Refetch Strategy:** On mount if stale
- **Background Updates:** Disabled (companies rarely change)

---

## Troubleshooting

### Issue: Company selection resets on every refresh

**Check:**
1. Verify `localStorage.getItem('autoerp-company-selection')` has valid UUID
2. Check browser console for "[CompanyStore] Restored currentCompanyId from localStorage"
3. Ensure UUID in localStorage matches a company ID in fetched list
4. Verify no browser extension is clearing localStorage

**Debug:**
```typescript
// Add to browser console
console.log('Stored ID:', localStorage.getItem('autoerp-company-selection'))
console.log('Companies:', useCompanyStore.getState().companies.map(c => c.id))
console.log('Current ID:', useCompanyStore.getState().currentCompanyId)
```

### Issue: Currency displays wrong for selected company

**Check:**
1. Verify component uses `useCompany()` not `useCompanyConfig()`
2. Check `currentCompany.currency` not `config.currency`
3. Ensure `formatCurrency()` receives correct currency parameter
4. Verify company data has correct currency field from API

**Debug:**
```typescript
const { currentCompany } = useCompany()
console.log('Selected company currency:', currentCompany?.currency)
console.log('Full company object:', currentCompany)
```

### Issue: "Cannot read properties of null" errors

**Cause:** Component trying to use `currentCompany` before selection is loaded

**Fix:** Add null checks or loading state:
```typescript
const { currentCompany } = useCompany()

if (!currentCompany) {
  return <LoadingSpinner />  // or fallback UI
}

return <div>{formatCurrency(amount, currentCompany.currency, 'en')}</div>
```

### Issue: localStorage quota exceeded

**Cause:** Browser storage limit reached (usually 5-10MB)

**Impact:** Company selection won't persist

**Fix:** Application should handle gracefully (already implemented):
```typescript
try {
  localStorage.setItem(MANUAL_STORAGE_KEY, companyId)
} catch (error) {
  console.error('[CompanyStore] Failed to persist company selection:', error)
  // State still updates, only persistence fails
}
```

---

## Migration Guide

### Updating Components to Use Selected Company

**Before (Incorrect):**
```typescript
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'

function Component() {
  const { config } = useCompanyConfig()

  return <div>{config?.currency}</div>  // ❌ Wrong - uses config currency
}
```

**After (Correct):**
```typescript
import { useCompany } from '@/hooks/useCompany'

function Component() {
  const { currentCompany } = useCompany()

  if (!currentCompany) return null

  return <div>{currentCompany.currency}</div>  // ✅ Right - uses selected company
}
```

### Adding Company Selector UI

```typescript
import { useCompany } from '@/hooks/useCompany'
import { useCompanyStore } from '@/stores/companyStore'

function CompanySelector() {
  const { currentCompanyId } = useCompany()
  const { companies, setCurrentCompany } = useCompanyStore()

  return (
    <select
      value={currentCompanyId || ''}
      onChange={(e) => setCurrentCompany(e.target.value)}
    >
      {companies.map((company) => (
        <option key={company.id} value={company.id}>
          {company.name}
        </option>
      ))}
    </select>
  )
}
```

---

## Related Documentation

- **Backend:** `docs/architecture/multi-tenancy.md` - Multi-tenant database architecture
- **Backend:** `docs/api/company-config.md` - Company Config API endpoint
- **Frontend:** `docs/architecture/frontend-contexts.md` - React Context hierarchy
- **Frontend:** `docs/FRONTEND.md` - React patterns and state management
- **Conventions:** `docs/conventions/05-REACT-QUERY.md` - React Query patterns

---

## Files

### Implementation
- `apps/web/src/stores/companyStore.ts` (137 lines)
- `apps/web/src/features/company/CompanyProvider.tsx` (143 lines)
- `apps/web/src/hooks/useCompany.ts` (15 lines)
- `apps/web/src/lib/formatCurrency.ts` (75 lines)
- `apps/web/src/features/finance/components/AccountTreeView.tsx` (109 lines)

### Tests
- `apps/web/src/stores/__tests__/companyStore.test.ts` (420+ lines, 30+ tests)

### Documentation
- `docs/architecture/company-selection.md` (this document)
- `docs/architecture/frontend-contexts.md` (related context documentation)

---

## Quality Metrics

**Test Coverage:**
- Unit Tests: 30+ scenarios
- Regression Tests: 5 critical user journeys
- Edge Cases: 5+ scenarios
- All Tests: PASSING ✅

**Production Readiness:**
- ✅ Race conditions resolved
- ✅ Persistence working reliably
- ✅ Error handling in place
- ✅ Console logging for debugging
- ✅ Performance optimized
- ✅ TypeScript strict mode
- ✅ Comprehensive tests
- ✅ Documentation complete

**Code Quality:**
- TypeScript: Strict mode, no `any`
- React Patterns: Hooks, functional components
- State Management: Zustand best practices
- Error Handling: Try-catch with logging
- Comments: Inline for complex logic

---

*Document Version: 1.0*
*Last Updated: 2026-01-02*
*Status: Production Ready*
