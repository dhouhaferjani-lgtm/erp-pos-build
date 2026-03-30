import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SmartPromptCard } from '@/components/atoms/SmartPromptCard';
import { ContextQuestion } from '@/components/atoms/ContextQuestion';
import type { Recommendation } from '@/types/recommendations';
import type { ContextFieldConfig } from '@/stores/smartPromptsStore';

const AUTO_COLLAPSE_MS = 15_000;

interface ToastSmartPromptsProps {
  recommendations: Recommendation[];
  contextFields: ContextFieldConfig[];
  skinType: string | null;
  onSkinTypeChange: (value: string) => void;
  onAdd: (productId: string) => void;
  isLoading: boolean;
}

export function ToastSmartPrompts({
  recommendations,
  contextFields,
  skinType,
  onSkinTypeChange,
  onAdd,
  isLoading,
}: ToastSmartPromptsProps) {
  const { t } = useTranslation('smart-prompts');
  const [expanded, setExpanded] = useState(false);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (expanded) {
      timerRef.current = setTimeout(() => setExpanded(false), AUTO_COLLAPSE_MS);
    }
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [expanded]);

  if (recommendations.length === 0 && !isLoading) {
    return null;
  }

  return (
    <div className="absolute bottom-0 left-0 right-0 z-10">
      {!expanded && (
        <button
          type="button"
          className="flex w-full items-center gap-2 border-t border-indigo-500/20 bg-indigo-500/10 px-4 py-2.5 backdrop-blur-sm"
          onClick={() => setExpanded(true)}
        >
          <span className="text-xs text-indigo-400">✦</span>
          <span className="text-xs font-medium text-indigo-400">
            {t('toast_collapsed', { count: recommendations.length })}
          </span>
          <span className="ml-auto text-sm text-indigo-400">⌃</span>
        </button>
      )}

      {expanded && (
        <div className="border-t border-indigo-500/20 bg-gray-900/95 px-4 py-3 backdrop-blur-md">
          <div className="mb-2 flex items-center gap-1.5">
            <span className="text-xs text-indigo-400">✦</span>
            <span className="text-[11px] font-semibold uppercase tracking-wide text-indigo-400">
              {t('section_title')}
            </span>
            <button
              type="button"
              className="ml-auto text-[10px] text-gray-500 hover:text-gray-400"
              onClick={() => setExpanded(false)}
            >
              ✕
            </button>
          </div>

          {contextFields.map((field) => (
            <ContextQuestion
              key={field.key}
              field={field}
              value={field.key === 'skin_type' ? skinType : null}
              onChange={field.key === 'skin_type' ? onSkinTypeChange : () => {}}
            />
          ))}

          <div className="flex gap-2 overflow-x-auto pb-1">
            {recommendations.map((rec) => (
              <div key={rec.product_id} className="min-w-[160px]">
                <SmartPromptCard recommendation={rec} onAdd={onAdd} />
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
