import { type ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { useCompanyConfig } from '../../contexts'

interface ModuleGuardProps {
  /**
   * Module name to check (e.g., "Vehicle", "Workshop", "Sales")
   * Must match the backend module name from the vertical configuration.
   */
  module: string

  /**
   * Path to redirect to if module is not enabled
   * @default "/dashboard"
   */
  fallback?: string | undefined

  /**
   * Content to render if module is enabled
   */
  children: ReactNode
}

/**
 * Route guard that protects routes based on vertical configuration
 *
 * Only renders children if the specified module is enabled for the current
 * company's vertical. Otherwise, redirects to the fallback route.
 *
 * This provides vertical-level protection. For permission-level protection,
 * wrap with RequirePermission component.
 *
 * @example
 * ```tsx
 * <ModuleGuard module="Vehicle">
 *   <RequirePermission moduleKey="vehicles">
 *     <VehicleListPage />
 *   </RequirePermission>
 * </ModuleGuard>
 * ```
 *
 * @example Custom fallback
 * ```tsx
 * <ModuleGuard module="Workshop" fallback="/">
 *   <WorkshopPage />
 * </ModuleGuard>
 * ```
 */
export function ModuleGuard({ module, fallback = '/dashboard', children }: ModuleGuardProps) {
  const { config, isLoading, error, hasModule } = useCompanyConfig()

  // Hold (render nothing) while the company config has not resolved yet.
  // This covers both the in-flight fetch (`isLoading`) AND the cold-load
  // window where the config query is DISABLED because `isAuthenticated` has
  // not rehydrated yet (`isAuthenticated` is intentionally not persisted).
  // A disabled query is `isLoading === false` with `config === null` and no
  // error — treating that as "module absent" wrongly bounced valid, enabled
  // modules (Batches / Parapharmacy / Loyalty …) to the fallback on direct
  // URL loads. Only decide once we have a definitive answer.
  if (isLoading || (!config && !error)) {
    return null
  }

  // Genuine config fetch failure → redirect to fallback (safe default).
  if (error || !config) {
    return <Navigate to={fallback} replace />
  }

  // Config is resolved — enforce the module gate for this vertical.
  if (!hasModule(module)) {
    return <Navigate to={fallback} replace />
  }

  // Module is enabled, render children
  return <>{children}</>
}
