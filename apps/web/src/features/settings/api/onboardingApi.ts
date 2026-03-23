import { apiGet } from '../../../lib/api'

export interface OnboardingItem {
  step: string
  label: string
  completed: boolean
  required: boolean
  settings_path: string
}

export function fetchOnboardingStatus(): Promise<OnboardingItem[]> {
  return apiGet<OnboardingItem[]>('/onboarding/status')
}
