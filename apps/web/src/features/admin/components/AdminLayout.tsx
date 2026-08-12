import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { Shield, LayoutDashboard, Users, FileText, LogOut, CreditCard, Activity, UserCheck, Layers, Headphones, BookOpenCheck, ListTree } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { logoutSuperAdmin } from '../api'
import { useAdminAuthStore } from '../stores/adminAuthStore'
import { colorClasses } from '@/lib/designTokens'

import type { AdminRole } from '../stores/adminAuthStore'

const navigation: { labelKey: string; href: string; icon: typeof Shield; roles: readonly AdminRole[] }[] = [
  { labelKey: 'shell.navigation.dashboard', href: '/admin/dashboard', icon: LayoutDashboard, roles: ['super_admin'] },
  { labelKey: 'shell.navigation.tenants', href: '/admin/tenants', icon: Users, roles: ['super_admin'] },
  { labelKey: 'shell.navigation.verticals', href: '/admin/verticals', icon: Layers, roles: ['super_admin'] },
  { labelKey: 'shell.navigation.companyOwners', href: '/admin/company-owners', icon: UserCheck, roles: ['super_admin'] },
  { labelKey: 'shell.navigation.billing', href: '/admin/billing', icon: CreditCard, roles: ['super_admin'] },
  { labelKey: 'shell.navigation.monitoring', href: '/admin/monitoring', icon: Activity, roles: ['super_admin'] },
  { labelKey: 'shell.navigation.auditLogs', href: '/admin/audit-logs', icon: FileText, roles: ['super_admin'] },
  { labelKey: 'navigation.templates', href: '/admin/country-defaults', icon: BookOpenCheck, roles: ['super_admin', 'defaults_editor'] },
  { labelKey: 'navigation.assignments', href: '/admin/country-defaults/assignments', icon: ListTree, roles: ['super_admin', 'defaults_editor'] },
  { labelKey: 'shell.navigation.supportAccess', href: '/admin/support-access', icon: Headphones, roles: ['super_admin', 'support_approver'] },
]

export function AdminLayout() {
  const { t } = useTranslation('adminCountryDefaults')
  const location = useLocation()
  const navigate = useNavigate()
  const { admin, logout } = useAdminAuthStore()

  const handleLogout = async () => {
    try {
      await logoutSuperAdmin()
    } catch {
      // Token may already be expired/revoked — local logout regardless.
    }
    logout()
    void navigate('/admin/login')
  }

  return (
    <div className={`flex h-screen ${colorClasses.bgGray900}`}>
      {/* Sidebar */}
      <aside className={`w-64 ${colorClasses.bgGray800} border-r ${colorClasses.borderGray700}`}>
        {/* Logo */}
        <div className={`flex h-16 items-center gap-3 px-6 border-b ${colorClasses.borderGray700}`}>
          <div className={`flex h-10 w-10 items-center justify-center rounded-lg ${colorClasses.bgBlue600}`}>
            <Shield className="h-6 w-6 text-white" />
          </div>
          <div>
            <span className="text-lg font-bold text-white">{t('shell.brand')}</span>
            <p className={`text-xs ${colorClasses.textGray400}`}>{t('shell.panel')}</p>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 px-3 py-4">
          <ul className="space-y-1">
            {navigation.map((item) => {
              if (admin === null || !item.roles.includes(admin.role)) return null
              const isActive = location.pathname === item.href
              return (
                <li key={item.href}>
                  <Link
                    to={item.href}
                    className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                      isActive
                        ? `${colorClasses.bgBlue600} text-white`
                        : `${colorClasses.textGray300} ${colorClasses.hoverBgGray700} hover:text-white`
                    }`}
                  >
                    <item.icon className="h-5 w-5" />
                    {t(item.labelKey)}
                  </Link>
                </li>
              )
            })}
          </ul>
        </nav>

        {/* User info and logout */}
        <div className={`border-t ${colorClasses.borderGray700} p-4`}>
          <div className="flex items-center gap-3 mb-3">
            <div className={`flex h-10 w-10 items-center justify-center rounded-full ${colorClasses.bgGray600}`}>
              <span className="text-sm font-medium text-white">
                {admin?.name?.charAt(0).toUpperCase() ?? 'A'}
              </span>
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-white truncate">
                {admin?.name ?? t('shell.adminFallback')}
              </p>
              <p className={`text-xs ${colorClasses.textGray400} truncate`}>
                {admin?.email ?? ''}
              </p>
            </div>
          </div>
          <button
            onClick={() => { void handleLogout() }}
            className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ${colorClasses.textGray300} ${colorClasses.hoverBgGray700} hover:text-white transition-colors`}
          >
            <LogOut className="h-4 w-4" />
            {t('shell.signOut')}
          </button>
        </div>
      </aside>

      {/* Main content */}
      {/* `relative` makes <main> the containing block for absolutely-positioned
          descendants so `overflow-y-auto` clips them instead of letting them inflate
          document height into a phantom page scroll. See DashboardLayout for details. */}
      <main className={`relative flex-1 overflow-y-auto ${colorClasses.bgGray50}`}>
        <Outlet />
      </main>
    </div>
  )
}
