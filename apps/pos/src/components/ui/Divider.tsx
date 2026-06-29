import { cn } from '@/lib/utils';

/**
 * Divider — hairline separator. `orientation="vertical"` is the header
 * group-divider between clusters (terminal | shop | shift | operator); horizontal
 * separates stacked sections.
 */
export interface DividerProps {
  orientation?: 'horizontal' | 'vertical';
  className?: string;
}

export function Divider({ orientation = 'horizontal', className }: DividerProps) {
  return (
    <span
      role="separator"
      aria-orientation={orientation}
      className={cn(
        'bg-border-subtle',
        orientation === 'vertical' ? 'mx-1 h-6 w-px self-center' : 'my-1 h-px w-full',
        className,
      )}
    />
  );
}
