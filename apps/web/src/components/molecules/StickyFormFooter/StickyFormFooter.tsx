import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
interface StickyFormFooterProps {
  children: React.ReactNode
}

export function StickyFormFooter({ children }: StickyFormFooterProps) {
  return (
    <div className="mt-auto sticky bottom-0 -mx-4 sm:-mx-6 pt-6">
      <div className={`border-t ${colorTokens.border.subtle} ${colorTokens.variants.bgWhiteAlpha95} backdrop-blur-sm px-4 sm:px-6 py-3`}>
        <div className="flex items-center justify-end gap-4">
          {children}
        </div>
      </div>
    </div>
  )
}
