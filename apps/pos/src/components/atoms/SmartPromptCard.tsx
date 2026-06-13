import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Recommendation } from '@/types/recommendations';

interface SmartPromptCardProps {
  recommendation: Recommendation;
  onAdd: (productId: string) => void;
}

export function SmartPromptCard({ recommendation, onAdd }: SmartPromptCardProps) {
  const { t } = useTranslation('smart-prompts');
  const [showInfo, setShowInfo] = useState(false);

  return (
    <div className="smart-prompt-card relative flex items-center gap-3 rounded-lg border border-indigo-500/10 bg-indigo-500/5 p-2">
      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-medium">{recommendation.product_name}</div>
        <div className="flex items-center gap-1.5">
          <span className="rounded bg-indigo-500/15 px-1.5 py-0.5 text-[10px] text-indigo-400">
            {t(`strategy.${recommendation.strategy}`)}
          </span>
          <span className="truncate text-xs text-indigo-400">{recommendation.reason}</span>
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <button
          type="button"
          className="flex h-7 w-7 items-center justify-center rounded-full bg-white/5 text-xs text-gray-400 hover:bg-white/10"
          onClick={(e) => {
            e.stopPropagation();
            setShowInfo(!showInfo);
          }}
          aria-label={t('info_label')}
        >
          ℹ
        </button>
        <button
          type="button"
          className="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-500/20 text-sm text-indigo-400 hover:bg-indigo-500/30"
          onClick={() => onAdd(recommendation.product_id)}
          aria-label={t('add_to_cart')}
        >
          +
        </button>
      </div>
      {showInfo && (
        <div className="absolute right-0 top-full z-10 mt-1 w-64 rounded-lg border border-indigo-500/20 bg-gray-800 p-3 text-xs shadow-lg">
          <div className="mb-1 font-semibold">{t('info_title')}</div>
          <div className="mb-2 text-gray-400">{recommendation.reason}</div>
          <div className="text-gray-500">
            {t('info_source', { source: recommendation.strategy })} · {t('info_score', { score: recommendation.score })}
          </div>
        </div>
      )}
    </div>
  );
}
