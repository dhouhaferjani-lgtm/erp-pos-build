import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Search, User, LogOut, Settings, Menu, Globe } from 'lucide-react'
import { useAuthStore } from '../../../stores/authStore'
import { useLogout } from '../../../features/auth/useLogout'
import { languages } from '../../../lib/i18n'
import { CompanySelector } from '../CompanySelector'
import { ViewScopePicker } from '../ViewScopePicker'
import { ConnectionStatusIndicator } from '../../molecules/ConnectionStatusIndicator'
import { QuickCreateButton } from './QuickCreateButton'
import { useScopeChangeNotice } from '../../../hooks/useScopeChangeNotice'
import { borderColors, colors } from '../../../lib/designTokens'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { NotificationBell } from '../../../features/notifications/components/NotificationBell'

interface TopBarProps {
  onMenuClick?: () => void
  onSearchClick?: () => void
}

export function TopBar({ onMenuClick, onSearchClick }: TopBarProps) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const user = useAuthStore((state) => state.user)
  const logout = useLogout()

  // Announce any active-scope change (company/location) with a visible toast.
  useScopeChangeNotice()
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
    <header className={`flex h-16 items-center justify-between border-b ${colorTokens.border.subtle} ${colorTokens.surface.base} px-4 sm:px-6`}>
      {/* Left side - Menu button and Search */}
      <div className="flex items-center gap-4">
        {/* Mobile menu button */}
        {onMenuClick && (
          <button
            type="button"
            onClick={onMenuClick}
            className={`rounded-lg p-2 ${colorTokens.text.subtle} ${colorTokens.variants.hoverBgGray100} lg:hidden`}
            aria-label={t('actions.open')}
          >
            <Menu className="h-5 w-5" />
          </button>
        )}

        {/* Search trigger */}
        <button
          type="button"
          onClick={onSearchClick}
          className={`hidden w-96 items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.page} py-2 ps-3 pe-4 text-sm ${colorTokens.text.disabled} ${colorTokens.border.hoverStrong} ${colorTokens.variants.hoverBgGray100} sm:flex`}
        >
          <Search className="h-4 w-4 shrink-0" />
          <span className="flex-1 text-start">{t('common:commandPalette.searchTrigger')}</span>
          <kbd className={`rounded border ${colorTokens.border.default} ${colorTokens.surface.base} px-1.5 py-0.5 text-xs font-medium ${colorTokens.text.subtle}`}>
            ⌘K
          </kbd>
        </button>

        {/* Quick Create */}
        <QuickCreateButton />
      </div>

      {/* Right side actions */}
      <div className="flex items-center gap-2">
        {/* Active scope (company + location) — kept visually distinct and
            visible at ALL breakpoints so the user always knows which company /
            location inventory operations will hit. */}
        <div
          className={`flex items-center gap-1.5 rounded-lg border ${borderColors.light} ${colors.neutral[50]} p-1`}
          aria-label={t('common:scope.activeScope', { defaultValue: 'Active scope' })}
        >
          {/* Company selector for multi-company users */}
          <CompanySelector />

          {/* Location selector for multi-location companies */}
          <ViewScopePicker />
        </div>

        {/* Language selector */}
        <div className="relative" ref={langMenuRef}>
          <button
            type="button"
            onClick={() => { setIsLangMenuOpen(!isLangMenuOpen) }}
            className={`flex items-center gap-1 rounded-lg p-2 ${colorTokens.text.subtle} ${colorTokens.variants.hoverBgGray100}`}
            aria-label={t('common:selectLanguage')}
            aria-expanded={isLangMenuOpen}
            aria-haspopup="true"
          >
            <Globe className="h-5 w-5" />
            <span className="hidden text-sm font-medium sm:inline">{currentLang.code.toUpperCase()}</span>
          </button>

          {/* Language dropdown */}
          {isLangMenuOpen && (
            <div className={`absolute end-0 z-50 mt-2 w-40 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1 shadow-lg`}>
              {languages.map((lang) => (
                <button
                  key={lang.code}
                  type="button"
                  onClick={() => { handleLanguageChange(lang.code) }}
                  className={`flex w-full items-center justify-between px-4 py-2 text-sm ${colorTokens.variants.hoverBgGray100} ${
                    i18n.language === lang.code ? `${colorTokens.intent.primary.bgSubtle} ${colorTokens.intent.primary.textStrong}` : `${colorTokens.text.secondary}`
                  }`}
                >
                  <span>{lang.name}</span>
                  {i18n.language === lang.code && (
                    <span className={`${colorTokens.intent.primary.text}`}>✓</span>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>

        <ConnectionStatusIndicator />

        <NotificationBell />

        {/* User menu */}
        <div className="relative" ref={menuRef}>
          <button
            type="button"
            onClick={() => { setIsMenuOpen(!isMenuOpen) }}
            className={`flex items-center gap-2 rounded-lg p-2 ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray100}`}
            aria-label={t('auth:user.profile', { defaultValue: 'User menu' })}
            aria-expanded={isMenuOpen}
            aria-haspopup="true"
          >
            <User className="h-5 w-5" />
            <span className="text-sm font-medium">{user?.name ?? 'User'}</span>
          </button>

          {/* Dropdown menu */}
          {isMenuOpen && (
            <div className={`absolute end-0 z-50 mt-2 w-48 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1 shadow-lg`}>
              <button
                type="button"
                onClick={handleSettingsClick}
                className={`flex w-full items-center gap-2 px-4 py-2 text-sm ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray100}`}
              >
                <Settings className="h-4 w-4" />
                {t('auth:user.settings')}
              </button>
              <hr className={`my-1 ${colorTokens.border.subtle}`} />
              <button
                type="button"
                onClick={handleLogout}
                className={`flex w-full items-center gap-2 px-4 py-2 text-sm ${colorTokens.intent.danger.text} ${colorTokens.variants.hoverBgGray100}`}
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
