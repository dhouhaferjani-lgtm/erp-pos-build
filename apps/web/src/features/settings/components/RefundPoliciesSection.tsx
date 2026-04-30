import { useState } from 'react'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { tokens, textColors } from '@/lib/designTokens'

interface RefundPoliciesSectionProps {
  title: string
  defaultOpen?: boolean
  children: React.ReactNode
}

export function RefundPoliciesSection({
  title,
  defaultOpen = true,
  children,
}: RefundPoliciesSectionProps) {
  const [isOpen, setIsOpen] = useState(defaultOpen)

  return (
    <div className={`${tokens.card.base} overflow-hidden`}>
      <button
        type="button"
        onClick={() => { setIsOpen((prev) => !prev) }}
        className="flex w-full items-center justify-between text-left"
        aria-expanded={isOpen}
      >
        <h2 className={`text-base font-semibold ${textColors.primary}`}>
          {title}
        </h2>
        {isOpen ? (
          <ChevronUp className={`h-4 w-4 shrink-0 ${textColors.disabled}`} />
        ) : (
          <ChevronDown className={`h-4 w-4 shrink-0 ${textColors.disabled}`} />
        )}
      </button>
      {isOpen && <div className="mt-4 space-y-4">{children}</div>}
    </div>
  )
}
