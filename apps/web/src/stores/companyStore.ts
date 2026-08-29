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
 * Companies the API has explicitly denied during THIS browser session
 * (403 `COMPANY_ACCESS_DENIED` / 400 `INVALID_COMPANY_ID`).
 *
 * W2-1 gate r1, F-2: "the reset cannot loop because the selection becomes null"
 * is only half the cycle. CompanyProvider re-bootstraps immediately on the
 * re-keyed query and {@link resolveCompanySelection} deterministically re-picks
 * `isPrimary` / `companies[0]` — so if the server denies THAT company, the next
 * 403 resets again, forever. Remembering the denial makes loop-freedom an
 * enforced invariant instead of a comment.
 *
 * Deliberately module-level and NOT part of the persisted store state: a denial
 * is a fact about the current session, not a durable user preference, and a
 * reload should re-ask the server. Cleared on logout and on every new session
 * (see `clearAppState.ts`), and by an explicit user pick in `setCurrentCompany`.
 */
const deniedCompanyIds = new Set<string>()

/** Record that the API denied this company for the current user. */
export function markCompanyAccessDenied(companyId: string): void {
  deniedCompanyIds.add(companyId)
}

/** Forget every denial — a new session, or a logout. */
export function clearDeniedCompanyIds(): void {
  deniedCompanyIds.clear()
}

/** Whether the API has denied this company during this session. */
export function isCompanyAccessDenied(companyId: string): boolean {
  return deniedCompanyIds.has(companyId)
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
  // A company the API has already denied is never a candidate, however it ranks
  // (F-2). The server lists memberships; the middleware decides access; when the
  // two disagree, re-picking a denied company loops reset -> bootstrap -> 403.
  const selectable = companies.filter((c) => !deniedCompanyIds.has(c.id))
  if (selectable.length === 0) {
    return null
  }
  if (currentId && selectable.some((c) => c.id === currentId)) {
    return currentId
  }
  const persisted = readPersistedCompanyId()
  if (persisted && selectable.some((c) => c.id === persisted)) {
    return persisted
  }
  const primary = selectable.find((c) => c.isPrimary)
  if (primary) {
    return primary.id
  }
  return selectable[0].id
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
  adoptCreatedCompany: (company: Company) => void
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
          // An explicit user pick outranks a remembered denial: the user asked
          // for this company. If the server still denies it they get exactly one
          // more 403 and it is re-denied — a user action, never a loop.
          deniedCompanyIds.delete(companyId)
          set({ currentCompanyId: companyId })
          // Manually persist to separate key to avoid Zustand persist middleware conflicts
          persistCompanyId(companyId)
        }
      },

      adoptCreatedCompany: (company) => {
        deniedCompanyIds.delete(company.id)
        set((state) => ({
          companies: state.companies.some((existing) => existing.id === company.id)
            ? state.companies
            : [...state.companies, company],
          currentCompanyId: company.id,
        }))
        persistCompanyId(company.id)
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
