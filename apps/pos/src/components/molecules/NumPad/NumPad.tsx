import { useCallback } from 'react';
import { Delete } from 'lucide-react';
import { cn } from '@/lib/utils';

interface NumPadProps {
  value: string;
  onChange: (value: string) => void;
  /** Allow decimal input. Defaults to true. */
  allowDecimal?: boolean;
  className?: string;
}

const KEYS = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

export function NumPad({
  value,
  onChange,
  allowDecimal = true,
  className,
}: NumPadProps) {

  const handleKey = useCallback(
    (key: string) => {
      if (key === 'backspace') {
        onChange(value.slice(0, -1));
      } else if (key === 'clear') {
        onChange('');
      } else if (key === '.') {
        if (allowDecimal && !value.includes('.')) {
          onChange(value === '' ? '0.' : value + '.');
        }
      } else {
        onChange(value + key);
      }
    },
    [value, onChange, allowDecimal],
  );

  const btnClass =
    'flex items-center justify-center rounded-lg bg-gray-100 text-lg font-semibold text-gray-900 transition-colors active:bg-gray-200 min-h-[48px]';

  return (
    <div className={cn('grid grid-cols-4 gap-1.5', className)}>
      {KEYS.map((key) => (
        <button key={key} type="button" onClick={() => handleKey(key)} className={btnClass}>
          {key}
        </button>
      ))}
      {/* Bottom row */}
      {allowDecimal ? (
        <button
          type="button"
          onClick={() => handleKey('.')}
          disabled={value.includes('.')}
          className={cn(btnClass, 'disabled:opacity-30')}
        >
          .
        </button>
      ) : (
        <button
          type="button"
          onClick={() => handleKey('clear')}
          className={cn(btnClass, 'text-red-600')}
        >
          C
        </button>
      )}
      <button type="button" onClick={() => handleKey('0')} className={btnClass}>
        0
      </button>
      <button type="button" onClick={() => handleKey('00')} className={btnClass}>
        00
      </button>
      <button
        type="button"
        onClick={() => handleKey('backspace')}
        className="flex items-center justify-center rounded-lg bg-red-50 text-red-600 transition-colors active:bg-red-100 min-h-[48px]"
      >
        <Delete className="h-5 w-5" />
      </button>
    </div>
  );
}
