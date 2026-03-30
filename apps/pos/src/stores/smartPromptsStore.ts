import { create } from 'zustand';
import { fetchRecommendations } from '@/api/smartPromptsApi';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useProductStore } from '@/stores/productStore';
import type { Recommendation } from '@/types/recommendations';

const DEBOUNCE_MS = 300;
const SUPPORTED_VERTICALS = ['parapharmacy'];

interface ContextFieldOption {
  value: string;
  labelKey: string;
}

export interface ContextFieldConfig {
  key: string;
  labelKey: string;
  options: ContextFieldOption[];
}

const VERTICAL_CONTEXT_FIELDS: Record<string, ContextFieldConfig[]> = {
  parapharmacy: [
    {
      key: 'skin_type',
      labelKey: 'smart-prompts:skin_type_question',
      options: [
        { value: 'normal', labelKey: 'smart-prompts:skin_type.normal' },
        { value: 'oily', labelKey: 'smart-prompts:skin_type.oily' },
        { value: 'dry', labelKey: 'smart-prompts:skin_type.dry' },
        { value: 'combination', labelKey: 'smart-prompts:skin_type.combination' },
        { value: 'sensitive', labelKey: 'smart-prompts:skin_type.sensitive' },
      ],
    },
  ],
};

interface SmartPromptsState {
  recommendations: Recommendation[];
  isLoading: boolean;
  skinType: string | null;
  contextFields: ContextFieldConfig[];
  lastProductIds: string;
}

interface SmartPromptsActions {
  fetchForCart: (productIds: string[]) => void;
  setSkinType: (value: string | null) => void;
  clear: () => void;
}

type SmartPromptsStore = SmartPromptsState & SmartPromptsActions;

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

export const useSmartPromptsStore = create<SmartPromptsStore>()((set, get) => ({
  recommendations: [],
  isLoading: false,
  skinType: null,
  contextFields: [],
  lastProductIds: '',

  fetchForCart: (productIds: string[]) => {
    const config = useProductStore.getState().companyConfig;
    const vertical = config?.vertical ?? '';
    const enabled = config?.smart_prompts_enabled === true;

    if (!SUPPORTED_VERTICALS.includes(vertical) || !enabled) {
      set({ recommendations: [], contextFields: [] });
      return;
    }

    const { isOnline } = useConnectivityStore.getState();
    if (!isOnline) {
      return;
    }

    const contextFields = VERTICAL_CONTEXT_FIELDS[vertical] ?? [];
    set({ contextFields });

    const sortedIds = [...productIds].sort().join(',');
    if (sortedIds === get().lastProductIds && get().recommendations.length > 0) {
      return;
    }

    if (debounceTimer) clearTimeout(debounceTimer);

    if (productIds.length === 0) {
      set({ recommendations: [], lastProductIds: '' });
      return;
    }

    set({ isLoading: true });

    debounceTimer = setTimeout(() => {
      const { skinType } = get();

      void fetchRecommendations({
        product_ids: productIds,
        context: 'cart',
        limit: 5,
        skin_type: skinType,
      })
        .then((response) => {
          set({
            recommendations: response.recommendations,
            isLoading: false,
            lastProductIds: sortedIds,
          });
        })
        .catch(() => {
          set({ isLoading: false });
        });
    }, DEBOUNCE_MS);
  },

  setSkinType: (value: string | null) => {
    set({ skinType: value, lastProductIds: '' });
  },

  clear: () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    set({ recommendations: [], isLoading: false, skinType: null, lastProductIds: '' });
  },
}));
