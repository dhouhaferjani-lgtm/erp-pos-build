import { useEffect, useLayoutEffect, useRef, useState, type ReactElement, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { MoreVertical } from 'lucide-react'

export interface ActionMenuItem {
  /** Unique key. */
  key: string
  /** Visible label. */
  label: string
  /** Optional icon (lucide icon, sized 16px). */
  icon?: ReactNode
  /** Click handler — called before the menu closes. */
  onClick: () => void
  /** Apply destructive (red) styling. */
  destructive?: boolean
  /** Item is rendered disabled and not clickable. */
  disabled?: boolean
  /** Item is omitted from the rendered menu. */
  hidden?: boolean
}

export interface ActionMenuProps {
  /** Menu items to render in order. */
  items: ActionMenuItem[]
  /** Accessible label for the trigger button. */
  ariaLabel: string
  /** Width in tailwind units (default: w-48). */
  className?: string
  /** When true, the trigger renders a spinner and is disabled. */
  isLoading?: boolean
}

interface MenuPosition {
  top: number
  left: number
  /** Width in pixels — pinned to MENU_WIDTH for consistency. */
  width: number
}

const MENU_WIDTH = 192 // matches w-48
const MENU_VERTICAL_GAP = 4

/**
 * A right-aligned action menu that renders into a portal so it cannot be
 * clipped by an overflow-hidden ancestor or the bottom of a table.
 *
 * Position is computed from the trigger button's bounding rect and flipped
 * upward when there isn't enough room below the trigger inside the viewport.
 */
export function ActionMenu({
  items,
  ariaLabel,
  className,
  isLoading = false,
}: ActionMenuProps): ReactElement | null {
  const triggerRef = useRef<HTMLButtonElement | null>(null)
  const menuRef = useRef<HTMLDivElement | null>(null)
  const [isOpen, setIsOpen] = useState(false)
  const [position, setPosition] = useState<MenuPosition | null>(null)

  const visibleItems = items.filter((item) => item.hidden !== true)

  const computePosition = (): MenuPosition | null => {
    const trigger = triggerRef.current
    if (trigger === null) return null

    const rect = trigger.getBoundingClientRect()
    const menuHeight = menuRef.current?.offsetHeight ?? 0
    const viewportHeight = window.innerHeight
    const viewportWidth = window.innerWidth

    // Right-align to the trigger
    let left = rect.right - MENU_WIDTH
    if (left < 8) left = 8
    if (left + MENU_WIDTH > viewportWidth - 8) {
      left = viewportWidth - MENU_WIDTH - 8
    }

    // Drop below by default; flip up if it would clip past the viewport
    const spaceBelow = viewportHeight - rect.bottom
    const spaceAbove = rect.top
    const wantsUp = menuHeight > 0 && spaceBelow < menuHeight + MENU_VERTICAL_GAP && spaceAbove > spaceBelow

    const top = wantsUp
      ? Math.max(8, rect.top - menuHeight - MENU_VERTICAL_GAP)
      : Math.min(viewportHeight - 8, rect.bottom + MENU_VERTICAL_GAP)

    return { top, left, width: MENU_WIDTH }
  }

  useLayoutEffect(() => {
    if (!isOpen) {
      setPosition(null)
      return
    }
    setPosition(computePosition())

    // After paint we know menu height — recompute once for accurate flip
    const id = window.requestAnimationFrame(() => {
      setPosition(computePosition())
    })
    return () => { window.cancelAnimationFrame(id) }
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return

    const handleScrollOrResize = () => {
      setPosition(computePosition())
    }
    const handleClickOutside = (event: MouseEvent) => {
      const target = event.target as Node | null
      if (
        target !== null &&
        triggerRef.current !== null &&
        !triggerRef.current.contains(target) &&
        menuRef.current !== null &&
        !menuRef.current.contains(target)
      ) {
        setIsOpen(false)
      }
    }
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsOpen(false)
    }

    window.addEventListener('scroll', handleScrollOrResize, true)
    window.addEventListener('resize', handleScrollOrResize)
    window.addEventListener('mousedown', handleClickOutside)
    window.addEventListener('keydown', handleKeyDown)

    return () => {
      window.removeEventListener('scroll', handleScrollOrResize, true)
      window.removeEventListener('resize', handleScrollOrResize)
      window.removeEventListener('mousedown', handleClickOutside)
      window.removeEventListener('keydown', handleKeyDown)
    }
  }, [isOpen])

  if (visibleItems.length === 0) return null

  const handleItemClick = (item: ActionMenuItem) => {
    if (item.disabled === true) return
    setIsOpen(false)
    item.onClick()
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        aria-label={ariaLabel}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        disabled={isLoading}
        onClick={() => { setIsOpen((prev) => !prev) }}
        className="rounded p-1 text-gray-400 transition-colors hover:text-gray-600 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {isLoading ? (
          <span className="block h-5 w-5 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
        ) : (
          <MoreVertical className="h-5 w-5" />
        )}
      </button>

      {isOpen && createPortal(
        <div
          ref={menuRef}
          role="menu"
          className={`fixed z-50 rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 ${className ?? ''}`}
          style={{
            top: position?.top ?? -9999,
            left: position?.left ?? -9999,
            width: position?.width ?? MENU_WIDTH,
            visibility: position === null ? 'hidden' : 'visible',
          }}
        >
          {visibleItems.map((item) => (
            <button
              key={item.key}
              type="button"
              role="menuitem"
              disabled={item.disabled === true}
              onClick={() => { handleItemClick(item) }}
              className={`flex w-full items-center gap-2 px-4 py-2 text-start text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
                item.destructive === true
                  ? 'text-red-600 hover:bg-red-50'
                  : 'text-gray-700 hover:bg-gray-100'
              }`}
            >
              {item.icon}
              <span>{item.label}</span>
            </button>
          ))}
        </div>,
        document.body,
      )}
    </>
  )
}
