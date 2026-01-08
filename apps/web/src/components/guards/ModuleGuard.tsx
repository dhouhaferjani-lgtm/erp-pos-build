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
  fallback?: string

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

  // While loading, don't render anything (prevent flash of wrong content)
  if (isLoading) {
    return null
  }

  // On error, redirect to fallback (safe default)
  if (error || !config) {
    return <Navigate to={fallback} replace />
  }

  // Check if the module is enabled for this vertical
  if (!hasModule(module)) {
    return <Navigate to={fallback} replace />
  }

  // Module is enabled, render children
  return <>{children}</>
}
