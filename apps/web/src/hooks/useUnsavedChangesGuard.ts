import { useEffect } from 'react'

export interface DirtyState {
  isDirty: boolean
  autosavePending?: boolean
  autosaveFailed?: boolean
}

/** BrowserRouter-compatible guard: warns on browser unload (tab close /
 *  refresh) while there are unsaved or un-persisted changes. In-app Cancel/
 *  Back uses confirmDiscard(). Full in-app route blocking (useBlocker) is
 *  deferred — it needs a data-router migration. */
export function useUnsavedChangesGuard(dirty: DirtyState): void {
  const shouldWarn = dirty.isDirty || !!dirty.autosavePending || !!dirty.autosaveFailed
  useEffect(() => {
    if (!shouldWarn) return
    const handler = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = '' }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [shouldWarn])
}

export function confirmDiscard(message: string): boolean {
  return window.confirm(message)
}
