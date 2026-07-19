import { create } from 'zustand'
import { useCompanyStore } from './companyStore'
import { useAuthStore } from './authStore'

export type ViewScope = 'all' | string[]

interface ViewScopeState {
  scope: ViewScope
  setScope: (scope: ViewScope) => void
  /** Internal hydration (company-change / cross-tab); not for component use. */
  _hydrate: (scope: ViewScope) => void
}

const keyFor = (companyId: string, userId: string | null): string => `autoerp-view-scope:${companyId}:${userId ?? 'anonymous'}`

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

function readPersistedScope(companyId: string, userId: string | null): ViewScope {
  try {
    return parseScope(localStorage.getItem(keyFor(companyId, userId)))
  } catch {
    return 'all'
  }
}

function persistScope(companyId: string, userId: string | null, scope: ViewScope): void {
  try {
    localStorage.setItem(keyFor(companyId, userId), JSON.stringify(scope))
  } catch {
    // Ignore persistence failures (quota/private mode); in-memory state still updates.
  }
}

export const useViewScopeStore = create<ViewScopeState>()((set) => ({
  scope: 'all',
  setScope: (scope) => {
    const companyId = useCompanyStore.getState().currentCompanyId
    const userId = useAuthStore.getState().user?.id ?? null
    if (companyId) persistScope(companyId, userId, scope)
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
  let previousUserId: string | null = null

  const syncForCompany = (companyId: string | null, userId: string | null): void => {
    if (companyId === null) {
      previousCompanyId = null
      previousUserId = null
      return
    }

    if (previousCompanyId === null) {
      // First load / refresh: honor this company's persisted scope.
      useViewScopeStore.getState()._hydrate(readPersistedScope(companyId, userId))
    } else if (previousCompanyId === companyId && previousUserId === null && userId !== null) {
      // Auth hydration can happen after the company store. Load the user's
      // persisted subset instead of resetting it to the company-wide default.
      useViewScopeStore.getState()._hydrate(readPersistedScope(companyId, userId))
    } else if (previousCompanyId !== companyId || previousUserId !== userId) {
      // Real company change: reset to all; never carry another company's subset.
      useViewScopeStore.getState()._hydrate('all')
    }

    previousCompanyId = companyId
    previousUserId = userId
  }

  syncForCompany(useCompanyStore.getState().currentCompanyId, useAuthStore.getState().user?.id ?? null)
  if (typeof useCompanyStore.subscribe === 'function') {
    useCompanyStore.subscribe((state) => {
      syncForCompany(state.currentCompanyId, useAuthStore.getState().user?.id ?? null)
    })
  }

  if (typeof useAuthStore.subscribe === 'function') {
    useAuthStore.subscribe((state) => {
      syncForCompany(useCompanyStore.getState().currentCompanyId, state.user?.id ?? null)
    })
  }

  window.addEventListener('storage', (event: StorageEvent) => {
    const companyId = useCompanyStore.getState().currentCompanyId
    const userId = useAuthStore.getState().user?.id ?? null
    if (!companyId || event.key !== keyFor(companyId, userId)) return
    if (event.newValue === null || event.newValue === '') return

    // Ignore present-but-malformed/invalid payloads so the current selection is
    // preserved. Only a valid all literal or string array is adopted.
    const next = tryParseScope(event.newValue)
    if (next !== null) {
      useViewScopeStore.getState()._hydrate(next)
    }
  })
}
