import { cn } from '@/lib/utils'
import type { Tone } from '@/lib/data'

// Pim's face — a warm green squircle with a serif "p". Simple, human, ownable.
export function PimAvatar({ size = 36, className }: { size?: number; className?: string }) {
  return (
    <div
      className={cn(
        'relative flex shrink-0 items-center justify-center rounded-[32%] bg-gradient-to-br from-brand to-brand-deep shadow-sm',
        className,
      )}
      style={{ width: size, height: size }}
    >
      <span
        className="font-display italic font-medium text-white"
        style={{ fontSize: size * 0.52, lineHeight: 1, transform: 'translateY(-4%)' }}
      >
        p
      </span>
    </div>
  )
}

const toneText: Record<Tone, string> = {
  brand: 'text-brand',
  honey: 'text-honey',
  clay: 'text-clay',
  sky: 'text-sky',
  iris: 'text-iris',
  neutral: 'text-warmgrey',
}

const toneSoftBg: Record<Tone, string> = {
  brand: 'bg-brand-soft',
  honey: 'bg-honey-soft',
  clay: 'bg-clay-soft',
  sky: 'bg-sky-soft',
  iris: 'bg-iris-soft',
  neutral: 'bg-secondary',
}

export function ToneDot({ tone, pulse = false }: { tone: Tone; pulse?: boolean }) {
  return (
    <span className={cn('relative inline-block h-2 w-2 rounded-full', toneText[tone], pulse && 'pulse-dot')}>
      <span className="absolute inset-0 rounded-full bg-current" />
    </span>
  )
}

export function Chip({
  tone = 'neutral',
  children,
  className,
}: {
  tone?: Tone
  children: React.ReactNode
  className?: string
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
        toneSoftBg[tone],
        toneText[tone],
        className,
      )}
    >
      {children}
    </span>
  )
}

export function ToneIcon({
  tone,
  children,
  size = 40,
}: {
  tone: Tone
  children: React.ReactNode
  size?: number
}) {
  return (
    <div
      className={cn('flex shrink-0 items-center justify-center rounded-xl', toneSoftBg[tone], toneText[tone])}
      style={{ width: size, height: size }}
    >
      {children}
    </div>
  )
}

// A small horizontal stock bar that reads at a glance, no numbers needed.
export function StockBar({ value, max = 100, tone = 'brand' }: { value: number; max?: number; tone?: Tone }) {
  const pct = Math.min(100, Math.round((value / max) * 100))
  const bar =
    pct <= 15 ? 'bg-clay' : pct <= 35 ? 'bg-honey' : tone === 'brand' ? 'bg-brand' : 'bg-brand'
  return (
    <div className="h-1.5 w-full overflow-hidden rounded-full bg-secondary">
      <div className={cn('h-full rounded-full transition-all duration-500', bar)} style={{ width: `${Math.max(4, pct)}%` }} />
    </div>
  )
}
