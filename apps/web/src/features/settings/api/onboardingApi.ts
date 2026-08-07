import { apiGet } from '../../../lib/api'

export interface OnboardingItem {
  step: string
  label: string
  completed: boolean
  required: boolean
  settings_path: string
  /**
   * True when the backend could not determine this step's state (its module's
   * check threw — e.g. a tenant whose migration lane is behind). The step is
   * reported `completed: false` so the page still renders; see BUG-005 / B1.
   */
  degraded: boolean
}

export function fetchOnboardingStatus(): Promise<OnboardingItem[]> {
  return apiGet<OnboardingItem[]>('/onboarding/status')
}
