import axios from 'axios'

export function countryDefaultsErrorStatus(error: unknown): number | undefined {
  return axios.isAxiosError(error) ? error.response?.status : undefined
}

export function countryDefaultsErrorKey(error: unknown): string {
  switch (countryDefaultsErrorStatus(error)) {
    case 403:
      return 'errors.forbidden'
    case 409:
      return 'errors.conflict'
    case 422:
      return 'errors.validation'
    case 500:
      return 'errors.server'
    default:
      return 'errors.generic'
  }
}
