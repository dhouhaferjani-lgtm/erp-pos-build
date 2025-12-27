import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { Shield, LayoutDashboard, Users, FileText, LogOut, CreditCard, Activity, UserCheck } from 'lucide-react'
import { useAdminAuthStore } from '../stores/adminAuthStore'

const navigation = [
  { name: 'Dashboard', href: '/admin/dashboard', icon: LayoutDashboard },
  { name: 'Tenants', href: '/admin/tenants', icon: Users },
  { name: 'Company Owners', href: '/admin/company-owners', icon: UserCheck },
  { name: 'Billing', href: '/admin/billing', icon: CreditCard },
  { name: 'Monitoring', href: '/admin/monitoring', icon: Activity },
  { name: 'Audit Logs', href: '/admin/audit-logs', icon: FileText },
]

export function AdminLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const { admin, logout } = useAdminAuthStore()

  const handleLogout = () => {
    logout()
    navigate('/admin/login')
  }

  return (
    <div className="flex h-screen bg-gray-900">
      {/* Sidebar */}
      <aside className="w-64 bg-gray-800 border-r border-gray-700">
        {/* Logo */}
        <div className="flex h-16 items-center gap-3 px-6 border-b border-gray-700">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600">
            <Shield className="h-6 w-6 text-white" />
          </div>
          <div>
            <span className="text-lg font-bold text-white">Admin</span>
            <p className="text-xs text-gray-400">Super Admin Panel</p>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 px-3 py-4">
          <ul className="space-y-1">
            {navigation.map((item) => {
              const isActive = location.pathname === item.href
              return (
                <li key={item.name}>
                  <Link
                    to={item.href}
                    className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                      isActive
                        ? 'bg-blue-600 text-white'
                        : 'text-gray-300 hover:bg-gray-700 hover:text-white'
                    }`}
                  >
                    <item.icon className="h-5 w-5" />
                    {item.name}
                  </Link>
                </li>
              )
            })}
          </ul>
        </nav>

        {/* User info and logout */}
        <div className="border-t border-gray-700 p-4">
          <div className="flex items-center gap-3 mb-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gray-600">
              <span className="text-sm font-medium text-white">
                {admin?.name?.charAt(0).toUpperCase() ?? 'A'}
              </span>
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-white truncate">
                {admin?.name ?? 'Admin'}
              </p>
              <p className="text-xs text-gray-400 truncate">
                {admin?.email ?? ''}
              </p>
            </div>
          </div>
          <button
            onClick={handleLogout}
            className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-300 hover:bg-gray-700 hover:text-white transition-colors"
          >
            <LogOut className="h-4 w-4" />
            Sign out
          </button>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 overflow-y-auto bg-gray-50">
        <Outlet />
      </main>
    </div>
  )
}
