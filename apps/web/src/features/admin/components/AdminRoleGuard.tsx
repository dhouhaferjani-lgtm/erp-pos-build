import { Navigate, Outlet } from 'react-router-dom'
import { useAdminAuthStore, type AdminRole } from '../stores/adminAuthStore'
import { homeForAdminRole } from '../lib/adminRolePolicy'

export function AdminIndexRedirect() {
  const role = useAdminAuthStore((state) => state.admin?.role)
  return <Navigate to={role === undefined ? '/admin/login' : homeForAdminRole(role)} replace />
}

export function RequireAdminRole({ allow, children }: { allow: readonly AdminRole[]; children?: React.ReactNode }) {
  const role = useAdminAuthStore((state) => state.admin?.role)
  if (role === undefined) return <Navigate to="/admin/login" replace />
  if (!allow.includes(role)) return <Navigate to={homeForAdminRole(role)} replace />
  return children === undefined ? <Outlet /> : <>{children}</>
}
