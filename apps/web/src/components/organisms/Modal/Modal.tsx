import { type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { tokens } from '../../../lib/designTokens'
import { cn } from '../../../lib/utils'

export interface ModalProps {
  /**
   * Controls modal visibility
   */
  isOpen: boolean

  /**
   * Callback when modal should close
   */
  onClose: () => void

  /**
   * Modal title (displayed in header)
   */
  title?: string

  /**
   * Modal content
   */
  children: ReactNode

  /**
   * Additional CSS classes for the modal container
   */
  className?: string

  /**
   * Size variant
   */
  size?: 'sm' | 'md' | 'lg' | 'xl'
}

export interface ModalHeaderProps {
  /**
   * Header title
   */
  title?: string

  /**
   * Close callback
   */
  onClose: () => void

  /**
   * Custom header content (overrides title)
   */
  children?: ReactNode

  /**
   * Additional CSS classes
   */
  className?: string
}

export interface ModalContentProps {
  /**
   * Content to display
   */
  children: ReactNode

  /**
   * Additional CSS classes
   */
  className?: string
}

export interface ModalFooterProps {
  /**
   * Footer content (typically buttons)
   */
  children: ReactNode

  /**
   * Additional CSS classes
   */
  className?: string
}

const sizeClasses = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
}

/**
 * Modal - Reusable modal/dialog component
 *
 * A flexible modal component extracted from AddLocationModal pattern.
 * Supports composition with ModalHeader, ModalContent, and ModalFooter subcomponents.
 * Uses design tokens for consistent styling.
 *
 * @example
 * ```tsx
 * // Simple usage
 * <Modal isOpen={isOpen} onClose={onClose} title="Add Partner">
 *   <form>
 *     <Input placeholder="Name" />
 *     <div className="mt-4 flex gap-2">
 *       <Button onClick={onClose}>Cancel</Button>
 *       <Button type="submit">Save</Button>
 *     </div>
 *   </form>
 * </Modal>
 *
 * // Composition usage with subcomponents
 * <Modal isOpen={isOpen} onClose={onClose}>
 *   <ModalHeader title="Add Partner" onClose={onClose} />
 *   <ModalContent>
 *     <form>
 *       <Input placeholder="Name" />
 *     </form>
 *   </ModalContent>
 *   <ModalFooter>
 *     <Button onClick={onClose}>Cancel</Button>
 *     <Button type="submit">Save</Button>
 *   </ModalFooter>
 * </Modal>
 * ```
 */
export function Modal({
  isOpen,
  onClose,
  title,
  children,
  className,
  size = 'md',
}: ModalProps) {
  if (!isOpen) return null

  return createPortal(
    <div className={tokens.modal.backdrop}>
      <div className={cn(tokens.modal.container, sizeClasses[size], className)}>
        {/* Auto-render header if title provided */}
        {title && <ModalHeader title={title} onClose={onClose} />}

        {children}
      </div>
    </div>,
    document.body,
  )
}

/**
 * ModalHeader - Header section with title and close button
 */
export function ModalHeader({
  title,
  onClose,
  children,
  className,
}: ModalHeaderProps) {
  return (
    <div className={cn(tokens.modal.header, className)}>
      {children || <h2 className={tokens.modal.title}>{title}</h2>}
      <button
        type="button"
        onClick={onClose}
        className={tokens.modal.closeButton}
        aria-label="Close"
      >
        <X className="h-5 w-5" />
      </button>
    </div>
  )
}

/**
 * ModalContent - Content section (wrapper for spacing)
 */
export function ModalContent({ children, className }: ModalContentProps) {
  return <div className={cn('space-y-4', className)}>{children}</div>
}

/**
 * ModalFooter - Footer section with actions (typically buttons)
 */
export function ModalFooter({ children, className }: ModalFooterProps) {
  return <div className={cn(tokens.modal.footer, className)}>{children}</div>
}

// Export all subcomponents
Modal.Header = ModalHeader
Modal.Content = ModalContent
Modal.Footer = ModalFooter
