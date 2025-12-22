import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import { Sidebar } from '../../organisms/Sidebar'
import { TopBar } from '../../organisms/TopBar'
import { Breadcrumb } from '../../molecules/Breadcrumb'
import { EmailVerificationBanner } from '../../organisms/EmailVerificationBanner'

export function DashboardLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="flex h-screen bg-gray-50">
      <Sidebar isOpen={sidebarOpen} onClose={() => { setSidebarOpen(false) }} />
      <div className="flex flex-1 flex-col overflow-hidden lg:ps-0">
        <EmailVerificationBanner />
        <TopBar onMenuClick={() => { setSidebarOpen(true) }} />
        <main className="flex-1 overflow-y-auto p-4 sm:p-6">
          <Breadcrumb />
          <Outlet />
        </main>
      </div>
    </div>
  )
}

// Re-export as Layout for backwards compatibility
export { DashboardLayout as Layout }
