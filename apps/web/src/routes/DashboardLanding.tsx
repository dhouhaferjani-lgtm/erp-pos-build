import { Navigate } from 'react-router-dom'
import { usePermissions } from '@/hooks/usePermissions'

/**
 * Root landing redirect.
 *
 * Owners (dashboard.owner) land on the owner reporting dashboard (/reports),
 * which reflects POS sales correctly. Everyone else lands on the generic
 * dashboard (/dashboard). This avoids showing a POS-only owner the generic
 * dashboard whose "revenue" card sums B2B invoices only and reads ~0 for a
 * pure-POS tenant (audit F-3). Uses the SAME permission check that gates the
 * /reports route, so an owner is only redirected there when they can access it.
 */
export function DashboardLanding() {
  const { hasPermission } = usePermissions()

  return <Navigate to={hasPermission('dashboard.owner') ? '/reports' : '/dashboard'} replace />
}
