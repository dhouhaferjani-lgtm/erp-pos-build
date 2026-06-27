import { useMemo } from 'react'
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
 *  mutation success side effects (invalidation, uploads, toasts) complete. */
export function useAfterSaveNavigation(config: AfterSaveNavConfig): AfterSaveNav {
  const navigate = useNavigate()
  return useMemo<AfterSaveNav>(() => ({
    goToRecord: (id) => { void navigate(config.recordPath(id)) },
    goToNew: () => { void navigate(config.createPath ?? config.listPath) },
    goToList: () => { void navigate(config.listPath) },
  }), [navigate, config])
}
