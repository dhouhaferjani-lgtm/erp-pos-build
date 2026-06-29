import { useMemo, useRef } from 'react'
import { useNavigate } from 'react-router-dom'

export interface AfterSaveNavConfig {
  /** Detail route for a record id, e.g. (id) => `/inventory/products/${id}` */
  recordPath: (id: string) => string
  /** Parent list route, e.g. `/inventory/products` */
  listPath: string
  /** Create route for Save & New; falls back to listPath when omitted */
  createPath?: string
}

export interface AfterSaveNav {
  goToRecord: (id: string) => void
  goToNew: () => void
  goToList: () => void
}

/** Canonical post-save navigation. Editors call these AFTER their own
 *  mutation success side effects (invalidation, uploads, toasts) complete.
 *  Callbacks are stable across renders (config is read via a ref), so they
 *  are safe to use in effect dependency arrays. */
export function useAfterSaveNavigation(config: AfterSaveNavConfig): AfterSaveNav {
  const navigate = useNavigate()
  const configRef = useRef(config)
  configRef.current = config
  return useMemo<AfterSaveNav>(() => ({
    goToRecord: (id) => { void navigate(configRef.current.recordPath(id)) },
    goToNew: () => { void navigate(configRef.current.createPath ?? configRef.current.listPath) },
    goToList: () => { void navigate(configRef.current.listPath) },
  }), [navigate])
}
