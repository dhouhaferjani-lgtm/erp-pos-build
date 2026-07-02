import { create } from 'zustand'
import { persist } from 'zustand/middleware'

/**
 * Company type for multi-company support
 */
export interface Company {
  id: string
  name: string
  legalName: string
  taxId: string | null
  countryCode: string
  currency: string
  locale: string
  timezone: string
  /**
   * Whether this is the user's primary company membership.
   * Used as the first tiebreak when auto-selecting a company (see
   * {@link resolveCompanySelection}). Optional so older cached payloads that
   * predate the field don't break typing.
   */
  isPrimary?: boolean
}

/** Origin-wide key holding the user's explicit company selection. */
const COMPANY_SELECTION_KEY = 'autoerp-company-selection'

/** Read the persisted company selection, tolerating storage errors. */
function readPersistedCompanyId(): string | null {
  try {
    return localStorage.getItem(COMPANY_SELECTION_KEY)
  } catch {
    return null
  }
}

/** Persist the company selection, tolerating storage errors. */
function persistCompanyId(companyId: string): void {
  try {
    localStorage.setItem(COMPANY_SELECTION_KEY, companyId)
  } catch {
    // Ignore persistence failures (quota/private mode) — in-memory state still updates.
  }
}

/**
 * Deterministic company auto-select rule — the single source of truth for
 * "which company is active".
 *
 * Order:
 *  1. Keep the current in-memory selection if it still exists in the list.
 *  2. Restore the user's last explicit selection from localStorage if valid.
 *     (Persisted intent wins over defaults so the scope never silently jumps
 *     back to the primary company on every refresh.)
 *  3. Prefer the primary membership (`isPrimary`) — the genuine first-run default.
 *  4. Otherwise the first company (server-ordered by name → stable).
 *
 * An EMPTY list is treated as "not loaded / transient" and never clears a
 * selection — this stops a partial/transient fetch from silently switching
 * scope. A genuinely empty membership set resolves to null via step 1.
 */
export function resolveCompanySelection(
  companies: Company[],
  currentId: string | null,
): string | null {
  if (companies.length === 0) {
    return currentId
  }
  if (currentId && companies.some((c) => c.id === currentId)) {
    return currentId
  }
  const persisted = readPersistedCompanyId()
  if (persisted && companies.some((c) => c.id === persisted)) {
    return persisted
  }
  const primary = companies.find((c) => c.isPrimary)
  if (primary) {
    return primary.id
  }
  return companies[0].id
}

/**
 * Company state interface
 */
interface CompanyState {
  currentCompanyId: string | null
  companies: Company[]
  isLoading: boolean
}

/**
 * Company actions interface
 */
interface CompanyActions {
  setCompanies: (companies: Company[]) => void
  setCurrentCompany: (companyId: string) => void
  setLoading: (loading: boolean) => void
  getCurrentCompany: () => Company | null
  reset: () => void
}

/**
 * Company store type
 */
type CompanyStore = CompanyState & CompanyActions

/**
 * Initial state
 */
const initialState: CompanyState = {
  currentCompanyId: null,
  companies: [],
  isLoading: true,
}

/**
 * Company store with persistence
 *
 * Stores the current company selection for multi-company users.
 * Company list is fetched from the server.
 */
export const useCompanyStore = create<CompanyStore>()(
  persist(
    (set, get) => ({
      ...initialState,

      setCompanies: (companies) => {
        set((state) => ({
          ...state,
          companies,
          // Centralized, deterministic selection — never an arbitrary pick and
          // never a silent clear on a transient/partial fetch.
          currentCompanyId: resolveCompanySelection(companies, state.currentCompanyId),
          isLoading: false,
        }))
        // Persist the resolved selection so a refresh restores exactly this
        // company (including a first-run auto-select), keeping the scope stable.
        const resolved = get().currentCompanyId
        if (resolved) {
          persistCompanyId(resolved)
        }
      },

      setCurrentCompany: (companyId) => {
        const { companies } = get()
        // Validate that the company exists in the list
        if (companies.find((c) => c.id === companyId)) {
          set({ currentCompanyId: companyId })
          // Manually persist to separate key to avoid Zustand persist middleware conflicts
          persistCompanyId(companyId)
        }
      },

      setLoading: (isLoading) => set({ isLoading }),

      getCurrentCompany: () => {
        const { currentCompanyId, companies } = get()
        if (!currentCompanyId) return null
        return companies.find((c) => c.id === currentCompanyId) ?? null
      },

      reset: () => {
        set(initialState)
        try {
          localStorage.removeItem(COMPANY_SELECTION_KEY)
        } catch {
          // Ignore storage errors on reset.
        }
      },
    }),
    {
      name: 'autoerp-company',
      // Don't persist anything automatically - we handle currentCompanyId manually
      partialize: () => ({}),
    }
  )
)

/**
 * Cross-tab reconciliation.
 *
 * The company selection is persisted manually under an origin-wide key, so a
 * switch in one tab is invisible to others until reload — at which point the two
 * tabs' in-memory stores diverge and one silently stomps the other. Listening
 * for the `storage` event (which only fires in OTHER tabs) keeps every tab's
 * in-memory selection consistent immediately. A visible notice is surfaced by
 * `useScopeChangeNotice`, which observes the resulting store change.
 */
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event: StorageEvent) => {
    if (event.key !== COMPANY_SELECTION_KEY) {
      return
    }
    const { currentCompanyId, companies } = useCompanyStore.getState()
    const nextId = event.newValue

    if (nextId) {
      // Adopt the new selection if it's known, or if companies aren't loaded yet
      // (it will be validated on the next fetch via resolveCompanySelection).
      const isKnown = companies.length === 0 || companies.some((c) => c.id === nextId)
      if (isKnown && nextId !== currentCompanyId) {
        useCompanyStore.setState({ currentCompanyId: nextId })
      }
    } else if (currentCompanyId) {
      // Key cleared in another tab (e.g. logout) — clear here too.
      useCompanyStore.setState({ currentCompanyId: null })
    }
  })
}
