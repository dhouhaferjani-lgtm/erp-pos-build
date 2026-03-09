interface PinPadProps {
  value: string;
  maxLength: number;
  onChange: (value: string) => void;
  onSubmit: () => void;
  disabled?: boolean;
}

export function PinPad({ value, maxLength, onChange, onSubmit, disabled }: PinPadProps) {
  function handleDigit(digit: string) {
    if (disabled) return;
    const next = value + digit;
    if (next.length > maxLength) return;
    onChange(next);
  }

  function handleBackspace() {
    if (disabled) return;
    onChange(value.slice(0, -1));
  }

  function handleClear() {
    if (disabled) return;
    onChange('');
  }

  const buttons = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
    ['clear', '0', 'back'],
  ];

  return (
    <div className="space-y-3">
      <div className="grid grid-cols-3 gap-3">
        {buttons.map((row) =>
          row.map((key) => {
            if (key === 'clear') {
              return (
                <button
                  key={key}
                  type="button"
                  onClick={handleClear}
                  disabled={disabled}
                  className="flex h-16 items-center justify-center rounded-lg bg-gray-200 text-sm font-medium text-gray-700 hover:bg-gray-300 active:bg-gray-400 disabled:opacity-50"
                >
                  Clear
                </button>
              );
            }
            if (key === 'back') {
              return (
                <button
                  key={key}
                  type="button"
                  onClick={handleBackspace}
                  disabled={disabled}
                  className="flex h-16 items-center justify-center rounded-lg bg-gray-200 text-sm font-medium text-gray-700 hover:bg-gray-300 active:bg-gray-400 disabled:opacity-50"
                >
                  &#9003;
                </button>
              );
            }
            return (
              <button
                key={key}
                type="button"
                onClick={() => handleDigit(key)}
                disabled={disabled}
                className="flex h-16 items-center justify-center rounded-lg bg-white text-2xl font-semibold text-gray-900 shadow-sm border border-gray-200 hover:bg-gray-50 active:bg-gray-100 disabled:opacity-50"
              >
                {key}
              </button>
            );
          })
        )}
      </div>
      <button
        type="button"
        onClick={onSubmit}
        disabled={disabled || value.length < 4}
        className="flex h-14 w-full items-center justify-center rounded-lg bg-blue-600 text-lg font-semibold text-white hover:bg-blue-700 active:bg-blue-800 disabled:opacity-50"
      >
        {disabled ? 'Verifying...' : 'Enter'}
      </button>
    </div>
  );
}
