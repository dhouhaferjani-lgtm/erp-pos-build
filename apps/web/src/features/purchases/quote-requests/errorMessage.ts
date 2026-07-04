interface ApiErrorLike {
  response?: {
    data?: {
      error?: {
        message?: unknown
      }
    }
  }
}

export function mutationErrorMessage(error: unknown, fallback: string): string {
  const message = (error as ApiErrorLike).response?.data?.error?.message
  return typeof message === 'string' && message.trim() !== '' ? message : fallback
}
