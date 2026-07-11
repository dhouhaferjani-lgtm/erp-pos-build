import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown } from 'lucide-react'
import { Button } from '@/components/atoms'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

export interface SaveSplitButtonProps {
  onPrimarySave: () => void
  onSaveAndNew?: () => void
  onSaveAndClose?: () => void
  isPending?: boolean
  disabled?: boolean
  primaryLabel?: string
  form?: string
  primaryType?: 'submit' | 'button'
}

export function SaveSplitButton({
  onPrimarySave, onSaveAndNew, onSaveAndClose,
  isPending = false, disabled = false, primaryLabel, form, primaryType = 'submit',
}: SaveSplitButtonProps) {
  const { t } = useTranslation('common')
  const [open, setOpen] = useState(false)
  const menuId = useId()
  const triggerId = useId()
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLUListElement>(null)

  const items: Array<{ key: string; label: string; onClick: () => void }> = []
  if (onSaveAndNew) items.push({ key: 'new', label: t('actions.saveAndNew'), onClick: onSaveAndNew })
  if (onSaveAndClose) items.push({ key: 'close', label: t('actions.saveAndClose'), onClick: onSaveAndClose })
  const hasMenu = items.length > 0

  const close = useCallback((focusTrigger = true) => {
    setOpen(false)
    if (focusTrigger) triggerRef.current?.focus()
  }, [])

  useEffect(() => {
    if (!open) return
    const onDocClick = (e: MouseEvent) => {
      if (!menuRef.current?.contains(e.target as Node) && !triggerRef.current?.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onDocClick)
    return () => { document.removeEventListener('mousedown', onDocClick) }
  }, [open])

  useEffect(() => {
    if (open) (menuRef.current?.querySelector('[role="menuitem"]') as HTMLElement | null)?.focus()
  }, [open])

  const onMenuKeyDown = (e: React.KeyboardEvent<HTMLUListElement>) => {
    const nodes = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
    const idx = nodes.findIndex((n) => n === document.activeElement)
    if (e.key === 'Escape') { e.preventDefault(); close() }
    else if (e.key === 'ArrowDown') { e.preventDefault(); nodes[Math.min(idx + 1, nodes.length - 1)]?.focus() }
    else if (e.key === 'ArrowUp') { e.preventDefault(); nodes[Math.max(idx - 1, 0)]?.focus() }
    else if (e.key === 'Home') { e.preventDefault(); nodes[0]?.focus() }
    else if (e.key === 'End') { e.preventDefault(); nodes[nodes.length - 1]?.focus() }
    else if (e.key === 'Tab') { setOpen(false) }
  }

  return (
    <div className="relative inline-flex items-center">
      <Button
        type={primaryType}
        {...(form ? { form } : {})}
        variant="primary"
        disabled={disabled || isPending}
        onClick={primaryType === 'button' ? onPrimarySave : undefined}
      >
        {isPending ? t('saving') : (primaryLabel ?? t('actions.save'))}
      </Button>
      {hasMenu && (
        <>
          <Button
            ref={triggerRef}
            id={triggerId}
            type="button"
            variant="primary"
            aria-haspopup="menu"
            aria-expanded={open}
            aria-controls={menuId}
            aria-label={t('actions.openSaveMenu')}
            disabled={disabled || isPending}
            onClick={() => { setOpen((v) => !v) }}
            className="ml-px px-2"
          >
            <ChevronDown className="h-4 w-4" aria-hidden="true" />
          </Button>
          {open && (
            <ul
              ref={menuRef}
              id={menuId}
              role="menu"
              aria-labelledby={triggerId}
              onKeyDown={onMenuKeyDown}
              className={`absolute right-0 top-full z-20 mt-1 min-w-[12rem] rounded-md border ${colorTokens.variants.borderNeutral200} ${colorTokens.surface.base} py-1 shadow-lg`}
            >
              {items.map((item) => (
                <li key={item.key}>
                  <button
                    type="button"
                    role="menuitem"
                    onClick={() => { close(false); item.onClick() }}
                    className={`block w-full px-4 py-2 text-left text-sm ${colorTokens.variants.hoverBgNeutral50}`}
                  >
                    {item.label}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  )
}
