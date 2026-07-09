import { useTranslation } from 'react-i18next';
import type { ContextFieldConfig } from '@/stores/smartPromptsStore';

interface ContextQuestionProps {
  field: ContextFieldConfig;
  value: string | null;
  onChange: (value: string) => void;
}

export function ContextQuestion({ field, value, onChange }: ContextQuestionProps) {
  const { t } = useTranslation();

  return (
    <div className="mb-2">
      <div className="mb-1.5 text-[11px] text-amber-400">{t(field.labelKey)}</div>
      <div className="flex flex-wrap gap-1.5">
        {field.options.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`rounded-pill border px-2.5 py-1 text-[11px] transition-colors ${
              value === option.value
                ? 'border-indigo-500/40 bg-indigo-500/20 text-indigo-400'
                : 'border-white/10 bg-white/5 text-gray-300 hover:bg-white/10'
            }`}
            onClick={() => onChange(option.value)}
            aria-pressed={value === option.value}
          >
            {t(option.labelKey)}
          </button>
        ))}
      </div>
    </div>
  );
}
