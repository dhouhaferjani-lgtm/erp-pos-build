import { useState, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Download } from 'lucide-react'
import { Button } from '@/components/atoms/Button/Button'
import { useVatExportFormats } from '../hooks/useVatReport'
import { useVatExport } from '../hooks/useVatExport'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface VatExportMenuProps {
  periodId: string
}

export function VatExportMenu({ periodId }: VatExportMenuProps) {
  const { t } = useTranslation('finance')
  const [isOpen, setIsOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

  const { data: formats, isLoading: formatsLoading } = useVatExportFormats(periodId)
  const exportMutation = useVatExport()

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [])

  const handleExport = (format: string) => {
    exportMutation.mutate({ periodId, format })
    setIsOpen(false)
  }

  return (
    <div className="relative inline-block" ref={menuRef}>
      <Button
        variant="secondary"
        size="sm"
        onClick={() => { setIsOpen(!isOpen); }}
        disabled={formatsLoading || exportMutation.isPending}
      >
        <Download className="me-1.5 h-4 w-4" />
        {t('finance:vatReporting.actions.export')}
      </Button>

      {isOpen && formats && formats.length > 0 && (
        <div className={`absolute end-0 z-10 mt-1 w-48 rounded-md border ${colorTokens.border.subtle} bg-white py-1 shadow-lg`}>
          {formats.map((format) => (
            <button
              key={format.format}
              type="button"
              className={`block w-full px-4 py-2 text-start text-sm ${colorTokens.text.secondary} ${colorTokens.variants.hoverBgGray100}`}
              onClick={() => { handleExport(format.format); }}
              disabled={exportMutation.isPending}
            >
              {format.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
