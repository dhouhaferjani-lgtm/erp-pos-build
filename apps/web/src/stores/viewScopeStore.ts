import { create } from 'zustand'
import { useCompanyStore } from './companyStore'

export type ViewScope = 'all' | string[]

interface ViewScopeState {
  scope: ViewScope
  setScope: (scope: ViewScope) => void
  /** Internal hydration (company-change / cross-tab); not for component use. */
  _hydrate: (scope: ViewScope) => void
}

const keyFor = (companyId: string): string => `autoerp-view-scope:${companyId}`

/**
 * Parse a payload into a ViewScope, or return null when it is absent,
 * malformed, or has an invalid shape. A valid payload is exactly the literal
 * "all" or an array containing only strings.
 */
export function tryParseScope(raw: string | null): ViewScope | null {
  if (raw === null || raw === '') return null

  try {
    const parsed = JSON.parse(raw) as unknown
    if (parsed === 'all') return 'all'
    if (Array.isArray(parsed) && parsed.every((item): item is string => typeof item === 'string')) {
      return parsed
    }
    return null
  } catch {
    return null
  }
}

/** Initial-load parse: fall back to all when no valid persisted scope exists. */
export function parseScope(raw: string | null): ViewScope {
  return tryParseScope(raw) ?? 'all'
}

function readPersistedScope(companyId: string): ViewScope {
  try {
    return parseScope(localStorage.getItem(keyFor(companyId)))
  } catch {
    return 'all'
  }
}

function persistScope(companyId: string, scope: ViewScope): void {
  try {
    localStorage.setItem(keyFor(companyId), JSON.stringify(scope))
  } catch {
    // Ignore persistence failures (quota/private mode); in-memory state still updates.
  }
}

export const useViewScopeStore = create<ViewScopeState>()((set) => ({
  scope: 'all',
  setScope: (scope) => {
    const companyId = useCompanyStore.getState().currentCompanyId
    if (companyId) persistScope(companyId, scope)
    set({ scope })
  },
  _hydrate: (scope) => {
    set({ scope })
  },
}))

// Module-scope company-change and cross-tab wiring mirrors the location
// provider's previous-company guard and location-store storage listener.
if (typeof window !== 'undefined') {
  let previousCompanyId: string | null = null

  const syncForCompany = (companyId: string | null): void => {
    if (companyId === null) {
      previousCompanyId = null
      return
    }

    if (previousCompanyId === null) {
      // First load / refresh: honor this company's persisted scope.
      useViewScopeStore.getState()._hydrate(readPersistedScope(companyId))
    } else if (previousCompanyId !== companyId) {
      // Real company change: reset to all; never carry another company's subset.
      useViewScopeStore.getState()._hydrate('all')
    }

    previousCompanyId = companyId
  }

  syncForCompany(useCompanyStore.getState().currentCompanyId)
  useCompanyStore.subscribe((state) => {
    syncForCompany(state.currentCompanyId)
  })

  window.addEventListener('storage', (event: StorageEvent) => {
    const companyId = useCompanyStore.getState().currentCompanyId
    if (!companyId || event.key !== keyFor(companyId)) return
    if (event.newValue === null || event.newValue === '') return

    // Ignore present-but-malformed/invalid payloads so the current selection is
    // preserved. Only a valid all literal or string array is adopted.
    const next = tryParseScope(event.newValue)
    if (next !== null) {
      useViewScopeStore.getState()._hydrate(next)
    }
  })
}
