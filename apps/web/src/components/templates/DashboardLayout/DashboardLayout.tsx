import { useState, useEffect, useCallback } from 'react'
import { Outlet } from 'react-router-dom'
import { Sidebar } from '../../organisms/Sidebar'
import { TopBar } from '../../organisms/TopBar'
import { Breadcrumb } from '../../molecules/Breadcrumb'
import { EmailVerificationBanner } from '../../organisms/EmailVerificationBanner'
import { CommandPalette } from '../../organisms/CommandPalette/CommandPalette'
import { WebSocketReconnectProvider } from '../../../providers/WebSocketReconnectProvider'
import { useProductConfig } from '../../../contexts/ProductConfigContext'
import { useImportProgress } from '../../../features/import/hooks/useImportProgress'
import { GlobalImportProgress } from '../../organisms/GlobalImportProgress/GlobalImportProgress'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

function ImportProgressSubscriber() {
  useImportProgress()
  return <GlobalImportProgress />
}

export function DashboardLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [commandPaletteOpen, setCommandPaletteOpen] = useState(false)
  const { product } = useProductConfig()

  const openCommandPalette = useCallback(() => {
    setCommandPaletteOpen(true)
  }, [])

  // Global Cmd+K / Ctrl+K shortcut
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        setCommandPaletteOpen((prev) => !prev)
      }
    }
    document.addEventListener('keydown', handleKeyDown)
    return () => { document.removeEventListener('keydown', handleKeyDown) }
  }, [])

  return (
    <div className={`flex h-screen ${colorTokens.surface.page}`} data-product={product}>
      <Sidebar isOpen={sidebarOpen} onClose={() => { setSidebarOpen(false) }} />
      <div className="flex flex-1 flex-col overflow-hidden lg:ps-0">
        <EmailVerificationBanner />
        <TopBar onMenuClick={() => { setSidebarOpen(true) }} onSearchClick={openCommandPalette} />
        <CommandPalette isOpen={commandPaletteOpen} onClose={() => { setCommandPaletteOpen(false) }} />
        <WebSocketReconnectProvider>
          <ImportProgressSubscriber />
          {/*
            `relative` is load-bearing: it makes <main> the containing block for
            absolutely-positioned descendants (e.g. Tailwind `sr-only` spans). Without
            it, `overflow-y-auto` does NOT clip those escapees — they anchor to the
            viewport and inflate documentElement.scrollHeight, producing a phantom
            whole-page scroll / dead white space below the content that grows with row
            count. See the products list at ?per_page=50/100.
          */}
          <main className="relative flex flex-1 flex-col overflow-y-auto p-4 sm:p-6">
            <Breadcrumb />
            <div className="flex flex-1 flex-col">
              <Outlet />
            </div>
          </main>
        </WebSocketReconnectProvider>
      </div>
    </div>
  )
}

// Re-export as Layout for backwards compatibility
export { DashboardLayout as Layout }
