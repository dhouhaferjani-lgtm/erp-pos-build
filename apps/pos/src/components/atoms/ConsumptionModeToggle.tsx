import { useTranslation } from 'react-i18next';
import { UtensilsCrossed, Package } from 'lucide-react';

export type ConsumptionMode = 'SUR_PLACE' | 'A_EMPORTER';

export interface ConsumptionModeToggleProps {
  value: ConsumptionMode;
  onChange: (mode: ConsumptionMode) => void;
  className?: string;
}

export function ConsumptionModeToggle({ value, onChange, className }: ConsumptionModeToggleProps) {
  const { t } = useTranslation();

  return (
    <div className={`inline-flex rounded-lg bg-gray-100 p-1 ${className ?? ''}`}>
      <button
        type="button"
        onClick={() => onChange('SUR_PLACE')}
        className={`flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
          value === 'SUR_PLACE'
            ? 'bg-white text-gray-900 shadow-sm'
            : 'text-gray-600 hover:text-gray-900'
        }`}
      >
        <UtensilsCrossed className="h-4 w-4" />
        {t('consumptionMode.dineIn')}
      </button>
      <button
        type="button"
        onClick={() => onChange('A_EMPORTER')}
        className={`flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
          value === 'A_EMPORTER'
            ? 'bg-white text-gray-900 shadow-sm'
            : 'text-gray-600 hover:text-gray-900'
        }`}
      >
        <Package className="h-4 w-4" />
        {t('consumptionMode.takeout')}
      </button>
    </div>
  );
}
