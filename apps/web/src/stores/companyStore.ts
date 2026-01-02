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
        set((state) => {
          // Use the currentCompanyId from state (may be restored by persist middleware)
          let currentCompanyId = state.currentCompanyId

          // If currentCompanyId is null, try to restore from localStorage synchronously
          // Use a separate key from Zustand persist to avoid conflicts
          if (!currentCompanyId) {
            try {
              const stored = localStorage.getItem('autoerp-company-selection')
              if (stored) {
                currentCompanyId = stored
                console.log('[CompanyStore] Restored currentCompanyId from localStorage:', currentCompanyId)
              }
            } catch (error) {
              console.error('[CompanyStore] Failed to restore from localStorage:', error)
            }
          }

          // Validate that the restored/existing company still exists
          if (currentCompanyId) {
            const companyStillExists = companies.find((c) => c.id === currentCompanyId)
            if (!companyStillExists) {
              // Previous company no longer exists, clear selection
              console.log('[CompanyStore] Previously selected company no longer exists, clearing')
              currentCompanyId = null
            } else {
              console.log('[CompanyStore] Preserving company selection:', currentCompanyId)
            }
          }

          return {
            ...state,
            companies,
            currentCompanyId,
            isLoading: false,
          }
        })
      },

      setCurrentCompany: (companyId) => {
        const { companies } = get()
        // Validate that the company exists in the list
        if (companies.find((c) => c.id === companyId)) {
          set({ currentCompanyId: companyId })
          // Manually persist to separate key to avoid Zustand persist middleware conflicts
          try {
            localStorage.setItem('autoerp-company-selection', companyId)
            console.log('[CompanyStore] Persisted company selection:', companyId)
          } catch (error) {
            console.error('[CompanyStore] Failed to persist company selection:', error)
          }
        }
      },

      setLoading: (isLoading) => set({ isLoading }),

      getCurrentCompany: () => {
        const { currentCompanyId, companies } = get()
        if (!currentCompanyId) return null
        return companies.find((c) => c.id === currentCompanyId) ?? null
      },

      reset: () => set(initialState),
    }),
    {
      name: 'autoerp-company',
      // Don't persist anything automatically - we handle currentCompanyId manually
      partialize: () => ({}),
    }
  )
)
