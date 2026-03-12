export interface Modifier {
  id: string;
  name: string;
  price_adjustment: string;
  is_default: boolean;
  is_active: boolean;
  position: number;
}

export interface ModifierGroup {
  id: string;
  name: string;
  selection_type: 'single' | 'multiple';
  min_selections: number;
  max_selections: number;
  is_required: boolean;
  position: number;
  modifiers: Modifier[];
}
