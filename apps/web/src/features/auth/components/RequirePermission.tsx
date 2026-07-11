import { Navigate, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ShieldX } from 'lucide-react'
import { usePermissions, type Permission } from '../../../hooks/usePermissions'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface RequirePermissionProps {
  permission?: Permission
  permissions?: Permission[]
  requireAll?: boolean
  moduleKey?: string
  children: React.ReactNode
  fallback?: React.ReactNode
}

function PermissionDenied() {
  const { t } = useTranslation()

  return (
    <div className="flex min-h-[400px] flex-col items-center justify-center p-8 text-center">
      <div className={`rounded-full ${colorTokens.intent.danger.bgSoft} p-4 mb-4`}>
        <ShieldX className={`h-12 w-12 ${colorTokens.intent.danger.text}`} />
      </div>
      <h2 className={`text-xl font-semibold ${colorTokens.text.primary} mb-2`}>
        {t('errors.permissionDenied', 'Permission Denied')}
      </h2>
      <p className={`${colorTokens.text.muted} max-w-md`}>
        {t(
          'errors.permissionDeniedDescription',
          "You don't have permission to access this page. Please contact your administrator if you believe this is an error."
        )}
      </p>
    </div>
  )
}

export function RequirePermission({
  permission,
  permissions,
  requireAll = false,
  moduleKey,
  children,
  fallback,
}: RequirePermissionProps) {
  const location = useLocation()
  const { hasPermission, hasAnyPermission, hasAllPermissions, canAccessModule } = usePermissions()

  let hasAccess = true

  // Check module-level access
  if (moduleKey) {
    hasAccess = canAccessModule(moduleKey)
  }

  // Check specific permission
  if (hasAccess && permission) {
    hasAccess = hasPermission(permission)
  }

  // Check multiple permissions
  if (hasAccess && permissions && permissions.length > 0) {
    hasAccess = requireAll ? hasAllPermissions(permissions) : hasAnyPermission(permissions)
  }

  if (!hasAccess) {
    // If a fallback is provided, render it
    if (fallback) {
      return <>{fallback}</>
    }

    // For route protection, we can either redirect or show permission denied
    // Check if this is a navigation to a protected route (not already on dashboard)
    if (location.pathname !== '/dashboard') {
      // Redirect to dashboard for unauthorized route access
      return <Navigate to="/dashboard" replace state={{ permissionDenied: true, from: location }} />
    }

    // Show permission denied component
    return <PermissionDenied />
  }

  return <>{children}</>
}

export { PermissionDenied }
