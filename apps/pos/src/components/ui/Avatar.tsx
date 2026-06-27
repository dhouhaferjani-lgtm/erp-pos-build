import { cn } from '@/lib/utils';
import { initialsFromName } from './ProductThumb';

/**
 * Avatar — initials disc for a customer or operator. No photos in the POS;
 * initials over a tinted disc. `tone="accent"` highlights the active operator.
 */
export type AvatarTone = 'neutral' | 'accent';

const TONE: Record<AvatarTone, string> = {
  neutral: 'bg-surface-sunken text-ink-muted',
  accent: 'bg-accent-tint text-accent-strong',
};

export interface AvatarProps {
  name: string;
  /** Disc diameter in px. Default 40 (≥ icon-target). */
  size?: number;
  tone?: AvatarTone;
  className?: string;
}

export function Avatar({ name, size = 40, tone = 'neutral', className }: AvatarProps) {
  return (
    <span
      aria-hidden
      style={{ width: size, height: size, fontSize: Math.round(size * 0.4) }}
      className={cn(
        'inline-flex shrink-0 select-none items-center justify-center rounded-full font-display font-bold',
        TONE[tone],
        className,
      )}
    >
      {initialsFromName(name)}
    </span>
  );
}
