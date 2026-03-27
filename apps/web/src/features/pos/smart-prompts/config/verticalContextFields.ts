export interface ContextFieldConfig {
  key: string
  labelKey: string
  options: { value: string; labelKey: string }[]
}

export const verticalContextFields: Record<string, ContextFieldConfig[]> = {
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
}
