import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bell, Search, User, LogOut, Settings, Menu, Globe } from 'lucide-react'
import { useAuthStore } from '../../../stores/authStore'
import { useLogout } from '../../../features/auth'
import { languages } from '../../../lib/i18n'
import { CompanySelector } from '../CompanySelector'
import { LocationSelector } from '../LocationSelector'
import { ConnectionStatusIndicator } from '../../molecules/ConnectionStatusIndicator'
import { QuickCreateButton } from './QuickCreateButton'

interface TopBarProps {
  onMenuClick?: () => void
  onSearchClick?: () => void
}

export function TopBar({ onMenuClick, onSearchClick }: TopBarProps) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const user = useAuthStore((state) => state.user)
  const logout = useLogout()
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isLangMenuOpen, setIsLangMenuOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const langMenuRef = useRef<HTMLDivElement>(null)

  // Close menus when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setIsMenuOpen(false)
      }
      if (langMenuRef.current && !langMenuRef.current.contains(event.target as Node)) {
        setIsLangMenuOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => { document.removeEventListener('mousedown', handleClickOutside) }
  }, [])

  const handleLogout = () => {
    setIsMenuOpen(false)
    void logout()
  }

  const handleSettingsClick = (): void => {
    setIsMenuOpen(false)
    void navigate('/settings')
  }

  const handleLanguageChange = (langCode: string) => {
    void i18n.changeLanguage(langCode)
    setIsLangMenuOpen(false)
  }

  const currentLang = languages.find((l) => l.code === i18n.language) ?? languages[0]

  return (
    <header className="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 sm:px-6">
      {/* Left side - Menu button and Search */}
      <div className="flex items-center gap-4">
        {/* Mobile menu button */}
        {onMenuClick && (
          <button
            type="button"
            onClick={onMenuClick}
            className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 lg:hidden"
            aria-label={t('actions.open')}
          >
            <Menu className="h-5 w-5" />
          </button>
        )}

        {/* Search trigger */}
        <button
          type="button"
          onClick={onSearchClick}
          className="hidden w-96 items-center gap-2 rounded-lg border border-gray-300 bg-gray-50 py-2 ps-3 pe-4 text-sm text-gray-400 hover:border-gray-400 hover:bg-gray-100 sm:flex"
        >
          <Search className="h-4 w-4 shrink-0" />
          <span className="flex-1 text-start">{t('common:commandPalette.searchTrigger')}</span>
          <kbd className="rounded border border-gray-300 bg-white px-1.5 py-0.5 text-xs font-medium text-gray-500">
            ⌘K
          </kbd>
        </button>

        {/* Quick Create */}
        <QuickCreateButton />
      </div>

      {/* Right side actions */}
      <div className="flex items-center gap-2">
        {/* Company selector for multi-company users */}
        <CompanySelector />

        {/* Location selector for multi-location companies */}
        <LocationSelector className="hidden lg:block" />

        {/* Language selector */}
        <div className="relative" ref={langMenuRef}>
          <button
            type="button"
            onClick={() => { setIsLangMenuOpen(!isLangMenuOpen) }}
            className="flex items-center gap-1 rounded-lg p-2 text-gray-500 hover:bg-gray-100"
            aria-label={t('common:selectLanguage')}
            aria-expanded={isLangMenuOpen}
            aria-haspopup="true"
          >
            <Globe className="h-5 w-5" />
            <span className="hidden text-sm font-medium sm:inline">{currentLang.code.toUpperCase()}</span>
          </button>

          {/* Language dropdown */}
          {isLangMenuOpen && (
            <div className="absolute end-0 mt-2 w-40 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
              {languages.map((lang) => (
                <button
                  key={lang.code}
                  type="button"
                  onClick={() => { handleLanguageChange(lang.code) }}
                  className={`flex w-full items-center justify-between px-4 py-2 text-sm hover:bg-gray-100 ${
                    i18n.language === lang.code ? 'bg-blue-50 text-blue-700' : 'text-gray-700'
                  }`}
                >
                  <span>{lang.name}</span>
                  {i18n.language === lang.code && (
                    <span className="text-blue-600">✓</span>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>

        <ConnectionStatusIndicator />

        <button
          type="button"
          className="relative rounded-lg p-2 text-gray-500 hover:bg-gray-100"
          aria-label={t('common:notifications', { defaultValue: 'Notifications' })}
        >
          <Bell className="h-5 w-5" />
          <span className="absolute end-1 top-1 h-2 w-2 rounded-full bg-red-500" />
        </button>

        {/* User menu */}
        <div className="relative" ref={menuRef}>
          <button
            type="button"
            onClick={() => { setIsMenuOpen(!isMenuOpen) }}
            className="flex items-center gap-2 rounded-lg p-2 text-gray-700 hover:bg-gray-100"
            aria-label={t('auth:user.profile', { defaultValue: 'User menu' })}
            aria-expanded={isMenuOpen}
            aria-haspopup="true"
          >
            <User className="h-5 w-5" />
            <span className="text-sm font-medium">{user?.name ?? 'User'}</span>
          </button>

          {/* Dropdown menu */}
          {isMenuOpen && (
            <div className="absolute end-0 mt-2 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
              <button
                type="button"
                onClick={handleSettingsClick}
                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
              >
                <Settings className="h-4 w-4" />
                {t('auth:user.settings')}
              </button>
              <hr className="my-1 border-gray-200" />
              <button
                type="button"
                onClick={handleLogout}
                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-gray-100"
              >
                <LogOut className="h-4 w-4" />
                {t('auth:logout')}
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  )
}
