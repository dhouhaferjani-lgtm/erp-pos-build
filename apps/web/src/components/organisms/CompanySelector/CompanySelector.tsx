import { useState, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Building2, ChevronDown, Check, Plus } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useCompany } from '../../../hooks/useCompany'
import { useQueryClient } from '@tanstack/react-query'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * CompanySelector allows users to switch between companies they have access to.
 *
 * Always renders to allow adding new companies.
 * Invalidates all queries when company is switched to refetch data.
 */
export function CompanySelector() {
  const { t } = useTranslation('common')
  const { currentCompany, companies, hasMultipleCompanies, switchCompany } = useCompany()
  const [isOpen, setIsOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  // Close menu when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [])

  const handleCompanyChange = (companyId: string) => {
    if (companyId !== currentCompany?.id) {
      switchCompany(companyId)
      // Invalidate all queries to refetch data for new company
      void queryClient.invalidateQueries()
    }
    setIsOpen(false)
  }

  const handleAddCompany = () => {
    setIsOpen(false)
    navigate('/company-onboarding')
  }

  return (
    <>
      <div className="relative" ref={menuRef}>
        <button
          type="button"
          onClick={() => { setIsOpen(!isOpen) }}
          className={`flex items-center gap-2 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} px-3 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray50}`}
          aria-label={t('company.select')}
          aria-expanded={isOpen}
          aria-haspopup="true"
        >
          <Building2 className={`h-4 w-4 ${colorTokens.text.subtle}`} />
          <span className="max-w-40 truncate font-semibold">{currentCompany?.name ?? t('company.select')}</span>
          <ChevronDown className={`h-4 w-4 ${colorTokens.text.disabled} transition-transform ${isOpen ? 'rotate-180' : ''}`} />
        </button>

        {/* Company dropdown */}
        {isOpen && (
          <div className={`absolute start-0 top-full z-50 mt-1 min-w-48 max-w-64 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} py-1 shadow-lg`}>
            {hasMultipleCompanies && (
              <>
                <div className={`px-3 py-2 text-xs font-semibold uppercase tracking-wider ${colorTokens.text.subtle}`}>
                  {t('company.switchCompany')}
                </div>
                <div className="max-h-64 overflow-y-auto">
                  {companies.map((company) => (
                    <button
                      key={company.id}
                      type="button"
                      onClick={() => { handleCompanyChange(company.id) }}
                      className={`flex w-full items-center justify-between px-3 py-2 text-sm ${colorTokens.variants.hoverBgGray50} ${
                        company.id === currentCompany?.id ? `${colorTokens.intent.primary.bgSubtle}` : ''
                      }`}
                    >
                      <div className="flex flex-col items-start">
                        <span className={`font-medium ${company.id === currentCompany?.id ? `${colorTokens.intent.primary.textStrong}` : `${colorTokens.text.primary}`}`}>
                          {company.name}
                        </span>
                        {company.legalName !== company.name && (
                          <span className={`text-xs ${colorTokens.text.subtle}`}>{company.legalName}</span>
                        )}
                      </div>
                      {company.id === currentCompany?.id && (
                        <Check className={`h-4 w-4 ${colorTokens.intent.primary.text}`} />
                      )}
                    </button>
                  ))}
                </div>
                <div className={`my-1 border-t ${colorTokens.border.hairline}`} />
              </>
            )}
            <button
              type="button"
              onClick={handleAddCompany}
              className={`flex w-full items-center gap-2 px-3 py-2 text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.variants.hoverBgBlue50}`}
            >
              <Plus className="h-4 w-4" />
              {t('company.addCompany')}
            </button>
          </div>
        )}
      </div>
    </>
  )
}
